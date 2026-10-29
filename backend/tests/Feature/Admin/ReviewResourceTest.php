<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\ReviewResource\Pages\ListReviews;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Review;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review Management (SPEC section 5 item 6, task 5.5).
 */
class ReviewResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    private function vendor(): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);
    }

    private function review(Vendor $vendor, array $overrides = [], ?Subcategory $subcategory = null): Review
    {
        $customerUser = User::factory()->role(UserRole::Customer)->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $subcategory ??= Subcategory::factory()->create();

        $lead = Lead::create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => 'call',
        ]);

        return Review::create(array_merge([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'rating' => 4,
            'comment' => 'Solid work',
        ], $overrides));
    }

    public function test_the_list_shows_both_visible_and_hidden_reviews(): void
    {
        $vendor = $this->vendor();
        $visible = $this->review($vendor);
        $hidden = $this->review($vendor, ['is_hidden' => true]);

        Livewire::test(ListReviews::class)
            ->assertCanSeeTableRecords([$visible, $hidden]);
    }

    public function test_filters_by_vendor_rating_category_subcategory_and_reply(): void
    {
        $coolAir = $this->vendor();
        $sparks = $this->vendor();

        $acRepair = Category::factory()->create();
        $gasFilling = Subcategory::factory()->for($acRepair)->create();
        $wiring = Subcategory::factory()->create();

        $fiveStar = $this->review($coolAir, ['rating' => 5, 'vendor_reply' => 'Thank you!'], $gasFilling);
        $oneStar = $this->review($coolAir, ['rating' => 1], $gasFilling);
        $other = $this->review($sparks, ['rating' => 4], $wiring);

        Livewire::test(ListReviews::class)
            ->filterTable('vendor_id', $sparks->id)
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$fiveStar, $oneStar]);

        Livewire::test(ListReviews::class)
            ->filterTable('rating', 1)
            ->assertCanSeeTableRecords([$oneStar])
            ->assertCanNotSeeTableRecords([$fiveStar, $other]);

        Livewire::test(ListReviews::class)
            ->filterTable('category', $acRepair->id)
            ->assertCanSeeTableRecords([$fiveStar, $oneStar])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListReviews::class)
            ->filterTable('subcategory', $wiring->id)
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$fiveStar, $oneStar]);

        Livewire::test(ListReviews::class)
            ->filterTable('replied', false)
            ->assertCanSeeTableRecords([$oneStar, $other])
            ->assertCanNotSeeTableRecords([$fiveStar]);
    }

    public function test_the_view_action_shows_the_whole_review(): void
    {
        $review = $this->review($this->vendor(), [
            'comment' => str_repeat('Long and detailed comment. ', 10),
            'vendor_reply' => 'Sorry about that — fixed the same day.',
        ]);

        Livewire::test(ListReviews::class)
            ->mountTableAction('view', $review)
            ->assertSee('Sorry about that — fixed the same day.')
            ->assertSee('Long and detailed comment.');
    }

    public function test_hide_flags_it_and_records_who_and_why(): void
    {
        $review = $this->review($this->vendor());

        Livewire::test(ListReviews::class)
            ->callTableAction('hide', $review, data: ['reason' => 'Suspected fake review']);

        $reloaded = $review->fresh();

        $this->assertTrue($reloaded->is_hidden);
        $this->assertSame($this->admin->id, $reloaded->hidden_by);
        $this->assertSame('Suspected fake review', $reloaded->hidden_reason);
    }

    public function test_hide_requires_a_reason(): void
    {
        $review = $this->review($this->vendor());

        Livewire::test(ListReviews::class)
            ->callTableAction('hide', $review, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertFalse($review->fresh()->is_hidden);
    }

    public function test_hide_recalculates_the_vendors_rating_aggregate(): void
    {
        $vendor = $this->vendor();
        $review = $this->review($vendor, ['rating' => 5]);
        $this->assertSame(1, $vendor->fresh()->rating_count);

        Livewire::test(ListReviews::class)
            ->callTableAction('hide', $review, data: ['reason' => 'Fake']);

        $this->assertSame(0, $vendor->fresh()->rating_count);
    }

    public function test_unhide_restores_it_and_clears_the_hide_metadata(): void
    {
        $review = $this->review($this->vendor(), [
            'is_hidden' => true,
            'hidden_by' => $this->admin->id,
            'hidden_reason' => 'Fake',
        ]);

        Livewire::test(ListReviews::class)
            ->callTableAction('unhide', $review);

        $reloaded = $review->fresh();

        $this->assertFalse($reloaded->is_hidden);
        $this->assertNull($reloaded->hidden_by);
        $this->assertNull($reloaded->hidden_reason);
    }

    public function test_hide_action_is_hidden_once_already_hidden(): void
    {
        $review = $this->review($this->vendor(), ['is_hidden' => true]);

        Livewire::test(ListReviews::class)
            ->assertTableActionHidden('hide', $review)
            ->assertTableActionVisible('unhide', $review);
    }

    public function test_no_create_action_is_registered(): void
    {
        Livewire::test(ListReviews::class)
            ->assertActionDoesNotExist('create');
    }

    public function test_a_sub_admin_without_the_hide_permission_cannot_act(): void
    {
        $review = $this->review($this->vendor());
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::ReviewsViewAny->value],
        ]);

        $this->actingAs($subAdmin);

        Livewire::test(ListReviews::class)
            ->assertTableActionHidden('hide', $review);
    }

    public function test_a_sub_admin_with_the_hide_permission_can_act(): void
    {
        $review = $this->review($this->vendor());
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::ReviewsViewAny->value, Permission::ReviewsHide->value],
        ]);

        $this->actingAs($subAdmin);

        Livewire::test(ListReviews::class)
            ->callTableAction('hide', $review, data: ['reason' => 'Fake']);

        $this->assertTrue($review->fresh()->is_hidden);
    }

    // ── Fraud-signal filter (SPEC section 5 item 6) ─────────────────────

    public function test_the_fraud_filter_surfaces_a_review_created_after_its_leads_30_day_window(): void
    {
        $vendor = $this->vendor();
        $normal = $this->review($vendor);

        $drifted = $this->review($vendor);
        $drifted->lead->forceFill(['created_at' => now()->subDays(45)])->save();

        Livewire::test(ListReviews::class)
            ->filterTable('lead_older_than_30_days')
            ->assertCanSeeTableRecords([$drifted])
            ->assertCanNotSeeTableRecords([$normal]);
    }
}
