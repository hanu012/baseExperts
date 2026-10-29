<?php

namespace Tests\Feature\Services;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approving moves two things — the vendor's lifecycle status and the
 * owning account's access to the app — and the two are not the same
 * decision. This pins where each one applies.
 */
class VendorVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(string $status): Vendor
    {
        $user = User::factory()->pending()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => $status,
        ]);
    }

    private function service(): VendorVerificationService
    {
        return app(VendorVerificationService::class);
    }

    public function test_approving_a_vendor_awaiting_verification_activates_them(): void
    {
        $vendor = $this->vendor('pending_verification');
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->service()->approve($vendor, $admin);

        $this->assertSame('active', $vendor->fresh()->status);
        $this->assertTrue($vendor->user->fresh()->isApproved());
    }

    /**
     * The regression this guards.
     *
     * A self-registered vendor sits in 'draft' until they pay. An admin
     * approving their ACCOUNT — which they must, or the person cannot get
     * into the app at all (SPEC section 3.1) — used to also force the
     * vendor to 'active'. StoreSubscriptionRequest only accepts a vendor
     * in 'draft', so Subscribe then failed with "This vendor is not
     * awaiting subscription", leaving them active, unpaid, and unable to
     * buy anything.
     */
    public function test_approving_a_draft_vendor_does_not_mark_them_active(): void
    {
        $vendor = $this->vendor('draft');
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->service()->approve($vendor, $admin);

        // Status untouched, so Subscribe still accepts them...
        $this->assertSame('draft', $vendor->fresh()->status);

        // ...but the account is let into the app, which is the whole
        // point of approving it.
        $this->assertTrue($vendor->user->fresh()->isApproved());
        $this->assertSame(
            ApprovalStatus::Approved,
            $vendor->user->fresh()->approval_status,
        );
    }

    public function test_approving_a_rejected_vendor_without_a_subscription_returns_them_to_draft(): void
    {
        $vendor = $this->vendor('rejected');
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->service()->approve($vendor, $admin);

        $this->assertSame('draft', $vendor->fresh()->status);
        $this->assertTrue($vendor->user->fresh()->isApproved());
    }

    public function test_rejecting_marks_the_vendor_and_the_account_rejected(): void
    {
        $vendor = $this->vendor('pending_verification');
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->service()->reject($vendor, $admin, 'Address could not be confirmed.');

        $this->assertSame('rejected', $vendor->fresh()->status);
        $this->assertTrue($vendor->user->fresh()->isRejected());
        $this->assertSame(
            'Address could not be confirmed.',
            $vendor->user->fresh()->approval_note,
        );
    }
}
