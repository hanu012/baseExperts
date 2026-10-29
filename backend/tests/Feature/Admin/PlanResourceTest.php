<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\PlanResource;
use App\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Models\Plan;
use App\Models\User;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PlanResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function validForm(array $overrides = []): array
    {
        // Quota fields sit under a `quota` state path: Fieldset->relationship()
        // calls statePath($name), so they are nested rather than flat.
        return array_merge([
            'name' => ['en' => 'Growth'],
            'slug' => 'growth',
            'price_rupees' => '999.00',
            'duration_days' => 365,
            'is_active' => true,
            'quota' => [
                'max_categories' => 5,
                'max_subcategories' => 15,
                'max_zones' => 3,
                'max_photos' => 20,
                'max_videos' => 2,
                'priority_rank' => 3,
            ],
        ], $overrides);
    }

    private function subscriptionOn(Plan $plan, string $status = 'active', bool $trashed = false): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'V', 'email' => 'v'.Str::random(8).'@example.test',
            'password' => bcrypt('secret-password'), 'role' => 'vendor',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vendorId = DB::table('vendors')->insertGetId([
            'user_id' => $userId, 'business_name' => 'V', 'owner_name' => 'V',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('subscriptions')->insert([
            'vendor_id' => $vendorId, 'plan_id' => $plan->id,
            'source' => 'self', 'status' => $status,
            'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'price_paise' => $plan->price_paise, 'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
            'deleted_at' => $trashed ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_list_page_renders(): void
    {
        Plan::factory()->count(3)->create();

        Livewire::test(ListPlans::class)->assertSuccessful();
    }

    /**
     * Not SPEC section 10 — that covers categories/subcategories/zones and
     * their missing foreign key. Here it is subscriptions.plan_id being
     * ON DELETE RESTRICT: the database refuses, so offering the action would
     * only surface a raw integrity error.
     */
    public function test_no_delete_action_is_registered(): void
    {
        $plan = Plan::factory()->create();

        Livewire::test(ListPlans::class)
            ->assertTableActionDoesNotExist(DeleteAction::class, record: $plan)
            ->assertTableBulkActionDoesNotExist(DeleteBulkAction::class);
    }

    public function test_the_edit_page_offers_no_delete_either(): void
    {
        $plan = Plan::factory()->create();

        Livewire::test(EditPlan::class, ['record' => $plan->getKey()])
            ->assertSuccessful()
            ->assertActionDoesNotExist('delete');
    }

    public function test_change_status_flips_the_record(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        Livewire::test(ListPlans::class)
            ->assertTableColumnFormattedStateSet('is_active', 'Active', $plan)
            ->callTableAction('changeStatus', $plan, data: [
                'is_active' => '0',
                'reason' => 'Replaced by the 2027 pricing.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($plan->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'status_changed',
            'auditable_id' => $plan->getKey(),
        ]);
    }

    public function test_reordering_writes_sort_order(): void
    {
        $a = Plan::factory()->sortedAt(1)->create();
        $b = Plan::factory()->sortedAt(2)->create();

        Livewire::test(ListPlans::class)
            ->call('reorderTable', [$b->getKey(), $a->getKey()]);

        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
    }

    public function test_a_plan_and_its_quota_save_together(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm($this->validForm())
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::where('slug', 'growth')->firstOrFail();

        $this->assertNotNull($plan->quota);
        $this->assertSame(5, $plan->quota->max_categories);
        $this->assertSame(15, $plan->quota->max_subcategories);
        $this->assertSame(3, $plan->quota->max_zones);
        $this->assertSame(20, $plan->quota->max_photos);
        $this->assertSame(2, $plan->quota->max_videos);
        $this->assertSame(3, $plan->quota->priority_rank);
    }

    /**
     * Add-on pricing (task 4.7) lives in a SEPARATE Fieldset from the
     * quota limits above, both mapped to the same `quota`
     * relationship() — this proves the two don't fight over the save
     * (one Fieldset's write clobbering the other's).
     */
    public function test_addon_pricing_saves_alongside_the_quota_limits(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm($this->validForm(['quota' => array_merge($this->validForm()['quota'], [
                'addon_price_per_category_paise' => 500,
                'addon_price_per_subcategory_paise' => 300,
                'addon_price_per_zone_paise' => 400,
                'addon_price_per_photo_paise' => 100,
                'addon_price_per_video_paise' => 200,
            ])]))
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::where('slug', 'growth')->firstOrFail();

        // The limits from the OTHER fieldset are still intact.
        $this->assertSame(5, $plan->quota->max_categories);
        $this->assertSame(500, $plan->quota->addon_price_per_category_paise);
        $this->assertSame(300, $plan->quota->addon_price_per_subcategory_paise);
        $this->assertSame(400, $plan->quota->addon_price_per_zone_paise);
        $this->assertSame(100, $plan->quota->addon_price_per_photo_paise);
        $this->assertSame(200, $plan->quota->addon_price_per_video_paise);
    }

    public function test_the_price_is_entered_in_rupees_and_stored_in_paise(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm($this->validForm(['price_rupees' => '999.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(99900, Plan::where('slug', 'growth')->firstOrFail()->price_paise);
    }

    public function test_editing_shows_the_price_back_in_rupees(): void
    {
        $plan = Plan::factory()->create(['price_paise' => 149950]);

        Livewire::test(EditPlan::class, ['record' => $plan->getKey()])
            ->assertFormSet(['price_rupees' => '1499.50']);
    }

    public function test_the_quota_can_be_edited_through_the_plan_form(): void
    {
        $plan = Plan::factory()->create();

        Livewire::test(EditPlan::class, ['record' => $plan->getKey()])
            ->fillForm(['quota' => ['max_zones' => 42]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(42, $plan->fresh()->quota->max_zones);
    }

    public function test_in_use_shows_not_used_for_a_fresh_plan(): void
    {
        $plan = Plan::factory()->create();

        $this->assertSame('Not used', PlanResource::inUseLabel($plan));
    }

    public function test_in_use_shows_only_active_when_history_matches(): void
    {
        $plan = Plan::factory()->create();
        $this->subscriptionOn($plan, 'active');
        $this->subscriptionOn($plan, 'active');

        $this->assertSame('2 active', PlanResource::inUseLabel($plan->fresh()));
    }

    /**
     * The case that matters: ON DELETE RESTRICT blocks on every subscription
     * ever, so a plan reading "0 active" can still be undeletable.
     */
    public function test_in_use_surfaces_history_when_it_differs_from_active(): void
    {
        $plan = Plan::factory()->create();
        $this->subscriptionOn($plan, 'expired');
        $this->subscriptionOn($plan, 'expired');
        $this->subscriptionOn($plan, 'active');

        $this->assertSame('1 active / 3 all time', PlanResource::inUseLabel($plan->fresh()));
    }

    public function test_soft_deleted_subscriptions_still_count_toward_history(): void
    {
        // They keep the foreign key row, so RESTRICT still fires on them.
        $plan = Plan::factory()->create();
        $this->subscriptionOn($plan, 'cancelled', trashed: true);

        $this->assertSame('0 active / 1 all time', PlanResource::inUseLabel($plan->fresh()));
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        Plan::factory()->create(['slug' => 'growth']);

        Livewire::test(CreatePlan::class)
            ->fillForm($this->validForm())
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_non_admin_cannot_reach_the_resource(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Vendor)->create());

        $this->get(PlanResource::getUrl('index'))->assertForbidden();
    }
}
