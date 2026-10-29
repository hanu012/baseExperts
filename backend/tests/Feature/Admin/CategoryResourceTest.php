<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\User;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    public function test_the_list_page_renders(): void
    {
        Category::factory()->count(3)->create();

        Livewire::test(ListCategories::class)->assertSuccessful();
    }

    public function test_categories_are_listed_in_sort_order(): void
    {
        $third = Category::factory()->sortedAt(30)->create();
        $first = Category::factory()->sortedAt(10)->create();
        $second = Category::factory()->sortedAt(20)->create();

        Livewire::test(ListCategories::class)
            ->assertCanSeeTableRecords([$first, $second, $third], inOrder: true);
    }

    public function test_an_unused_category_can_be_deleted_from_the_list_and_the_edit_page(): void
    {
        $listed = Category::factory()->create();
        $edited = Category::factory()->create();

        Livewire::test(ListCategories::class)
            ->callTableAction(DeleteAction::class, $listed)
            ->assertHasNoTableActionErrors();

        Livewire::test(EditCategory::class, ['record' => $edited->getKey()])
            ->callAction('delete');

        $this->assertModelMissing($listed);
        $this->assertModelMissing($edited);
    }

    /**
     * Subcategories cascade in the database, so deleting the parent would
     * take them with it unseen. The edit page is checked as well as the
     * table — it is a separate registration, and once leaked a bare delete.
     */
    public function test_a_category_with_subcategories_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->for($category)->create();

        Livewire::test(ListCategories::class)
            ->assertTableActionDisabled(DeleteAction::class, $category)
            ->callTableAction(DeleteAction::class, $category);

        Livewire::test(EditCategory::class, ['record' => $category->getKey()])
            ->assertActionDisabled('delete')
            ->callAction('delete');

        $this->assertModelExists($category);
    }

    /** One record at a time, so each gets its own checks. */
    public function test_there_is_no_bulk_delete(): void
    {
        Livewire::test(ListCategories::class)
            ->assertTableBulkActionDoesNotExist(DeleteBulkAction::class);
    }

    public function test_delete_needs_the_categories_delete_permission(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->subAdmin(['categories.viewAny', 'categories.update'])->create());

        Livewire::test(ListCategories::class)
            ->assertTableActionHidden(DeleteAction::class, $category);
    }

    public function test_change_status_flips_the_record_and_records_the_reason(): void
    {
        $category = Category::factory()->create(['is_active' => true]);

        Livewire::test(ListCategories::class)
            ->callTableAction('changeStatus', $category, data: [
                'is_active' => '0',
                'reason' => 'Seasonal service, off until summer.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($category->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'status_changed',
            'auditable_type' => $category->getMorphClass(),
            'auditable_id' => $category->getKey(),
            'user_id' => auth()->id(),
        ]);
        $this->assertSame(
            'Seasonal service, off until summer.',
            \App\Models\AuditLog::query()->for($category)->where('action', 'status_changed')->sole()->new_values['reason'],
        );
    }

    public function test_change_status_requires_a_reason(): void
    {
        $category = Category::factory()->create(['is_active' => true]);

        Livewire::test(ListCategories::class)
            ->callTableAction('changeStatus', $category, data: ['is_active' => '0', 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertTrue($category->fresh()->is_active);
    }

    /**
     * The one-click toggle is gone from the list on purpose: status only
     * changes through the reasoned action, so it cannot be flipped by a
     * stray click.
     */
    public function test_status_is_a_badge_not_a_toggle(): void
    {
        $category = Category::factory()->create(['is_active' => true]);

        Livewire::test(ListCategories::class)
            ->assertTableColumnFormattedStateSet('is_active', 'Active', $category)
            ->assertTableActionExists('changeStatus')
            ->assertTableActionExists('view');
    }

    public function test_the_view_modal_opens(): void
    {
        $category = Category::factory()->create();

        Livewire::test(ListCategories::class)
            ->mountTableAction('view', $category)
            ->assertHasNoTableActionErrors();
    }

    public function test_the_edit_page_offers_change_status_and_no_active_field(): void
    {
        $category = Category::factory()->create(['is_active' => true]);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->assertActionExists('changeStatus')
            ->assertFormFieldIsHidden('is_active')
            ->callAction('changeStatus', data: ['is_active' => '0', 'reason' => 'Retired'])
            ->assertHasNoActionErrors();

        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_tabs_split_active_and_inactive(): void
    {
        $active = Category::factory()->create(['is_active' => true]);
        $inactive = Category::factory()->create(['is_active' => false]);

        Livewire::test(ListCategories::class)
            ->set('activeTab', 'inactive')
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_reordering_writes_sort_order(): void
    {
        $a = Category::factory()->sortedAt(1)->create();
        $b = Category::factory()->sortedAt(2)->create();
        $c = Category::factory()->sortedAt(3)->create();

        // reorderTable is a component method, not a chainable test helper.
        Livewire::test(ListCategories::class)
            ->call('reorderTable', [$c->getKey(), $a->getKey(), $b->getKey()]);

        $this->assertSame(1, $c->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(3, $b->fresh()->sort_order);
    }

    public function test_a_category_can_be_created(): void
    {
        Livewire::test(CategoryResource\Pages\CreateCategory::class)
            ->fillForm([
                'name' => ['en' => 'Pest Control'],
                'slug' => 'pest-control',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('categories', ['slug' => 'pest-control']);
    }

    public function test_a_category_is_saved_in_every_language(): void
    {
        Livewire::test(CategoryResource\Pages\CreateCategory::class)
            ->fillForm([
                'name' => ['en' => 'Pest Control', 'hi' => 'कीट नियंत्रण', 'gu' => 'જંતુ નિયંત્રણ'],
                'slug' => 'pest-control',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::where('slug', 'pest-control')->sole();

        $this->assertSame('Pest Control', $category->getTranslation('name', 'en'));
        $this->assertSame('कीट नियंत्रण', $category->getTranslation('name', 'hi'));
        $this->assertSame('જંતુ નિયંત્રણ', $category->getTranslation('name', 'gu'));
    }

    /**
     * The edit form is filled from raw attributes, where the column is the
     * stored JSON string — each language has to be split back out or the
     * inputs open empty and the next save wipes the translations.
     */
    public function test_the_edit_form_opens_with_each_language_filled_in(): void
    {
        $category = Category::factory()->create([
            'name' => ['en' => 'Plumbing', 'hi' => 'प्लंबिंग'],
        ]);

        Livewire::test(EditCategory::class, ['record' => $category->getKey()])
            ->assertFormSet([
                'name.en' => 'Plumbing',
                'name.hi' => 'प्लंबिंग',
            ])
            ->fillForm(['name' => ['en' => 'Plumbing', 'hi' => 'प्लंबिंग', 'gu' => 'પ્લમ્બિંગ']])
            ->call('save')
            ->assertHasNoFormErrors();

        $category->refresh();
        $this->assertSame('प्लंबिंग', $category->getTranslation('name', 'hi'));
        $this->assertSame('પ્લમ્બિંગ', $category->getTranslation('name', 'gu'));
    }

    public function test_english_is_required_but_other_languages_are_optional(): void
    {
        Livewire::test(CategoryResource\Pages\CreateCategory::class)
            ->fillForm(['name' => ['hi' => 'कीट नियंत्रण'], 'slug' => 'pest'])
            ->call('create')
            ->assertHasFormErrors(['name.en' => 'required']);
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        Category::factory()->create(['slug' => 'plumbing']);

        Livewire::test(CategoryResource\Pages\CreateCategory::class)
            ->fillForm([
                'name' => ['en' => 'Plumbing'],
                'slug' => 'plumbing',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_non_admin_cannot_reach_the_resource(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Vendor)->create());

        $this->get(CategoryResource::getUrl('index'))->assertForbidden();
    }
}
