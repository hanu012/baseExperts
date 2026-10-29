<?php

namespace Tests\Feature\Notifications;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Notification as NotificationLog;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUILD_PLAN 7.2 — exercises the REAL call sites (VendorVerificationService,
 * VendorLeadController::requestReview, LeadController::store), not
 * PushNotificationService directly, so a regression in the wiring
 * between a business action and its notification would actually be
 * caught here.
 */
class PushNotificationTriggersTest extends TestCase
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

        // Only the token exchange is shared across every test in this
        // class — every test needs it and none override it. The
        // fcm.googleapis.com response is declared per test instead:
        // Http::fake() MERGES repeated calls rather than replacing
        // them (first registered stub wins on a URL match), so a
        // second, test-local Http::fake() call for the same pattern
        // would silently never take effect if a catch-all success
        // stub were already registered here.
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
        ]);
    }

    private function fakeFcmSendSucceeds(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);
    }

    private function withDeviceToken(User $user): User
    {
        DeviceToken::create(['user_id' => $user->id, 'token' => "token-{$user->id}", 'platform' => 'android']);

        return $user;
    }

    private function vendorWithUser(): array
    {
        $user = $this->withDeviceToken(User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]));
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'pending_verification',
            'shop_photo_path' => 'vendor-kyc/shop.jpg',
            'id_proof_type' => 'aadhaar',
            'id_proof_path' => 'vendor-kyc/id.jpg',
        ]);

        return [$user, $vendor];
    }

    // ── Vendor approved / rejected (SPEC section 5.8) ───────────────────

    public function test_approving_a_vendor_sends_a_push_and_logs_it(): void
    {
        $this->fakeFcmSendSucceeds();
        [$user, $vendor] = $this->vendorWithUser();
        $admin = User::factory()->role(UserRole::Admin)->create();

        app(VendorVerificationService::class)->approve($vendor, $admin);

        Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
            && $request->data()['message']['token'] === "token-{$user->id}"
            && $request->data()['message']['notification']['title'] === 'Verification approved');

        $log = NotificationLog::sole();
        $this->assertSame('verification_approved', $log->type);
        $this->assertSame('vendor', $log->target_app);
        $this->assertSame(['user_id' => $user->id], $log->audience);
        $this->assertSame(1, $log->sent_count);
        $this->assertSame(0, $log->failed_count);
    }

    public function test_rejecting_a_vendor_sends_a_push_with_the_reason_and_logs_it(): void
    {
        $this->fakeFcmSendSucceeds();
        [$user, $vendor] = $this->vendorWithUser();
        $admin = User::factory()->role(UserRole::Admin)->create();

        app(VendorVerificationService::class)->reject($vendor, $admin, 'Invalid KYC documents');

        Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
            && $request->data()['message']['notification']['body'] === 'Invalid KYC documents');

        $log = NotificationLog::sole();
        $this->assertSame('verification_rejected', $log->type);
    }

    // ── A notifiable with no registered devices still logs, sends nothing ──

    public function test_a_vendor_with_no_device_tokens_still_logs_zero_sent(): void
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'pending_verification',
        ]);
        $admin = User::factory()->role(UserRole::Admin)->create();

        app(VendorVerificationService::class)->approve($vendor, $admin);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));

        $log = NotificationLog::sole();
        $this->assertSame(0, $log->sent_count);
        $this->assertSame(0, $log->failed_count);
    }

    // ── Review requested (task 4.8) — sent to the CUSTOMER ──────────────

    public function test_requesting_a_review_notifies_the_customer_not_the_vendor(): void
    {
        $this->fakeFcmSendSucceeds();
        [$vendorUser, $vendor] = $this->vendorWithUser();
        $vendor->update(['status' => 'active']);

        $customerUser = $this->withDeviceToken(
            User::factory()->role(UserRole::Customer)->create(['must_change_password' => false])
        );
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $subcategory = Subcategory::factory()->for(Category::factory())->create();

        $lead = \App\Models\Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ]);

        $this->actingAs($vendorUser, 'sanctum')
            ->postJson("/api/vendors/me/leads/{$lead->id}/request-review")
            ->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
            && $request->data()['message']['token'] === "token-{$customerUser->id}");

        $log = NotificationLog::sole();
        $this->assertSame('review_request', $log->type);
        $this->assertSame('customer', $log->target_app);
        $this->assertSame(['user_id' => $customerUser->id], $log->audience);
    }

    // ── Lead received (new, task 5.4 call site) — sent to the VENDOR ───

    public function test_recording_a_lead_notifies_the_vendor(): void
    {
        $this->fakeFcmSendSucceeds();
        [$vendorUser, $vendor] = $this->vendorWithUser();
        $vendor->update(['status' => 'active']);

        $customerUser = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        Customer::create(['user_id' => $customerUser->id]);
        $subcategory = Subcategory::factory()->for(Category::factory())->create();

        $this->actingAs($customerUser, 'sanctum')
            ->postJson('/api/leads', [
                'vendor_id' => $vendor->id,
                'subcategory_id' => $subcategory->id,
                'channel' => 'whatsapp',
            ])
            ->assertStatus(201);

        Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
            && $request->data()['message']['token'] === "token-{$vendorUser->id}"
            && $request->data()['message']['notification']['title'] === 'New lead received');

        $log = NotificationLog::sole();
        $this->assertSame('lead_received', $log->type);
        $this->assertSame('vendor', $log->target_app);
    }

    /**
     * A stale/invalid device token, or the FCM call itself failing,
     * must never turn a successful lead write into a failed
     * response — FcmChannel's own exception handling is what
     * guarantees this.
     */
    public function test_a_failed_push_never_breaks_the_lead_recording_response(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => 'invalid token'], 400),
        ]);

        [$vendorUser, $vendor] = $this->vendorWithUser();
        $vendor->update(['status' => 'active']);

        $customerUser = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        Customer::create(['user_id' => $customerUser->id]);
        $subcategory = Subcategory::factory()->for(Category::factory())->create();

        $this->actingAs($customerUser, 'sanctum')
            ->postJson('/api/leads', [
                'vendor_id' => $vendor->id,
                'subcategory_id' => $subcategory->id,
                'channel' => 'call',
            ])
            ->assertStatus(201);

        $log = NotificationLog::sole();
        $this->assertSame(0, $log->sent_count);
        $this->assertSame(1, $log->failed_count);
    }
}
