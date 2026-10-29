<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\MediaGalleryResource;
use App\Filament\Resources\MediaGalleryResource\Pages\ListMediaGallery;
use App\Models\Category;
use App\Models\Media;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Media Gallery: every vendor portfolio upload, filterable by vendor,
 * type, category, subcategory and status, with Take down / Restore.
 */
class MediaGalleryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
    }

    private function vendor(string $name = 'Cool Air Services'): Vendor
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        return Vendor::create([
            'user_id' => $user->id,
            'business_name' => $name,
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);
    }

    private function media(Vendor $vendor, Subcategory $subcategory, string $type = 'image', string $status = 'approved'): Media
    {
        return $vendor->media()->create([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/'.$vendor->id.'/'.uniqid().($type === 'video' ? '.mp4' : '.jpg'),
            'type' => $type,
            'size_bytes' => 1234,
            'moderation_status' => $status,
        ]);
    }

    public function test_it_lists_every_upload_whatever_its_status(): void
    {
        $vendor = $this->vendor();
        $sub = Subcategory::factory()->create();

        $live = $this->media($vendor, $sub);
        $takenDown = $this->media($vendor, $sub, status: 'rejected');
        $video = $this->media($vendor, $sub, type: 'video');

        Livewire::test(ListMediaGallery::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$live, $takenDown, $video]);
    }

    public function test_filters_by_vendor_type_category_subcategory_and_status(): void
    {
        $coolAir = $this->vendor('Cool Air');
        $sparks = $this->vendor('Sparks Electric');

        $acRepair = Category::factory()->create();
        $gasFilling = Subcategory::factory()->for($acRepair)->create();
        $wiring = Subcategory::factory()->create();

        $photo = $this->media($coolAir, $gasFilling);
        $video = $this->media($coolAir, $gasFilling, type: 'video');
        $other = $this->media($sparks, $wiring);
        $removed = $this->media($sparks, $wiring, status: 'rejected');

        Livewire::test(ListMediaGallery::class)
            ->filterTable('vendor', $coolAir->id)
            ->assertCanSeeTableRecords([$photo, $video])
            ->assertCanNotSeeTableRecords([$other, $removed]);

        Livewire::test(ListMediaGallery::class)
            ->filterTable('type', 'video')
            ->assertCanSeeTableRecords([$video])
            ->assertCanNotSeeTableRecords([$photo, $other]);

        Livewire::test(ListMediaGallery::class)
            ->filterTable('category', $acRepair->id)
            ->assertCanSeeTableRecords([$photo, $video])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListMediaGallery::class)
            ->filterTable('subcategory_id', $wiring->id)
            ->assertCanSeeTableRecords([$other, $removed])
            ->assertCanNotSeeTableRecords([$photo]);

        Livewire::test(ListMediaGallery::class)
            ->filterTable('moderation_status', 'rejected')
            ->assertCanSeeTableRecords([$removed])
            ->assertCanNotSeeTableRecords([$photo, $other]);
    }

    public function test_take_down_hides_it_and_restore_brings_it_back(): void
    {
        $media = $this->media($this->vendor(), Subcategory::factory()->create());

        Livewire::test(ListMediaGallery::class)
            ->assertTableActionHidden('approve', $media)
            ->callTableAction('reject', $media, data: ['reason' => 'Not the vendor\'s own work.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('rejected', $media->fresh()->moderation_status);

        Livewire::test(ListMediaGallery::class)
            ->assertTableActionHidden('reject', $media)
            ->callTableAction('approve', $media)
            ->assertHasNoTableActionErrors();

        $this->assertSame('approved', $media->fresh()->moderation_status);
    }

    public function test_nothing_can_be_deleted_from_the_gallery(): void
    {
        $media = $this->media($this->vendor(), Subcategory::factory()->create());

        Livewire::test(ListMediaGallery::class)
            ->assertTableActionDoesNotExist(DeleteAction::class, record: $media);
    }

    public function test_view_only_access_cannot_take_anything_down(): void
    {
        $media = $this->media($this->vendor(), Subcategory::factory()->create());

        $this->actingAs(User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::MediaViewAny->value],
        ]));

        Livewire::test(ListMediaGallery::class)
            ->assertSuccessful()
            ->assertTableActionHidden('reject', $media);
    }

    public function test_an_admin_without_media_access_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(['permissions' => []]));

        $this->get(MediaGalleryResource::getUrl('index'))->assertForbidden();
    }
}
