<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\BannerResource;
use App\Filament\Resources\BannerResource\Pages\CreateBanner;
use App\Filament\Resources\BannerResource\Pages\EditBanner;
use App\Filament\Resources\BannerResource\Pages\ListBanners;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Banner Management (SPEC section 5 item 5).
 */
class BannerResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Every banner() fixture writes its fake image to this disk —
        // faked globally so no test accidentally touches the real
        // public disk regardless of whether it happens to render the
        // edit form.
        Storage::fake('public');

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    /**
     * Writes the fixture file onto the (fake, per-test) public disk
     * so the EditBanner form's FileUpload field can actually resolve
     * it as an existing file — a path string alone isn't enough: the
     * field checks the file is really there when hydrating existing
     * state, and `required()` reads a not-found file as unset.
     */
    private function banner(array $overrides = []): Banner
    {
        $path = $overrides['image_path'] ?? 'banners/diwali.jpg';
        Storage::disk('public')->put($path, 'fake-image-bytes');

        return Banner::create(array_merge([
            'target_app' => 'customer',
            'title' => 'Diwali Sale',
            'position' => 'home_top',
            'image_path' => $path,
            'is_active' => true,
        ], $overrides));
    }

    public function test_the_list_page_renders(): void
    {
        $this->banner();

        Livewire::test(ListBanners::class)->assertSuccessful();
    }

    public function test_a_banner_can_be_created_with_an_image_and_the_disk_is_stamped(): void
    {
        Livewire::test(CreateBanner::class)
            ->fillForm([
                'target_app' => 'vendor',
                'title' => ['en' => 'New Feature'],
                'position' => 'home_top',
                'image_path' => UploadedFile::fake()->image('banner.jpg'),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // title is stored per language, so match the English value.
        $banner = Banner::where('title->en', 'New Feature')->sole();
        $this->assertSame('vendor', $banner->target_app);
        $this->assertNotNull($banner->image_path);
        // TracksFileDisk stamps this on create — confirms the trait is
        // actually wired, not just present on the class.
        $this->assertNotNull($banner->disk);
    }

    public function test_target_app_is_required(): void
    {
        Livewire::test(CreateBanner::class)
            ->fillForm(['position' => 'home_top'])
            ->call('create')
            ->assertHasFormErrors(['target_app']);
    }

    public function test_a_banner_can_be_edited(): void
    {
        $banner = $this->banner();

        Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
            ->fillForm(['title' => ['en' => 'Updated Title']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Title', $banner->fresh()->title);
    }

    /**
     * click_count is a counter the public click endpoint owns, not
     * admin input — it isn't in the form schema at all, so submitting
     * the edit form can't move it even if a crafted payload tried.
     */
    public function test_click_count_is_not_editable_through_the_form(): void
    {
        $banner = $this->banner();
        $banner->increment('click_count', 5);

        Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
            ->fillForm(['title' => ['en' => 'Still Diwali Sale']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, $banner->fresh()->click_count);
    }

    /**
     * The point that distinguishes Banner from the master-data
     * resources: delete is real here, not omitted. Confirms the row
     * is actually gone — no SoftDeletes residue to check for either,
     * since Banner doesn't use the trait.
     */
    public function test_a_banner_can_actually_be_deleted(): void
    {
        $banner = $this->banner();

        Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
            ->callAction('delete');

        $this->assertDatabaseCount('banners', 0);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_a_sub_admin_without_the_banners_permission_cannot_access_the_resource(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create(['permissions' => []]);
        $this->actingAs($subAdmin);

        $this->get(BannerResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_sub_admin_with_view_only_cannot_delete(): void
    {
        $banner = $this->banner();

        // BannersUpdate, not just BannersViewAny: Filament's EditRecord
        // page requires the update ability just to mount at all (a
        // view-only sub-admin 403s on the page itself, before any
        // action could even be inspected) — granting update here is
        // what isolates the thing this test actually checks: that
        // update does NOT implicitly carry delete too.
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::BannersViewAny->value, Permission::BannersUpdate->value],
        ]);
        $this->actingAs($subAdmin);

        // Unlike Category/Zone (where DeleteAction is never added to
        // getHeaderActions() at all), Banner's is always registered
        // but conditionally ->visible() — so the correct assertion
        // here is "hidden", not "does not exist"; the action object
        // is genuinely there, just gated at render time.
        Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
            ->assertActionHidden('delete');
    }

    public function test_a_sub_admin_with_delete_can_delete(): void
    {
        $banner = $this->banner();

        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [
                Permission::BannersViewAny->value,
                Permission::BannersUpdate->value,
                Permission::BannersDelete->value,
            ],
        ]);
        $this->actingAs($subAdmin);

        Livewire::test(EditBanner::class, ['record' => $banner->getKey()])
            ->callAction('delete');

        $this->assertDatabaseCount('banners', 0);
    }
}
