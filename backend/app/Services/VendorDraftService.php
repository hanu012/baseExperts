<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating a vendor in Draft (SPEC section 2.2).
 *
 * In app/Services because it spans two tables in one transaction, which
 * CLAUDE.md puts here regardless of whether repositories are used elsewhere.
 * A half-applied create is the specific failure to avoid: a `users` row with
 * no `vendors` row is an account nobody can reach and an email nobody can
 * reuse, since the unique index still holds it.
 */
class VendorDraftService
{
    /**
     * An existing draft the same salesman can resume, if there is one.
     *
     * SPEC section 2.2: salesmen work in the field on unreliable networks and
     * must be able to resume. If the response is lost after the row is
     * written, a retry has to recover the draft rather than dead-end on a
     * duplicate error the salesman cannot clear from a rooftop.
     *
     * Deliberately narrow. Only a DRAFT vendor created by this same salesman
     * is resumable: anything further along its lifecycle, or belonging to
     * someone else, is a genuine duplicate and still rejects.
     */
    public function findResumableDraft(string $email, string $phone, ?int $salesmanId): ?Vendor
    {
        if ($salesmanId === null) {
            return null;
        }

        return Vendor::query()
            ->where('status', 'draft')
            ->where('created_by_salesman_id', $salesmanId)
            ->where(function ($query) use ($email, $phone) {
                $query->where('phone', $phone)
                    ->orWhereHas('user', fn ($q) => $q->where('email', $email));
            })
            ->first();
    }

    /**
     * Conflicts that are not resumable — a real duplicate.
     *
     * @return array<string, string> field => message, empty when clear
     */
    public function conflicts(string $email, string $phone, ?int $ignoreVendorId = null): array
    {
        $conflicts = [];

        // Trashed rows count: the unique indexes do not exempt them.
        $emailTaken = User::withTrashed()
            ->where('email', $email)
            ->when($ignoreVendorId, fn ($q) => $q->whereDoesntHave(
                'vendor',
                fn ($v) => $v->where('id', $ignoreVendorId)
            ))
            ->exists();

        if ($emailTaken) {
            $conflicts['email'] = 'This email is already registered.';
        }

        $phoneTaken = Vendor::withTrashed()
            ->where('phone', $phone)
            ->when($ignoreVendorId, fn ($q) => $q->whereKeyNot($ignoreVendorId))
            ->exists();

        if ($phoneTaken) {
            $conflicts['phone'] = 'This phone number already belongs to another vendor.';
        }

        return $conflicts;
    }

    /**
     * Creates the user and vendor rows together.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?Salesman $salesman): Vendor
    {
        return DB::transaction(function () use ($data, $salesman) {
            $user = User::create([
                'name' => $data['owner_name'],
                'email' => $data['email'],
                // A strong random password the salesman never sees. SPEC
                // section 2.2 generates the shareable temp password at the
                // Subscribe step, not here — a vendor who never completes
                // payment should not be left holding working credentials to
                // a Draft account.
                'password' => Str::password(24),
                'role' => UserRole::Vendor,
            ]);

            $user->forceFill([
                // Pending, not approved (decided 2026-10-28, replacing SPEC
                // sections 3.1/7's "salesman-added vendors skip
                // verification"): like every vendor, the account is approved
                // by an admin once its compulsory KYC documents — uploaded
                // by the salesman — are Verified (VendorKycService).
                'approval_status' => ApprovalStatus::Pending,
                // Still true: whatever password they are eventually given is
                // one they did not choose.
                'must_change_password' => true,
            ])->saveQuietly();

            return Vendor::create([
                'user_id' => $user->id,
                'business_name' => $data['business_name'],
                'owner_name' => $data['owner_name'],
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'id_proof_type' => $data['id_proof_type'] ?? null,
                // SPEC section 7: Draft until payment. pending_verification is
                // for self-registered vendors only.
                'status' => 'draft',
                'created_by_salesman_id' => $salesman?->id,
            ]);
        });
    }
}
