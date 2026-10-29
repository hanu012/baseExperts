<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\CmsPageResource;
use App\Filament\Resources\CmsPageResource\Pages\CreateCmsPage;
use App\Filament\Resources\CmsPageResource\Pages\EditCmsPage;
use App\Filament\Resources\CmsPageResource\Pages\ListCmsPages;
use App\Models\CmsPage;
use App\Models\User;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CMS Pages (SPEC section 5 item 13).
 */
class CmsPageResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    private function page(array $overrides = []): CmsPage
    {
        return CmsPage::create(array_merge([
            'slug' => 'terms',
            'title' => 'Terms of Service',
            'body' => '# Terms',
            'is_published' => false,
        ], $overrides));
    }

    public function test_the_list_page_renders(): void
    {
        $this->page();

        Livewire::test(ListCmsPages::class)->assertSuccessful();
    }

    public function test_a_page_can_be_created_and_updated_by_is_stamped(): void
    {
        Livewire::test(CreateCmsPage::class)
            ->fillForm([
                'slug' => 'faq',
                'title' => ['en' => 'FAQ'],
                'body' => ['en' => '# FAQ'],
                'is_published' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = CmsPage::where('slug', 'faq')->sole();
        $this->assertSame($this->admin->id, $page->updated_by);
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        $this->page(['slug' => 'privacy-policy']);

        Livewire::test(CreateCmsPage::class)
            ->fillForm([
                'slug' => 'privacy-policy',
                'title' => ['en' => 'Privacy'],
                'body' => ['en' => '# Privacy'],
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_publishing_a_page_stamps_published_at(): void
    {
        $page = $this->page(['is_published' => false, 'published_at' => null]);

        Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
            ->fillForm(['is_published' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($page->fresh()->published_at);
        $this->assertSame($this->admin->id, $page->fresh()->updated_by);
    }

    public function test_updating_an_already_published_page_does_not_move_published_at(): void
    {
        $page = $this->page(['is_published' => true, 'published_at' => now()->subDays(5)]);
        $originalPublishedAt = $page->published_at;

        Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => ['en' => 'Terms of Service (Updated)']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($originalPublishedAt->equalTo($page->fresh()->published_at));
    }

    /**
     * SPEC section 10's referential-integrity reason doesn't apply
     * here, but the outcome is the same: no delete action, anywhere
     * on the resource — table actions or the Edit page's own header
     * actions (the two-place check the Category bug taught us to
     * make; see CategoryResourceTest for the original discovery).
     */
    public function test_no_delete_action_is_registered_on_the_table(): void
    {
        $page = $this->page();

        Livewire::test(ListCmsPages::class)
            ->assertTableActionDoesNotExist(DeleteAction::class, record: $page)
            ->assertTableBulkActionDoesNotExist(DeleteBulkAction::class);
    }

    public function test_no_delete_action_is_registered_on_the_edit_page(): void
    {
        $page = $this->page();

        Livewire::test(EditCmsPage::class, ['record' => $page->getKey()])
            ->assertActionDoesNotExist(DeleteAction::class);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_a_sub_admin_without_the_pages_permission_cannot_access_the_resource(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create(['permissions' => []]);
        $this->actingAs($subAdmin);

        $this->get(CmsPageResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_sub_admin_with_the_pages_permission_can_access_the_resource(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::PagesViewAny->value],
        ]);
        $this->actingAs($subAdmin);

        $this->get(CmsPageResource::getUrl('index'))->assertOk();
    }
}
