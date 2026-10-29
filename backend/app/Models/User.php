<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Events\AccountApprovalDecided;
use App\Exceptions\KycIncompleteException;
use App\Models\Concerns\RecordsAuditLog;
use App\Services\AuthProvider;
use App\Services\VendorKycService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use RecordsAuditLog {
        auditExcluded as baseAuditExcluded;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
        'must_change_password',
        'language',
        'enable_notification',
    ];

    /**
     * Keeps the user's Firebase account (if any) matching this row — see
     * AuthProvider. Both are no-ops for a user without a firebase_uid, and
     * neither can fail the save: Firebase is a mirror, this row is the record.
     *
     * deleteWithTombstone() renames the email with saveQuietly(), so the
     * tombstone address never reaches Firebase; the delete itself removes
     * the Firebase account.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('email') && ! $user->isTombstoned()) {
                app(AuthProvider::class)->syncEmail($user);
            }
        });

        static::deleted(fn (User $user) => app(AuthProvider::class)->forget($user));
    }

    /**
     * 1:1 profiles. Exactly one is populated, matching the user's role.
     */
    public function salesman(): HasOne
    {
        return $this->hasOne(Salesman::class);
    }

    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class);
    }

    /** The vendor's KYC documents — backs the admin's Documents tab. */
    public function vendorDocuments(): HasManyThrough
    {
        return $this->hasManyThrough(VendorDocument::class, Vendor::class);
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /**
     * FCM registration tokens (BUILD_PLAN 7.2) — every device this
     * user is currently signed in on. `FcmChannel` sends to all of
     * them; there is no "primary device" concept.
     */
    /**
     * Portfolio uploads belonging to this user's vendor record.
     *
     * Exists so the admin panel can moderate a vendor's media from the
     * user's own page — Filament relation managers render as tabs there,
     * and a tab needs a relation hanging off the record the page is for.
     * Media is a MorphMany on Vendor, so the hop is spelled out by hand
     * with the morph type pinned; hasManyThrough does not know about
     * polymorphic second legs on its own.
     *
     * @return HasManyThrough<Media, Vendor, $this>
     */
    public function portfolioMedia(): HasManyThrough
    {
        return $this->hasManyThrough(
            Media::class,
            Vendor::class,
            'user_id',
            'mediable_id',
            'id',
            'id',
        )->where('media.mediable_type', (new Vendor)->getMorphClass());
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'firebase_uid',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_step',
    ];

    /**
     * Defaults applied to a new instance before it is saved.
     *
     * The column has the same default, but a database default is only
     * applied by the INSERT — it is not read back into the in-memory model.
     * Without this, the User returned straight out of User::create() has a
     * null approval_status, and anything reading it on that instance (the
     * API resource serialising the registration response, for one) sees
     * null rather than Pending.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'approval_status' => ApprovalStatus::Pending->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approval_status' => ApprovalStatus::class,
            'approval_decided_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'permissions' => 'array',
            'must_change_password' => 'boolean',
            'enable_notification' => 'boolean',
            // Two-step login (TwoFactorService). Encrypted at rest, and not
            // fillable — written only through forceFill there.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ];
    }

    /**
     * Deletes the account and frees its email address.
     *
     * SPEC section 4.10 requires account deletion for app store compliance,
     * and users.email carries a real unique index that a soft-deleted row
     * keeps occupying — so a plain delete() would bar that person from ever
     * signing up again. The address is rewritten to a tombstone first, and
     * the original is kept in original_email so a restore can put it back.
     *
     * All of it in one transaction: a half-applied delete would either leave
     * a live token against a deleted account or lose the original address.
     */
    public function deleteWithTombstone(): void
    {
        DB::transaction(function () {
            // Keep the real address before overwriting it, unless this row is
            // already tombstoned from an earlier cycle.
            if ($this->original_email === null) {
                $this->original_email = $this->email;
            }

            // id + timestamp makes this unique by construction, so the unique
            // index holds even if the same account is deleted, re-created and
            // deleted again. @deleted.local cannot receive mail, so no
            // notification can ever reach a tombstoned address by accident.
            $this->email = "deleted-{$this->id}-".now()->timestamp.'@deleted.local';

            // saveQuietly: the rename is bookkeeping, not a profile change, and
            // should not fire events that later phases may hang observers off.
            $this->saveQuietly();

            // Otherwise a token on another device keeps authenticating against
            // a deleted account — SoftDeletes hides the user from queries, but
            // an already-issued token does not care.
            $tokensRevoked = $this->tokens()->count();
            $this->tokens()->delete();

            $this->delete();

            // Written explicitly, NOT by the RecordsAuditLog events: the
            // rename above is saveQuietly() and therefore fires nothing, so
            // an event listener would never see the single most audit-worthy
            // change in the system. The values below are the real before and
            // after, which no listener could reconstruct after the fact.
            $this->writeAuditLog(
                'deleted',
                ['email' => $this->original_email, 'deleted_at' => null],
                [
                    'email' => $this->email,
                    'deleted_at' => (string) $this->deleted_at,
                    'tokens_revoked' => $tokensRevoked,
                ],
            );
        });
    }

    /**
     * Restores the account and puts its original email back.
     *
     * @throws RuntimeException when the original address has been taken by
     *                          someone else in the meantime
     */
    public function restoreWithOriginalEmail(): void
    {
        DB::transaction(function () {
            $original = $this->original_email;

            if ($original !== null && static::originalEmailIsTaken($original, $this->id)) {
                // Freeing the address is the point of the tombstone, so
                // someone else may legitimately hold it now. Restoring anyway
                // would violate the unique index; silently keeping the
                // tombstone would hand back an account nobody can sign in as.
                throw new RuntimeException(
                    "Cannot restore this account: {$original} has since been registered by "
                    .'another user. Change that account\'s email first, or restore this one '
                    .'and assign it a new address.'
                );
            }

            $tombstone = $this->email;

            if ($original !== null) {
                $this->email = $original;
                $this->original_email = null;
                $this->saveQuietly();
            }

            $this->restore();

            // Explicit for the same reason as the delete: by the time the
            // `restored` event fires, the email has already been swapped back
            // quietly and the tombstone value is gone, so a listener could not
            // record what it was restored from.
            $this->writeAuditLog(
                'restored',
                ['email' => $tombstone, 'deleted_at' => 'set'],
                ['email' => $this->email, 'deleted_at' => null],
            );
        });
    }

    /**
     * Whether a live or trashed row other than this one already holds the
     * address. Trashed rows count: the unique index does not exempt them.
     */
    public static function originalEmailIsTaken(string $email, ?int $exceptId = null): bool
    {
        return static::withTrashed()
            ->where('email', $email)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function isTombstoned(): bool
    {
        return str_ends_with((string) $this->email, '@deleted.local');
    }

    /**
     * Whether this user holds a scoped ability (SPEC section 5.16).
     *
     * FAILS CLOSED: null or an empty array grants nothing. Only the wildcard
     * or an exact match returns true, and only admins hold permissions at
     * all — the other three roles never touch the panel.
     */
    public function hasPermission(Permission|string $permission): bool
    {
        if ($this->role !== UserRole::Admin) {
            return false;
        }

        $permissions = $this->permissions ?? [];

        if (! is_array($permissions) || $permissions === []) {
            return false;
        }

        if (in_array(Permission::WILDCARD, $permissions, strict: true)) {
            return true;
        }

        $value = $permission instanceof Permission ? $permission->value : $permission;

        return in_array($value, $permissions, strict: true);
    }

    /**
     * A super-admin: unrestricted, and the only role permitted to grant
     * permissions or modify another admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::Admin
            && is_array($this->permissions)
            && in_array(Permission::WILDCARD, $this->permissions, strict: true);
    }

    /**
     * Gate for the Filament admin panel.
     *
     * Filament calls this itself on every panel request. Implementing the
     * FilamentUser contract is not optional here: without it Filament allows
     * any authenticated user into the panel while APP_ENV=local.
     *
     * Reuses the existing UserRole enum rather than introducing a separate
     * admin flag or guard.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // The super-admin panel (/super-admin) is super-admins only; every
        // admin, super or not, uses the daily panel (/admin).
        if ($panel->getId() === 'super-admin') {
            return $this->isSuperAdmin();
        }

        return $this->role === UserRole::Admin;
    }

    /**
     * Two-step login secrets never reach audit_logs — only
     * two_factor_confirmed_at does, which is the "turned on / off" record.
     *
     * @return array<int, string>
     */
    protected function auditExcluded(): array
    {
        return [
            ...$this->baseAuditExcluded(),
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_last_step',
        ];
    }

    /** Two-step login is set up and confirmed (TwoFactorService). */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /**
     * Whether an admin has let this account into the product.
     *
     * SPEC section 3.1: this is the only gate. It replaced email
     * verification, which proved an address was reachable but said nothing
     * about whether the person behind it should be selling on the platform.
     *
     * Applies to every self-registered role. Accounts created *by* an admin
     * or a salesman are approved at creation time — somebody already vetted
     * them in person — so this returns true for them from the start.
     */
    public function isApproved(): bool
    {
        return $this->approval_status === ApprovalStatus::Approved;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->approval_status === ApprovalStatus::Pending;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === ApprovalStatus::Rejected;
    }

    /**
     * Records an admin's decision. Kept here rather than written field-by
     * -field at each call site so the status, the timestamp and the deciding
     * admin can never drift apart — a status with no decided_at makes the
     * audit log useless.
     *
     * A vendor is approved only once every compulsory KYC document is
     * Verified (VendorKycService) — checked here, the one place every
     * approval passes through, so no screen or bulk action can skip it.
     *
     * @throws KycIncompleteException
     */
    public function recordApprovalDecision(
        ApprovalStatus $status,
        ?User $decidedBy = null,
        ?string $note = null,
    ): void {
        if ($status === ApprovalStatus::Approved && $this->role === UserRole::Vendor && $this->vendor !== null) {
            app(VendorKycService::class)->assertComplete($this->vendor);
        }

        $this->forceFill([
            'approval_status' => $status,
            'approval_decided_at' => now(),
            'approval_decided_by' => $decidedBy?->getKey(),
            'approval_note' => $note,
        ])->save();

        // Live to the app's verification screen — after commit, since the
        // app answers it by re-reading GET /user and must see the decision
        // (approval can run inside VendorVerificationService's transaction).
        // rescue(): Reverb being down must not fail the admin's decision; it
        // is reported, and the app still finds out when it next checks.
        DB::afterCommit(fn () => rescue(fn () => event(new AccountApprovalDecided($this))));
    }

    /**
     * The admin who approved or rejected this account, where there was one.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvalDecidedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'approval_decided_by');
    }

    /**
     * A temp password an admin/salesman shares out-of-band — SPEC section
     * 5.2 (account creation) and section 2.2 (shared via WhatsApp at
     * Subscribe). Never stored anywhere in plaintext; the caller holds it
     * only long enough to show or send it once.
     *
     * Unambiguous alphabet — no 0/O or 1/l/I — because these get read aloud
     * down a phone line or typed off a WhatsApp message.
     */
    public static function generateTemporaryPassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * Sets the password the user chose through an emailed reset link
     * (Laravel's or Firebase's — see PasswordResetWebController). This is
     * the only way a forgotten password is reset: admins never set one.
     *
     * The user's own choice, so must_change_password is cleared (it may be
     * set on an account an admin or salesman created). Every app session is
     * signed out: a reset usually means the old password may be known to
     * someone else. Any request left over from the old admin-handled reset
     * is closed as resolved.
     */
    public function resetPasswordFromEmailLink(string $password): void
    {
        DB::transaction(function () use ($password) {
            $this->forceFill([
                'password' => $password,
                'must_change_password' => false,
            ])->save();

            $this->tokens()->delete();

            PasswordResetRequest::query()
                ->where('user_id', $this->getKey())
                ->pending()
                ->update([
                    'status' => PasswordResetRequest::STATUS_RESOLVED,
                    'resolved_by' => null,
                    'resolved_at' => now(),
                ]);
        });
    }

    /**
     * Public URL of the profile photo, or null for none. avatar_disk and
     * avatar_path are set only through ProfileController (forceFill) — they
     * are deliberately not fillable.
     */
    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return Storage::disk($this->avatar_disk ?? Vendor::currentUploadDisk())->url($this->avatar_path);
    }

    /**
     * The phone shown on the profile — it lives on the role's own table
     * (customers / vendors / salesmen), not on users.
     */
    public function profilePhone(): ?string
    {
        return match ($this->role) {
            UserRole::Customer => $this->customer?->phone,
            UserRole::Vendor => $this->vendor?->phone,
            UserRole::Salesman => $this->salesman?->phone,
            default => null,
        };
    }

    public function passwordResetRequests(): HasMany
    {
        return $this->hasMany(PasswordResetRequest::class);
    }
}
