<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\VendorVerificationResource\Pages\ListVendorVerifications;
use App\Filament\Resources\VendorVerificationResource\Pages\ViewVendorVerification;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vendor Verification Queue (SPEC section 5.8, task 4.3).
 */
class VendorVerificationResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    private function vendor(string $status = 'pending_verification', array $overrides = []): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'address' => '12 MG Road',
            'status' => $status,
            'shop_photo_path' => 'vendor-kyc/shop.jpg',
            'id_proof_type' => 'aadhaar',
            'id_proof_path' => 'vendor-kyc/id.jpg',
        ], $overrides));
    }

    public function test_the_list_only_shows_pending_verification_vendors(): void
    {
        $pending = $this->vendor('pending_verification');
        $this->vendor('draft');
        $this->vendor('active');
        $this->vendor('rejected');

        Livewire::test(ListVendorVerifications::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([]); // sanity: page renders successfully
    }

    public function test_approve_activates_the_vendor_and_records_who_and_when(): void
    {
        $vendor = $this->vendor();

        Livewire::test(ListVendorVerifications::class)
            ->callTableAction('approve', $vendor);

        $reloaded = $vendor->fresh();

        $this->assertSame('active', $reloaded->status);
        $this->assertNotNull($reloaded->verified_at);
        $this->assertSame($this->admin->id, $reloaded->verified_by);
        $this->assertNull($reloaded->rejection_reason);
    }

    public function test_approved_vendor_no_longer_appears_in_the_queue(): void
    {
        $vendor = $this->vendor();

        Livewire::test(ListVendorVerifications::class)
            ->callTableAction('approve', $vendor);

        Livewire::test(ListVendorVerifications::class)
            ->assertCanNotSeeTableRecords([$vendor->fresh()]);
    }

    public function test_reject_requires_a_reason(): void
    {
        $vendor = $this->vendor();

        Livewire::test(ListVendorVerifications::class)
            ->callTableAction('reject', $vendor, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame('pending_verification', $vendor->fresh()->status);
    }

    public function test_reject_stores_the_reason_and_a_distinct_status(): void
    {
        $vendor = $this->vendor();

        Livewire::test(ListVendorVerifications::class)
            ->callTableAction('reject', $vendor, data: ['reason' => 'ID proof is unreadable']);

        $reloaded = $vendor->fresh();

        $this->assertSame('rejected', $reloaded->status);
        $this->assertSame('ID proof is unreadable', $reloaded->rejection_reason);
        $this->assertSame($this->admin->id, $reloaded->verified_by);
    }

    /**
     * The approve/reject decision is made on this page, so the documents
     * being decided on have to be visible. The ID proof used to render as
     * a text link reading only "Aadhaar".
     */
    public function test_the_view_page_shows_both_kyc_documents_full_size(): void
    {
        $vendor = $this->vendor();

        $vendor->forceFill([
            'disk' => 'public',
            'shop_photo_path' => 'vendor-kyc/'.$vendor->id.'/shop.jpg',
            'id_proof_path' => 'vendor-kyc/'.$vendor->id.'/id.jpg',
            'id_proof_type' => 'aadhaar',
        ])->save();

        Livewire::test(ViewVendorVerification::class, ['record' => $vendor->getKey()])
            ->assertSuccessful()
            ->assertSee('Shop photo')
            ->assertSee('ID proof')
            // Each links to the original, not just the thumbnail.
            ->assertSee($vendor->fileUrl($vendor->shop_photo_path), escape: false)
            ->assertSee($vendor->fileUrl($vendor->id_proof_path), escape: false);
    }

    /**
     * A vendor can reach this queue with nothing uploaded, so neither
     * entry may hard-fail on a null path.
     */
    public function test_the_view_page_renders_when_no_kyc_was_uploaded(): void
    {
        $vendor = $this->vendor('pending_verification', [
            'shop_photo_path' => null,
            'id_proof_path' => null,
            'id_proof_type' => null,
        ]);

        Livewire::test(ViewVendorVerification::class, ['record' => $vendor->getKey()])
            ->assertSuccessful()
            ->assertSee('Not provided');
    }

    public function test_the_view_page_offers_the_same_approve_and_reject_actions(): void
    {
        $vendor = $this->vendor();

        Livewire::test(ViewVendorVerification::class, ['record' => $vendor->getKey()])
            ->assertActionExists('approve')
            ->assertActionExists('reject')
            ->callAction('approve');

        $this->assertSame('active', $vendor->fresh()->status);
    }

    /**
     * SPEC section 5.8: vendors aren't created or deleted through this
     * queue, only transitioned. Asserting absence, not merely that it is
     * disabled — same discipline CLAUDE.md calls out for master data.
     */
    public function test_no_create_action_is_registered(): void
    {
        Livewire::test(ListVendorVerifications::class)
            ->assertActionDoesNotExist('create');
    }

    public function test_a_sub_admin_without_the_verify_permission_cannot_act(): void
    {
        $vendor = $this->vendor();
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::VendorsViewAny->value],
        ]);

        $this->actingAs($subAdmin);

        Livewire::test(ListVendorVerifications::class)
            ->assertTableActionHidden('approve', $vendor)
            ->assertTableActionHidden('reject', $vendor);
    }

    public function test_a_sub_admin_with_the_verify_permission_can_act(): void
    {
        $vendor = $this->vendor();
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::VendorsViewAny->value, Permission::VendorsVerify->value],
        ]);

        $this->actingAs($subAdmin);

        Livewire::test(ListVendorVerifications::class)
            ->callTableAction('approve', $vendor);

        $this->assertSame('active', $vendor->fresh()->status);
    }
}
