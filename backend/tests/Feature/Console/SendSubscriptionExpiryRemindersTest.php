<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\DeviceToken;
use App\Models\Notification as NotificationLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * subscriptions:send-expiry-reminders (BUILD_PLAN 7.2, T-15/T-7/T-1).
 */
class SendSubscriptionExpiryRemindersTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC4m4P2vDq87QX4
    KMoFza5GDIuyroonU2X4EMyZR7D7kf1D0R5aW1EfaQAFxTRaVlYeXHOroDy7URdg
    /7jUJGlNR6gKSiCibXLGMgmUPgstLElGH066zldjUfp/XfUnIcCvupfFYu1IfRMm
    96GDpGZtxgCYtSCUtp58ZuL8nhoHQgtMKIDrQp80QiKC0L1sp/nVGVj+teyqXffX
    T/MKhW4HEynPVpXe6KEEnvjMIM+eQlRZ2JkbU3BdCgdVePvDeYxg5w2XFKPOYKu/
    5kksmeLNzEJwTrYFJU1AZAhq6IJdQs4UYqa+ahKg26KU4e8FUbSbHQHeP+7EKcn4
    otcd19nLAgMBAAECggEAA4h9ocfGRTf+GlrhfvOJ528b1cG8a1zcjnJeQ0kmRhi4
    5OB7bCKwrnq3R7HK1J3JZfX1nfr7npQo1km4P/f21SyCNuwzReVbwhcfRgL0xzRA
    eylkCHQ+VoX+Cgw1m6TSoZDFkQwMwYuCeQdaEOehF26OM8Rnr4daCJFJiXWXLVaN
    N4VfGHIe6g7vvoSx99pvcb74zTpWzVzw3D9eq0lVH47AUv8SMXgL2apVN/BxTcRf
    L/XhEDWAOy4yKEv1TMQs/pP0/PBMbJ/76jieR+BfEqtHNSAzajh1APp492d3OVy9
    J0ujgEzqio/AaJTh4NuZTx1yIGaCK+/iJeqTLKrozQKBgQDmaRUVYQa4l2cTm0BM
    2pshKFHp0DJr3uUgZqiLfw/3jdy0Qhnmpu444X6XBKrugGJus0Evq5GRHUiDB3YV
    lzn6bFRnYqmZGr7IAYHJcB5kmC2Dk1fvx0bKzbCTnVmaLfmMUqsJhQ0oTJLIpotK
    kUoaenMxWB3tGCKCFPjFOWwa9wKBgQDNHC9RIE3Jt3roPLnqSNwmLLmGh9GQfVBs
    5z/Cmg0v6zodwm0JkIxvVujZpsrWJ5g6KT3JoVxDkSUG+D8G7PEvI/6k9e0Ug5YF
    /YNmJLmXBP5ZjFK7iubFQzEcNXU5xv/r2ErE+W+aKgRxnBv48QtYBHdaYfWkL5Qm
    9+f5g6lOzQKBgAi7quTojIyqkGmZ1NIU5xRWpuQp0/9qr1yPB4xiAITth5P9fWXU
    peraASZQMvpfO1vex3W7FwVdCsaMndkrpjLrsDdK8gqvjNOf2v97lGtTqUX3a7nW
    38QID81IhYDmhTLgX0M5G8qPPHEGfvkQkLJ4Oa2BHYFDDOvJR7SR/Jr5AoGANdPS
    uxieMXTcZXwiUlDCraYJHjwgjCnG5H2fpwNkuJGj09GFagAsSr/lJdF249LKSWEv
    XO3i17yMmhKl/7xI41Uv67y6diq+QV4xkKnMpsxhr8B6qcsfGt+yULPaysnludAu
    dxj659tlBSex05f2oSey5t5UZ70wxTVEBKA/23UCgYBcHnUrVNpfBWzHRvbulhWn
    mSECKh9IlA2hZYk0dDrfxEPb1EHyHtAEFO13wvm0lP++VX3fzQRwIp46TLYxN9NT
    XTtQ8ZP+BNagHtEFCXpSz4ia5d67v0vj8HpVJSzgs/k9pdC+ENvi8kGo5vnGhN4d
    5r7pMiksXXp41vBgloVv3A==
    -----END PRIVATE KEY-----
    PEM;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.fcm.project_id' => 'test-project',
            'services.fcm.client_email' => 'test@test-project.iam.gserviceaccount.com',
            'services.fcm.private_key_base64' => base64_encode(self::FIXTURE_PRIVATE_KEY),
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);
    }

    /**
     * @return array{0: Vendor, 1: Subscription}
     */
    private function vendorWithSubscription($endDate): array
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        DeviceToken::create(['user_id' => $user->id, 'token' => "token-{$user->id}", 'platform' => 'android']);

        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now()->subDays(300),
            'end_date' => $endDate,
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return [$vendor, $subscription];
    }

    public function test_a_subscription_exactly_15_days_out_gets_the_t15_reminder(): void
    {
        [, $subscription] = $this->vendorWithSubscription(now()->addDays(15));

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $reloaded = $subscription->fresh();
        $this->assertNotNull($reloaded->reminder_sent_t15_at);
        $this->assertNull($reloaded->reminder_sent_t7_at);
        $this->assertNull($reloaded->reminder_sent_t1_at);
    }

    public function test_a_subscription_16_days_out_gets_no_reminder_yet(): void
    {
        [, $subscription] = $this->vendorWithSubscription(now()->addDays(16));

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $reloaded = $subscription->fresh();
        $this->assertNull($reloaded->reminder_sent_t15_at);
        $this->assertNull($reloaded->reminder_sent_t7_at);
        $this->assertNull($reloaded->reminder_sent_t1_at);
    }

    public function test_a_subscription_exactly_1_day_out_gets_all_three_thresholds_stamped_at_once(): void
    {
        // Never seen by this job before (e.g. the feature just
        // shipped). All three thresholds are stamped so none fires
        // later — but only ONE push goes out, saying the real time left.
        [, $subscription] = $this->vendorWithSubscription(now()->addDay());

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $reloaded = $subscription->fresh();
        $this->assertNotNull($reloaded->reminder_sent_t15_at);
        $this->assertNotNull($reloaded->reminder_sent_t7_at);
        $this->assertNotNull($reloaded->reminder_sent_t1_at);

        $log = NotificationLog::where('type', 'subscription_expiring')->sole();
        $this->assertStringContainsString('expires in 1 day', $log->body);
    }

    /**
     * The bug this fixes: a 7-day free trial is inside both the 15- and
     * 7-day windows on its first day, and got two pushes at once — one
     * saying "15 days".
     */
    public function test_a_7_day_trial_gets_one_reminder_with_the_real_days_left(): void
    {
        [, $subscription] = $this->vendorWithSubscription(now()->addDays(7));

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $log = NotificationLog::where('type', 'subscription_expiring')->sole();
        $this->assertStringContainsString('expires in 7 days', $log->body);
        $this->assertStringNotContainsString('15 days', $log->body);

        $reloaded = $subscription->fresh();
        $this->assertNotNull($reloaded->reminder_sent_t15_at);
        $this->assertNotNull($reloaded->reminder_sent_t7_at);
        $this->assertNull($reloaded->reminder_sent_t1_at);
    }

    public function test_the_last_day_reminder_still_follows_later(): void
    {
        [, $subscription] = $this->vendorWithSubscription(now()->addDays(7));

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        // Six days later: one day left.
        $this->travel(6)->days();
        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $bodies = NotificationLog::where('type', 'subscription_expiring')->orderBy('id')->pluck('body')->all();
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('expires in 1 day', $bodies[1]);
        $this->assertNotNull($subscription->fresh()->reminder_sent_t1_at);
    }

    public function test_a_plan_ending_today_says_today(): void
    {
        $this->vendorWithSubscription(now()->startOfDay());

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $log = NotificationLog::where('type', 'subscription_expiring')->sole();
        $this->assertStringContainsString('expires today', $log->body);
    }

    public function test_running_twice_in_a_row_does_not_resend(): void
    {
        [, $subscription] = $this->vendorWithSubscription(now()->addDays(15));

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();
        $firstStamp = $subscription->fresh()->reminder_sent_t15_at;

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $this->assertSame(1, NotificationLog::where('type', 'subscription_expiring')->count());
        $this->assertTrue($firstStamp->equalTo($subscription->fresh()->reminder_sent_t15_at));
    }

    public function test_a_subscription_already_in_grace_is_skipped(): void
    {
        [, $subscription] = $this->vendorWithSubscription(now()->subDays(2));
        $subscription->update(['status' => 'grace']);

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $reloaded = $subscription->fresh();
        $this->assertNull($reloaded->reminder_sent_t15_at);
        $this->assertNull($reloaded->reminder_sent_t7_at);
        $this->assertNull($reloaded->reminder_sent_t1_at);
    }

    public function test_each_send_writes_a_real_notification_log_row(): void
    {
        // Exactly 15 days out (the widest threshold) so only ONE
        // reminder fires this run — a closer end_date would also
        // cross the narrower thresholds in the same pass (the
        // catch-up behaviour the T-1 test above already covers),
        // which would make this specifically about "one row" murkier
        // than it needs to be.
        [$vendor] = $this->vendorWithSubscription(now()->addDays(15));

        $this->artisan('subscriptions:send-expiry-reminders')->assertSuccessful();

        $log = NotificationLog::where('type', 'subscription_expiring')->sole();
        $this->assertSame('vendor', $log->target_app);
        $this->assertSame(['user_id' => $vendor->user_id], $log->audience);
        $this->assertSame(1, $log->sent_count);
    }
}
