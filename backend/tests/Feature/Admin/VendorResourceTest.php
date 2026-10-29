<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\VendorResource;
use App\Filament\Resources\VendorResource\Pages\ListVendors;
use App\Filament\Resources\VendorResource\Pages\ViewVendor;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Salesman;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Zone;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin-side answer to "what is this vendor subscribed to?" (SPEC
 * section 5.2) — the read-only counterpart to the verification queue,
 * which is scoped to pending_verification and so cannot show an active
 * vendor at all.
 */
class VendorResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    /** There is no VendorFactory — vendors are built by hand in these tests. */
    private function vendor(string $status = 'draft'): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'address' => '12 MG Road',
            'status' => $status,
        ]);
    }

    private function subscribedVendor(): Vendor
    {
        $vendor = $this->vendor('active');

        $plan = Plan::factory()->create(['name' => 'Platinum']);
        $plan->quota()->update([
            'max_categories' => 5,
            'max_subcategories' => 10,
            'max_zones' => 8,
            'max_photos' => 20,
            'max_videos' => 3,
        ]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now()->subDays(5),
            'end_date' => now()->addDays(360),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $category = Category::factory()->create(['name' => 'AC Service']);
        $subcategory = Subcategory::factory()->create([
            'category_id' => $category->id,
            'name' => 'AC Installation',
        ]);
        $zone = Zone::factory()->create(['name' => 'Bodakdev']);

        foreach ([
            ['category', $category->id],
            ['subcategory', $subcategory->id],
            ['zone', $zone->id],
        ] as [$type, $id]) {
            SubscriptionItem::create([
                'subscription_id' => $subscription->id,
                'item_type' => $type,
                'item_id' => $id,
            ]);
        }

        return $vendor;
    }

    public function test_the_view_page_shows_how_the_subscription_was_paid(): void
    {
        $vendor = $this->subscribedVendor();
        $subscription = $vendor->currentActiveSubscription();

        $salesmanUser = User::factory()->role(UserRole::Salesman)->create(['name' => 'Riya Patel']);
        $salesman = Salesman::create([
            'user_id' => $salesmanUser->id,
            'employee_code' => 'EMP-PAY',
            'phone' => '9900000010',
        ]);

        $payments = app(PaymentService::class);
        $payments->payViaCash($subscription, 99900, $salesman);
        $payments->payOnline($subscription, 25000);

        Livewire::test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->assertSuccessful()
            ->assertSee('Payments')
            ->assertSee('Plan')
            ->assertSee('Add-on')
            ->assertSee('Cash')
            ->assertSee('Online')
            ->assertSee(Number::currency(999, 'INR'))
            ->assertSee(Number::currency(250, 'INR'))
            ->assertSee('Cash collected — awaiting admin verification')
            ->assertSee('Paid online')
            ->assertSee('Riya Patel');
    }

    public function test_a_free_trial_shows_its_length_and_no_payment(): void
    {
        $vendor = $this->subscribedVendor();
        $subscription = $vendor->currentActiveSubscription();
        $subscription->forceFill(['free_trial_days' => 12])->save();

        app(PaymentService::class)->recordFreeTrial($subscription);

        Livewire::test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->assertSee('Free trial (12 days)')
            ->assertSee('No payment');
    }

    public function test_the_list_page_renders_every_vendor_regardless_of_status(): void
    {
        // The verification queue only ever shows pending_verification, which
        // is exactly the gap this resource exists to close.
        $draft = $this->vendor('draft');
        $active = $this->subscribedVendor();

        Livewire::test(ListVendors::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$draft, $active]);
    }

    public function test_the_list_shows_the_plan_for_a_subscribed_vendor(): void
    {
        $vendor = $this->subscribedVendor();

        Livewire::test(ListVendors::class)
            ->assertTableColumnStateSet('plan', 'Platinum', $vendor);
    }

    public function test_the_list_shows_no_plan_for_an_unsubscribed_vendor(): void
    {
        $vendor = $this->vendor('draft');

        Livewire::test(ListVendors::class)
            ->assertTableColumnStateSet('plan', null, $vendor);
    }

    public function test_the_not_subscribed_filter_excludes_subscribed_vendors(): void
    {
        $draft = $this->vendor('draft');
        $active = $this->subscribedVendor();

        Livewire::test(ListVendors::class)
            ->filterTable('unsubscribed')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_the_detail_page_shows_the_plan_quota_and_selected_items(): void
    {
        $vendor = $this->subscribedVendor();

        Livewire::test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->assertSuccessful()
            // Plan and quota, sourced from ActiveSubscriptionSummary so the
            // panel cannot disagree with the apps.
            ->assertSee('Platinum')
            ->assertSee('1 / 5')
            ->assertSee('1 / 10')
            ->assertSee('1 / 8')
            // The actual picks, by name — the whole point of the screen.
            ->assertSee('AC Service')
            ->assertSee('AC Installation')
            ->assertSee('Bodakdev');
    }

    /**
     * Both KYC files, not just the shop photo.
     *
     * The ID proof was uploaded by the salesman and stored all along, but
     * the infolist only ever rendered `id_proof_type` — so an admin
     * reviewing a vendor saw the word "aadhaar" and no document, which is
     * not something a verification decision can be made from.
     */
    public function test_the_detail_page_shows_both_kyc_documents(): void
    {
        $vendor = $this->vendor('pending_verification');

        $vendor->forceFill([
            'disk' => 'public',
            'shop_photo_path' => 'vendor-kyc/'.$vendor->id.'/shop.jpg',
            'id_proof_path' => 'vendor-kyc/'.$vendor->id.'/id.jpg',
            'id_proof_type' => 'aadhaar',
        ])->save();

        Livewire::test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->assertSuccessful()
            ->assertSee('Shop photo')
            ->assertSee('ID proof document')
            ->assertSee('aadhaar')
            // Each thumbnail links to the stored original — the rendered
            // size is far too small to verify a document from.
            ->assertSee(Storage::disk('public')->url($vendor->shop_photo_path), escape: false)
            ->assertSee(Storage::disk('public')->url($vendor->id_proof_path), escape: false);
    }

    /**
     * Neither entry may hard-fail when a draft was never given documents —
     * that is the normal state for most of the salesman flow.
     */
    public function test_the_detail_page_renders_with_no_kyc_documents(): void
    {
        $vendor = $this->vendor('draft');

        Livewire::test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->assertSuccessful()
            ->assertSee('Not provided');
    }

    public function test_the_detail_page_renders_for_a_vendor_with_no_subscription(): void
    {
        $vendor = $this->vendor('draft');

        // A null summary must read as "Not subscribed", not crash the page —
        // draft vendors are the common case in the salesman flow.
        Livewire::test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->assertSuccessful()
            ->assertSee('Not subscribed');
    }

    /**
     * This resource is deliberately read-only: creating a vendor is a
     * multi-table transaction owned by VendorDraftService, editing services
     * is quota-governed by SubscriptionService, and approval belongs to the
     * verification queue. Asserting the actions are ABSENT (rather than
     * present-but-disabled) is what fails loudly if one is ever re-added.
     */
    public function test_there_is_no_create_edit_or_delete(): void
    {
        $this->assertArrayNotHasKey('create', VendorResource::getPages());
        $this->assertArrayNotHasKey('edit', VendorResource::getPages());

        Livewire::test(ListVendors::class)
            ->assertTableActionDoesNotExist(\Filament\Tables\Actions\EditAction::class)
            ->assertTableActionDoesNotExist(\Filament\Tables\Actions\DeleteAction::class)
            ->assertTableBulkActionDoesNotExist(\Filament\Tables\Actions\DeleteBulkAction::class);
    }

    public function test_a_sub_admin_without_the_vendors_permission_cannot_access_it(): void
    {
        $this->actingAs(User::factory()->subAdmin(['categories.viewAny'])->create());

        $this->assertFalse(VendorResource::canAccess());
    }

    public function test_a_sub_admin_with_the_vendors_permission_can_access_it(): void
    {
        $this->actingAs(User::factory()->subAdmin(['vendors.viewAny'])->create());

        $this->assertTrue(VendorResource::canAccess());
    }
}
