<?php

namespace Tests\Feature\Schema;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function latestFor(string $type, int $id): AuditLog
    {
        return AuditLog::where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->latest('id')
            ->firstOrFail();
    }

    // ── User ────────────────────────────────────────────────────────────

    public function test_a_role_change_records_only_the_changed_field(): void
    {
        $user = User::factory()->role(UserRole::Vendor)->create();
        AuditLog::query()->delete();

        $user->update(['role' => UserRole::Salesman]);

        $entry = $this->latestFor(User::class, $user->id);

        $this->assertSame('updated', $entry->action);
        $this->assertSame(['role' => 'vendor'], $entry->old_values);
        $this->assertSame(['role' => 'salesman'], $entry->new_values);
    }

    public function test_unchanged_fields_are_not_recorded(): void
    {
        $user = User::factory()->create(['name' => 'Asha']);
        AuditLog::query()->delete();

        $user->update(['name' => 'Asha Patel']);

        $entry = $this->latestFor(User::class, $user->id);

        $this->assertSame(['name'], array_keys($entry->new_values));
    }

    public function test_a_save_that_changes_nothing_writes_no_entry(): void
    {
        $user = User::factory()->create();
        AuditLog::query()->delete();

        $user->update(['name' => $user->name]);

        $this->assertSame(0, AuditLog::count());
    }

    /**
     * Secrets must be excluded by a deny-list, not by remembering.
     */
    public function test_passwords_are_never_recorded(): void
    {
        $user = User::factory()->create();
        AuditLog::query()->delete();

        $user->update(['password' => 'a-brand-new-password']);

        $entries = AuditLog::all();

        foreach ($entries as $entry) {
            $this->assertArrayNotHasKey('password', $entry->new_values ?? []);
            $this->assertArrayNotHasKey('password', $entry->old_values ?? []);
            $this->assertArrayNotHasKey('remember_token', $entry->new_values ?? []);
        }

        // A password-only change leaves nothing meaningful to log.
        $this->assertSame(0, $entries->count());
    }

    public function test_creating_a_user_is_recorded(): void
    {
        $user = User::factory()->create();

        $entry = $this->latestFor(User::class, $user->id);

        $this->assertSame('created', $entry->action);
        $this->assertNull($entry->old_values);
        $this->assertArrayNotHasKey('password', $entry->new_values);
    }

    public function test_the_actor_is_recorded_when_someone_is_signed_in(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $target = User::factory()->role(UserRole::Vendor)->create();

        $this->actingAs($admin);
        AuditLog::query()->delete();

        $target->update(['role' => UserRole::Customer]);

        $this->assertSame($admin->id, $this->latestFor(User::class, $target->id)->user_id);
    }

    public function test_system_changes_record_a_null_actor(): void
    {
        // Seeders, console commands and jobs have no authenticated user.
        $user = User::factory()->create();

        $this->assertNull($this->latestFor(User::class, $user->id)->user_id);
    }

    // ── Tombstone: the case model events cannot see ─────────────────────

    /**
     * deleteWithTombstone() renames the email with saveQuietly(), which fires
     * no events — so this entry only exists because the method writes it
     * explicitly.
     */
    public function test_deleting_records_the_email_release_and_token_revocation(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.com']);
        $user->createToken('pixel-8');
        $user->createToken('ipad');
        AuditLog::query()->delete();

        $user->deleteWithTombstone();

        $entry = $this->latestFor(User::class, $user->id);

        $this->assertSame('deleted', $entry->action);
        $this->assertSame('asha@example.com', $entry->old_values['email']);
        $this->assertStringEndsWith('@deleted.local', $entry->new_values['email']);
        $this->assertSame(2, $entry->new_values['tokens_revoked']);
    }

    public function test_restoring_records_what_it_was_restored_from(): void
    {
        $user = User::factory()->create(['email' => 'back@example.com']);
        $user->deleteWithTombstone();
        $tombstone = $user->fresh()->email;
        AuditLog::query()->delete();

        $user->fresh()->restoreWithOriginalEmail();

        $entry = $this->latestFor(User::class, $user->id);

        $this->assertSame('restored', $entry->action);
        $this->assertSame($tombstone, $entry->old_values['email']);
        $this->assertSame('back@example.com', $entry->new_values['email']);
    }

    public function test_a_refused_restore_writes_no_entry(): void
    {
        // The transaction rolls back, so a failed restore must not leave a
        // log claiming it happened.
        $user = User::factory()->create(['email' => 'taken@example.com']);
        $user->deleteWithTombstone();
        User::factory()->create(['email' => 'taken@example.com']);
        AuditLog::query()->delete();

        try {
            $user->fresh()->restoreWithOriginalEmail();
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, AuditLog::where('action', 'restored')->count());
    }

    // ── Plan and quota ──────────────────────────────────────────────────

    public function test_a_price_change_carries_the_full_commercial_snapshot(): void
    {
        $plan = Plan::factory()->create(['price_paise' => 249900, 'duration_days' => 365]);
        AuditLog::query()->delete();

        $plan->update(['price_paise' => 299900]);

        $entry = $this->latestFor(Plan::class, $plan->id);

        // The diff still shows what moved...
        $this->assertSame(249900, $entry->old_values['price_paise']);
        $this->assertSame(299900, $entry->new_values['price_paise']);

        // ...and the entry is a complete point-in-time record of the package.
        foreach (['duration_days', 'max_categories', 'max_subcategories', 'max_zones', 'max_photos', 'max_videos', 'priority_rank'] as $field) {
            $this->assertArrayHasKey($field, $entry->new_values, "missing {$field}");
        }
    }

    /**
     * The reason the snapshot exists: subscriptions copy price and duration at
     * purchase but NOT quota, so this log is the only record of what an
     * existing subscriber actually bought.
     */
    public function test_a_quota_change_is_filed_against_the_plan(): void
    {
        $plan = Plan::factory()->create();

        // Pin the starting value. PlanQuotaFactory randomises max_zones
        // between 1 and 10, so updating it to a fixed number is a no-op
        // whenever the factory happens to roll that same number — no change,
        // no `updated` event, no audit row. The test then fails only on
        // certain runs, which reads as a bug in the audit trait rather than
        // as randomness in the fixture.
        $plan->quota->update(['max_zones' => 3]);
        AuditLog::query()->delete();

        $plan->quota->update(['max_zones' => 8]);

        $entry = $this->latestFor(Plan::class, $plan->id);

        $this->assertSame(Plan::class, $entry->auditable_type);
        $this->assertSame($plan->id, $entry->auditable_id);
        $this->assertSame(8, $entry->new_values['max_zones']);

        // No entry filed against the quota row itself.
        $this->assertSame(0, AuditLog::where('auditable_type', PlanQuota::class)->count());
    }

    public function test_a_quota_entry_snapshots_every_limit(): void
    {
        $plan = Plan::factory()->create();

        // Same pinning as above — see the note there.
        $plan->quota->update(['max_zones' => 3]);
        AuditLog::query()->delete();

        $plan->quota->update(['max_zones' => 8]);

        $entry = $this->latestFor(Plan::class, $plan->id);

        foreach (['max_categories', 'max_subcategories', 'max_zones', 'max_photos', 'max_videos', 'priority_rank'] as $field) {
            $this->assertArrayHasKey($field, $entry->new_values, "missing {$field}");
        }

        // The diff half still isolates what actually moved.
        $this->assertSame(['max_zones'], array_keys($entry->old_values));
    }

    public function test_a_plans_history_is_one_timeline(): void
    {
        $plan = Plan::factory()->create();
        AuditLog::query()->delete();

        $plan->update(['price_paise' => 299900]);
        $plan->quota->update(['max_photos' => 60]);
        $plan->update(['is_active' => false]);

        $history = AuditLog::where('auditable_type', Plan::class)
            ->where('auditable_id', $plan->id)
            ->get();

        $this->assertCount(3, $history);
    }

    // ── The log itself ──────────────────────────────────────────────────

    public function test_the_audit_log_does_not_audit_itself(): void
    {
        $user = User::factory()->create();
        $countAfterUser = AuditLog::count();

        // Writing entries must not generate entries about the entries.
        $this->assertSame(1, $countAfterUser);
        $this->assertSame(0, AuditLog::where('auditable_type', AuditLog::class)->count());
    }

    public function test_entries_are_write_once_with_no_updated_at(): void
    {
        $user = User::factory()->create();
        $entry = $this->latestFor(User::class, $user->id);

        $this->assertNotNull($entry->created_at);
        $this->assertNull(AuditLog::UPDATED_AT);
    }
}
