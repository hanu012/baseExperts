<?php

namespace Tests\Feature\Schema;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The `disk` column is what makes the eventual move to Cloudflare R2
 * row-at-a-time and re-runnable instead of all-or-nothing. These tests pin
 * the behaviour it depends on.
 */
class FileDiskTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_category_records_the_current_upload_disk(): void
    {
        $category = Category::create(['name' => 'AC Repair', 'slug' => 'ac-repair']);

        $this->assertSame(Category::currentUploadDisk(), $category->disk);
    }

    public function test_a_new_subcategory_records_the_current_upload_disk(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::create([
            'category_id' => $category->id,
            'name' => 'Gas Filling',
            'slug' => 'gas-filling',
        ]);

        $this->assertSame(Subcategory::currentUploadDisk(), $subcategory->disk);
    }

    public function test_the_upload_disk_is_filaments_not_the_filesystem_default(): void
    {
        // These genuinely differ in this project — filesystems.default is
        // `local` while Filament writes to `public`. Recording the wrong one
        // would point every row at a directory the files were never in.
        config()->set('filament.default_filesystem_disk', 'public');
        config()->set('filesystems.default', 'local');

        $this->assertSame('public', Category::currentUploadDisk());
    }

    public function test_an_explicit_disk_is_not_overwritten(): void
    {
        // Rows migrated to R2 must keep their disk when saved again.
        $category = Category::create([
            'name' => 'Plumbing',
            'slug' => 'plumbing',
            'disk' => 's3',
        ]);

        $this->assertSame('s3', $category->fresh()->disk);
    }

    public function test_adding_a_file_later_stamps_the_disk(): void
    {
        $category = Category::factory()->create();
        DB::table('categories')->where('id', $category->id)->update(['disk' => null]);

        $category = $category->fresh();
        $category->icon = 'category-icons/late.png';
        $category->save();

        $this->assertSame(Category::currentUploadDisk(), $category->fresh()->disk);
    }

    public function test_the_icon_url_resolves_through_the_rows_own_disk(): void
    {
        $category = Category::factory()->create([
            'icon' => 'category-icons/ac.png',
            'disk' => 'public',
        ]);

        $url = $category->fileUrl();

        $this->assertNotNull($url);
        $this->assertStringContainsString('category-icons/ac.png', $url);
    }

    public function test_a_row_with_no_file_has_no_url(): void
    {
        $category = Category::factory()->create(['icon' => null]);

        $this->assertNull($category->fileUrl());
    }

    public function test_the_api_exposes_a_null_icon_url_when_there_is_no_icon(): void
    {
        Category::factory()->create(['icon' => null]);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.icon_url', null);
    }

    // Multi-file models. Vendor carries two file paths against one disk
    // column, and Phase 4's vendor self-service KYC will hit the same trait.

    private function vendorWithNullDisk(): \App\Models\Vendor
    {
        $user = \App\Models\User::factory()->role(\App\Enums\UserRole::Vendor)->create();

        $vendor = \App\Models\Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air',
            'owner_name' => 'Bhavin',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'draft',
        ]);

        // The trait stamps disk on create, so it has to be cleared for these
        // tests to exercise the update path at all. Without this they pass
        // regardless of whether multi-column stamping works.
        \App\Models\Vendor::whereKey($vendor->id)->update(['disk' => null]);

        return $vendor->fresh();
    }

    public function test_the_primary_file_column_stamps_the_disk(): void
    {
        $vendor = $this->vendorWithNullDisk();

        $vendor->shop_photo_path = 'vendor-kyc/1/shop.jpg';
        $vendor->save();

        $this->assertSame(
            \App\Models\Vendor::currentUploadDisk(),
            $vendor->fresh()->disk
        );
    }

    /**
     * The case a single-column trait would miss: only the SECONDARY file is
     * uploaded. An unstamped row is invisible to the R2 migration, which
     * selects on rows whose disk is not yet the R2 disk — so the file would
     * silently never move.
     */
    public function test_a_secondary_file_column_also_stamps_the_disk(): void
    {
        $vendor = $this->vendorWithNullDisk();

        $vendor->id_proof_path = 'vendor-kyc/1/pan.jpg';
        $vendor->id_proof_type = 'pan';
        $vendor->save();

        $this->assertNull($vendor->fresh()->shop_photo_path);
        $this->assertSame(
            \App\Models\Vendor::currentUploadDisk(),
            $vendor->fresh()->disk
        );
    }

    public function test_a_save_touching_no_file_column_leaves_the_disk_alone(): void
    {
        // Otherwise every unrelated edit would claim a storage location for
        // files the row does not have.
        $vendor = $this->vendorWithNullDisk();

        $vendor->address = 'changed';
        $vendor->save();

        $this->assertNull($vendor->fresh()->disk);
    }

    public function test_vendor_declares_both_of_its_file_columns(): void
    {
        // The declaration IS the mechanism — there is no bespoke boot hook.
        // A multi-file model that forgets this is the bug being prevented.
        $columns = (new \App\Models\Vendor)->fileDiskPathColumns();

        $this->assertContains('shop_photo_path', $columns);
        $this->assertContains('id_proof_path', $columns);
    }

    public function test_single_file_models_need_no_declaration(): void
    {
        // The default keeps Category and Subcategory working untouched.
        $this->assertSame(['icon'], (new Category)->fileDiskPathColumns());
        $this->assertSame(['icon'], (new Subcategory)->fileDiskPathColumns());
    }

    public function test_the_migration_backfilled_existing_rows(): void
    {
        // RefreshDatabase re-runs the migration, so any row inserted beneath
        // the model layer still ends up with a disk once stamped. This guards
        // the backfill's intent: no row that owns a file is left unlabelled.
        $category = Category::factory()->create(['icon' => 'category-icons/x.png']);

        $this->assertNotNull($category->disk);
        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
            'disk' => null,
        ]);
    }
}
