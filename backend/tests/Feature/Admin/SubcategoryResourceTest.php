<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\SubcategoryResource;
use App\Filament\Resources\SubcategoryResource\Pages\CreateSubcategory;
use App\Filament\Resources\SubcategoryResource\Pages\EditSubcategory;
use App\Filament\Resources\SubcategoryResource\Pages\ListSubcategories;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\User;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubcategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    public function test_the_list_page_renders(): void
    {
        Subcategory::factory()->count(3)->create();

        Livewire::test(ListSubcategories::class)->assertSuccessful();
    }

    public function test_an_unused_subcategory_can_be_deleted(): void
    {
        $listed = Subcategory::factory()->create();
        $edited = Subcategory::factory()->create();

        Livewire::test(ListSubcategories::class)
            ->assertTableBulkActionDoesNotExist(DeleteBulkAction::class)
            ->callTableAction(DeleteAction::class, $listed)
            ->assertHasNoTableActionErrors();

        Livewire::test(EditSubcategory::class, ['record' => $edited->getKey()])
            ->callAction('delete');

        $this->assertModelMissing($listed);
        $this->assertModelMissing($edited);
    }

    /** subscription_items.item_id has no foreign key — the row would be orphaned. */
    public function test_a_subscribed_subcategory_cannot_be_deleted(): void
    {
        $subcategory = Subcategory::factory()->create();

        $vendor = \App\Models\Vendor::create([
            'user_id' => User::factory()->role(UserRole::Vendor)->create()->id,
            'business_name' => 'Cool Air',
            'owner_name' => 'Asha Patel',
            'phone' => '9812345672',
            'status' => 'active',
        ]);
        $plan = \App\Models\Plan::factory()->create();
        $subscription = \App\Models\Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        \App\Models\SubscriptionItem::insert([
            ['subscription_id' => $subscription->id, 'item_type' => 'subcategory', 'item_id' => $subcategory->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Livewire::test(ListSubcategories::class)
            ->assertTableActionDisabled(DeleteAction::class, $subcategory)
            ->callTableAction(DeleteAction::class, $subcategory);

        $this->assertModelExists($subcategory);
    }

    public function test_the_category_filter_shows_only_that_categorys_subcategories(): void
    {
        $category = Category::factory()->create();
        $mine = Subcategory::factory()->for($category)->create();
        $theirs = Subcategory::factory()->for(Category::factory()->create())->create();

        Livewire::test(ListSubcategories::class)
            ->filterTable('category_id', $category->getKey())
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_subcategory_can_be_created_under_a_chosen_category(): void
    {
        $category = Category::factory()->create();

        Livewire::test(CreateSubcategory::class)
            ->fillForm([
                'name' => ['en' => 'Gas Filling'],
                'category_id' => $category->getKey(),
                'slug' => 'gas-filling',
                'sort_order' => 4,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('subcategories', [
            'category_id' => $category->getKey(),
            'slug' => 'gas-filling',
            'sort_order' => 4,
        ]);
    }

    public function test_category_is_required(): void
    {
        Livewire::test(CreateSubcategory::class)
            ->fillForm(['name' => ['en' => 'Gas Filling'], 'slug' => 'gas-filling'])
            ->call('create')
            ->assertHasFormErrors(['category_id' => 'required']);
    }

    public function test_the_same_slug_is_allowed_in_another_category_but_not_the_same_one(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->for($category)->create(['slug' => 'repair']);

        Livewire::test(CreateSubcategory::class)
            ->fillForm(['name' => ['en' => 'Repair'], 'category_id' => $category->getKey(), 'slug' => 'repair'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreateSubcategory::class)
            ->fillForm(['name' => ['en' => 'Repair'], 'category_id' => Category::factory()->create()->getKey(), 'slug' => 'repair'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_change_status_works_and_the_edit_form_hides_active(): void
    {
        $subcategory = Subcategory::factory()->create(['is_active' => true]);

        Livewire::test(EditSubcategory::class, ['record' => $subcategory->getKey()])
            ->assertFormFieldIsHidden('is_active')
            ->callAction('changeStatus', data: ['is_active' => '0', 'reason' => 'Retired'])
            ->assertHasNoActionErrors();

        $this->assertFalse($subcategory->fresh()->is_active);
    }

    public function test_a_non_admin_cannot_reach_the_resource(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Vendor)->create());

        $this->get(SubcategoryResource::getUrl('index'))->assertForbidden();
    }
}
