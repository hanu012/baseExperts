<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * subscriptions:process-expiry (BUILD_PLAN 7.1, SPEC section 7) —
 * Active -> Grace -> Expired.
 */
class ProcessSubscriptionExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Vendor, 1: Subscription}
     */
    private function vendorWithSubscription(string $status, \DateTimeInterface|string $endDate): array
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => $status,
        ]);

        $plan = Plan::factory()->create();

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => $status,
            'start_date' => now()->subDays(300),
            'end_date' => $endDate,
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return [$vendor, $subscription];
    }

    // ── Active -> Grace ──────────────────────────────────────────────────

    public function test_an_active_subscription_past_its_end_date_moves_to_grace(): void
    {
        [$vendor, $subscription] = $this->vendorWithSubscription('active', now()->subDay());

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('grace', $subscription->fresh()->status);
        $this->assertSame('grace', $vendor->fresh()->status);
    }

    public function test_an_active_subscription_whose_end_date_is_today_is_not_yet_moved(): void
    {
        [$vendor, $subscription] = $this->vendorWithSubscription('active', now()->startOfDay());

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('active', $vendor->fresh()->status);
    }

    public function test_an_active_subscription_not_yet_expired_is_untouched(): void
    {
        [$vendor, $subscription] = $this->vendorWithSubscription('active', now()->addDays(10));

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('active', $vendor->fresh()->status);
    }

    // ── Grace -> Expired ─────────────────────────────────────────────────

    public function test_a_grace_subscription_past_the_default_grace_period_moves_to_expired(): void
    {
        // No grace_period_days seeded — the command must fall back to
        // the documented default of 7, same as Setting::get()'s own
        // $default parameter everywhere else it's called.
        [$vendor, $subscription] = $this->vendorWithSubscription('grace', now()->subDays(8));

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('expired', $vendor->fresh()->status);
    }

    public function test_a_grace_subscription_still_within_the_default_grace_period_is_untouched(): void
    {
        [$vendor, $subscription] = $this->vendorWithSubscription('grace', now()->subDays(3));

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('grace', $subscription->fresh()->status);
        $this->assertSame('grace', $vendor->fresh()->status);
    }

    public function test_a_custom_grace_period_setting_is_respected(): void
    {
        Setting::create(['key' => 'grace_period_days', 'value' => '3', 'type' => 'integer']);

        // 4 days past end_date — past a 3-day grace period, still
        // within the 7-day default, so this only passes if the
        // command actually reads the setting rather than hardcoding 7.
        [$vendor, $subscription] = $this->vendorWithSubscription('grace', now()->subDays(4));

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame('expired', $vendor->fresh()->status);
    }

    // ── Terminal states are never touched ───────────────────────────────

    public function test_a_cancelled_subscription_is_never_touched(): void
    {
        // 'cancelled'/'superseded' are subscription-only states —
        // vendors.status has no such values, so the vendor is given a
        // real (if unrelated) status of its own.
        [, $subscription] = $this->vendorWithSubscription('active', now()->subDays(30));
        $subscription->update(['status' => 'cancelled']);

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('cancelled', $subscription->fresh()->status);
    }

    public function test_a_superseded_subscription_is_never_touched(): void
    {
        [, $subscription] = $this->vendorWithSubscription('active', now()->subDays(30));
        $subscription->update(['status' => 'superseded']);

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('superseded', $subscription->fresh()->status);
    }

    /**
     * The bug found during survey: a self-registered vendor's
     * subscription is created `active` by SubscriptionService BEFORE
     * admin approval, and VendorVerificationService::reject() only
     * ever sets vendor.status — it never cancels the subscription.
     * Without the whereHas('vendor', ...) guard, this dangling active
     * subscription would resurrect a rejected vendor to 'grace' once
     * its irrelevant end_date lapsed.
     */
    public function test_a_rejected_vendors_dangling_active_subscription_is_not_cascaded(): void
    {
        [$vendor, $subscription] = $this->vendorWithSubscription('active', now()->subDays(30));
        $vendor->update(['status' => 'rejected', 'rejection_reason' => 'Invalid KYC documents']);

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertSame('rejected', $vendor->fresh()->status);
        // The subscription row itself is a separate, lesser concern —
        // what matters is the vendor never moved off 'rejected'.
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_is_suspended_is_never_touched_by_either_transition(): void
    {
        [$vendor] = $this->vendorWithSubscription('active', now()->subDay());
        $vendor->update(['is_suspended' => true]);

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $this->assertTrue($vendor->fresh()->is_suspended);
        $this->assertSame('grace', $vendor->fresh()->status);
    }

    // ── Audit trail ──────────────────────────────────────────────────────

    public function test_both_transitions_write_real_audit_log_entries(): void
    {
        [$vendor, $subscription] = $this->vendorWithSubscription('active', now()->subDay());

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        $subscriptionEntry = AuditLog::where('auditable_type', $subscription->getMorphClass())
            ->where('auditable_id', $subscription->id)
            ->where('action', 'updated')
            ->sole();
        $this->assertSame('grace', $subscriptionEntry->new_values['status']);

        $vendorEntry = AuditLog::where('auditable_type', $vendor->getMorphClass())
            ->where('auditable_id', $vendor->id)
            ->where('action', 'updated')
            ->sole();
        $this->assertSame('grace', $vendorEntry->new_values['status']);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_running_twice_in_a_row_only_transitions_once(): void
    {
        [$vendor, $subscription] = $this->vendorWithSubscription('active', now()->subDay());

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();
        $this->assertSame('grace', $subscription->fresh()->status);

        $this->artisan('subscriptions:process-expiry')->assertSuccessful();

        // Still grace, not expired — the second run must not treat an
        // already-transitioned row as a fresh active->grace candidate,
        // and it's nowhere near the (default 7-day) grace cutoff yet.
        $this->assertSame('grace', $subscription->fresh()->status);
        $this->assertSame('grace', $vendor->fresh()->status);

        $subscriptionUpdates = AuditLog::where('auditable_type', $subscription->getMorphClass())
            ->where('auditable_id', $subscription->id)
            ->where('action', 'updated')
            ->count();
        $this->assertSame(1, $subscriptionUpdates);
    }
}
