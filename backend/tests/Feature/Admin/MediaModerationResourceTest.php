<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\MediaModerationResource\Pages\ListMediaModerations;
use App\Models\Media;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Media Moderation Queue (SPEC section 5.10, task 4.5).
 */
class MediaModerationResourceTest extends TestCase
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

    private function media(Vendor $vendor, string $status = 'pending', array $overrides = []): Media
    {
        $subcategory = Subcategory::factory()->create();

        return $vendor->media()->create(array_merge([
            'subcategory_id' => $subcategory->id,
            'disk' => 'public',
            'path' => 'vendor-portfolio/'.$vendor->id.'/photo.jpg',
            'type' => 'image',
            'size_bytes' => 12345,
            'moderation_status' => $status,
        ], $overrides));
    }

    public function test_the_list_only_shows_pending_media(): void
    {
        $vendor = $this->vendor();
        $pending = $this->media($vendor, 'pending');
        $this->media($vendor, 'approved');
        $this->media($vendor, 'rejected');

        Livewire::test(ListMediaModerations::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([]);
    }

    public function test_approve_marks_it_approved_and_records_who_and_when(): void
    {
        $media = $this->media($this->vendor());

        Livewire::test(ListMediaModerations::class)
            ->callTableAction('approve', $media);

        $reloaded = $media->fresh();

        $this->assertSame('approved', $reloaded->moderation_status);
        $this->assertNotNull($reloaded->moderated_at);
        $this->assertSame($this->admin->id, $reloaded->moderated_by);
        $this->assertNull($reloaded->rejection_reason);
    }

    public function test_approved_media_no_longer_appears_in_the_queue(): void
    {
        $media = $this->media($this->vendor());

        Livewire::test(ListMediaModerations::class)
            ->callTableAction('approve', $media);

        Livewire::test(ListMediaModerations::class)
            ->assertCanNotSeeTableRecords([$media->fresh()]);
    }

    public function test_reject_requires_a_reason(): void
    {
        $media = $this->media($this->vendor());

        Livewire::test(ListMediaModerations::class)
            ->callTableAction('reject', $media, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame('pending', $media->fresh()->moderation_status);
    }

    public function test_reject_stores_the_reason_and_a_distinct_status(): void
    {
        $media = $this->media($this->vendor());

        Livewire::test(ListMediaModerations::class)
            ->callTableAction('reject', $media, data: ['reason' => 'Blurry photo']);

        $reloaded = $media->fresh();

        $this->assertSame('rejected', $reloaded->moderation_status);
        $this->assertSame('Blurry photo', $reloaded->rejection_reason);
        $this->assertSame($this->admin->id, $reloaded->moderated_by);
    }

    /**
     * SPEC section 5.10: media isn't created or deleted through this
     * queue, only transitioned.
     */
    public function test_no_create_action_is_registered(): void
    {
        Livewire::test(ListMediaModerations::class)
            ->assertActionDoesNotExist('create');
    }

    public function test_a_sub_admin_without_the_moderate_permission_cannot_act(): void
    {
        $media = $this->media($this->vendor());
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::MediaViewAny->value],
        ]);

        $this->actingAs($subAdmin);

        Livewire::test(ListMediaModerations::class)
            ->assertTableActionHidden('approve', $media)
            ->assertTableActionHidden('reject', $media);
    }

    public function test_a_sub_admin_with_the_moderate_permission_can_act(): void
    {
        $media = $this->media($this->vendor());
        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::MediaViewAny->value, Permission::MediaModerate->value],
        ]);

        $this->actingAs($subAdmin);

        Livewire::test(ListMediaModerations::class)
            ->callTableAction('approve', $media);

        $this->assertSame('approved', $media->fresh()->moderation_status);
    }
}
