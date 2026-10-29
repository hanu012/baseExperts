<?php

namespace Tests\Feature\Api;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Creating a vendor in Draft from the salesman app (SPEC section 2.2).
 */
class VendorDraftTest extends TestCase
{
    use RefreshDatabase;

    private function salesmanUser(string $code = 'EMP-1', string $phone = '9900000001'): User
    {
        $user = User::factory()->role(UserRole::Salesman)->create([
            'must_change_password' => false,
        ]);

        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => $code,
            'phone' => $phone,
        ]);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Bhavin Patel',
            'phone' => '9812345678',
            'email' => 'bhavin@example.com',
            'address' => '12 Gota Road, Ahmedabad',
            'latitude' => 23.102,
            'longitude' => 72.545,
        ], $overrides);
    }

    public function test_a_salesman_can_create_a_draft(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');

        $response = $this->postJson('/api/vendors/draft', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.resumed', false)
            ->assertJsonPath('data.vendor.status', 'draft');

        // The id is the point: the app stores it to resume.
        $this->assertIsInt($response->json('data.vendor.id'));
        $this->assertDatabaseHas('vendors', [
            'phone' => '9812345678',
            'status' => 'draft',
        ]);
    }

    public function test_the_draft_is_linked_to_a_new_vendor_user(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');

        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $user = User::where('email', 'bhavin@example.com')->sole();

        $this->assertSame(UserRole::Vendor, $user->role);
        // Pending until an admin approves, once the salesman-uploaded KYC
        // documents are verified (VendorKycService, decided 2026-10-28) —
        // no longer approved on the strength of the visit alone.
        $this->assertFalse($user->isApproved());
        $this->assertSame(ApprovalStatus::Pending, $user->approval_status);
        // Whatever password they are eventually given, they did not choose it.
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->vendor);
    }

    public function test_the_draft_records_who_created_it(): void
    {
        $salesman = $this->salesmanUser();
        $this->actingAs($salesman, 'sanctum');

        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $this->assertSame(
            $salesman->salesman->id,
            Vendor::sole()->created_by_salesman_id
        );
    }

    public function test_the_vendor_cannot_sign_in_yet(): void
    {
        // SPEC section 2.2 generates the shareable temp password at Subscribe.
        // A vendor who never completes payment must not be left holding
        // working credentials to a Draft account.
        $this->actingAs($this->salesmanUser(), 'sanctum');
        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'bhavin@example.com',
            'password' => 'password',
            'device_name' => 'pixel-8',
        ])->assertStatus(401);
    }

    // Duplicate handling.

    public function test_a_duplicate_phone_is_rejected(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');
        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $this->actingAs($this->salesmanUser('EMP-2', '9900000002'), 'sanctum');

        $this->postJson('/api/vendors/draft', $this->payload([
            'email' => 'someone-else@example.com',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VENDOR_ALREADY_EXISTS')
            ->assertJsonPath('error.fields.phone.0', 'This phone number already belongs to another vendor.');
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');
        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $this->actingAs($this->salesmanUser('EMP-2', '9900000002'), 'sanctum');

        $this->postJson('/api/vendors/draft', $this->payload([
            'phone' => '9800000000',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.email.0', 'This email is already registered.');
    }

    /**
     * The email check has to span `users`, not just `vendors` - there is no
     * email column on vendors, and an address already belonging to a customer
     * would otherwise fail on the unique index as a raw integrity error.
     */
    public function test_an_email_belonging_to_a_customer_is_rejected(): void
    {
        User::factory()->role(UserRole::Customer)->create(['email' => 'taken@example.com']);

        $this->actingAs($this->salesmanUser(), 'sanctum');

        $this->postJson('/api/vendors/draft', $this->payload(['email' => 'taken@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.email.0', 'This email is already registered.');
    }

    // Resume.

    /**
     * SPEC section 2.2: salesmen work on unreliable networks and must be able
     * to resume. A lost response after the row is written must not leave them
     * permanently blocked on that vendor.
     */
    public function test_the_same_salesman_resubmitting_resumes_their_draft(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');

        $first = $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $second = $this->postJson('/api/vendors/draft', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.resumed', true);

        $this->assertSame(
            $first->json('data.vendor.id'),
            $second->json('data.vendor.id')
        );

        // Resuming must not create a second vendor or a second user.
        $this->assertSame(1, Vendor::count());
        $this->assertSame(1, User::where('role', UserRole::Vendor->value)->count());
    }

    public function test_another_salesmans_draft_is_not_resumable(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');
        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $this->actingAs($this->salesmanUser('EMP-2', '9900000002'), 'sanctum');

        $this->postJson('/api/vendors/draft', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VENDOR_ALREADY_EXISTS');
    }

    public function test_a_non_draft_vendor_is_not_resumable(): void
    {
        // Only Draft is in-progress. Anything further along its lifecycle is
        // a genuine duplicate.
        $this->actingAs($this->salesmanUser(), 'sanctum');
        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        Vendor::sole()->update(['status' => 'active']);

        $this->postJson('/api/vendors/draft', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VENDOR_ALREADY_EXISTS');
    }

    // KYC upload.

    public function test_kyc_files_upload_to_the_draft(): void
    {
        Storage::fake('public');

        $this->actingAs($this->salesmanUser(), 'sanctum');
        $id = $this->postJson('/api/vendors/draft', $this->payload())
            ->json('data.vendor.id');

        $this->post("/api/vendors/{$id}/kyc", [
            'shop_photo' => UploadedFile::fake()->image('shop.jpg'),
            'id_proof' => UploadedFile::fake()->image('aadhaar.jpg'),
            'id_proof_type' => 'aadhaar',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.vendor.has_kyc', true);

        $vendor = Vendor::sole();

        $this->assertNotNull($vendor->shop_photo_path);
        $this->assertNotNull($vendor->id_proof_path);
        $this->assertSame('aadhaar', $vendor->id_proof_type);
    }

    /**
     * The disk column is what makes the eventual R2 migration row-by-row.
     */
    public function test_the_upload_disk_is_recorded(): void
    {
        Storage::fake('public');

        $this->actingAs($this->salesmanUser(), 'sanctum');
        $id = $this->postJson('/api/vendors/draft', $this->payload())->json('data.vendor.id');

        $this->post("/api/vendors/{$id}/kyc", [
            'shop_photo' => UploadedFile::fake()->image('shop.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(Vendor::currentUploadDisk(), Vendor::sole()->disk);
    }

    /**
     * Vendor has two file paths against one disk column, so uploading only
     * the ID proof must still stamp it - otherwise the file is invisible to
     * the migration, which selects on rows whose disk is not yet R2.
     */
    public function test_uploading_only_the_id_proof_still_stamps_the_disk(): void
    {
        Storage::fake('public');

        $this->actingAs($this->salesmanUser(), 'sanctum');
        $id = $this->postJson('/api/vendors/draft', $this->payload())->json('data.vendor.id');

        // TracksFileDisk stamps `disk` unconditionally on create, so it is
        // already set before any file exists. Clearing it first is what makes
        // this test actually exercise Vendor's own saving hook — without this
        // line it passes whether or not that override works, which is how it
        // was originally written.
        Vendor::whereKey($id)->update(['disk' => null]);

        $this->post("/api/vendors/{$id}/kyc", [
            'id_proof' => UploadedFile::fake()->image('pan.jpg'),
            'id_proof_type' => 'pan',
        ], ['Accept' => 'application/json'])->assertOk();

        $vendor = Vendor::sole();

        // Only the secondary file is set. The trait's own updating hook keys
        // on fileDiskPathColumn() — shop_photo_path — so it would not fire
        // here; Vendor's override is what covers this.
        $this->assertNull($vendor->shop_photo_path);
        $this->assertNotNull($vendor->id_proof_path);
        $this->assertSame(Vendor::currentUploadDisk(), $vendor->disk);
    }

    /**
     * Documents current behaviour rather than endorsing it: a vendor with no
     * files at all still carries a disk, because TracksFileDisk stamps on
     * create. Harmless for the R2 migration, which selects on rows whose
     * *path* is not null — but worth pinning so a future change to the trait
     * surfaces here rather than silently altering what `disk` means.
     */
    public function test_a_draft_with_no_files_still_records_a_disk(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');
        $this->postJson('/api/vendors/draft', $this->payload())->assertCreated();

        $vendor = Vendor::sole();

        $this->assertNull($vendor->shop_photo_path);
        $this->assertNull($vendor->id_proof_path);
        $this->assertSame(Vendor::currentUploadDisk(), $vendor->disk);
    }

    public function test_an_id_proof_without_a_type_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->salesmanUser(), 'sanctum');
        $id = $this->postJson('/api/vendors/draft', $this->payload())->json('data.vendor.id');

        $this->post("/api/vendors/{$id}/kyc", [
            'id_proof' => UploadedFile::fake()->image('unknown.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_a_non_image_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->salesmanUser(), 'sanctum');
        $id = $this->postJson('/api/vendors/draft', $this->payload())->json('data.vendor.id');

        $this->post("/api/vendors/{$id}/kyc", [
            'shop_photo' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_kyc_survives_a_failed_upload_without_losing_the_draft(): void
    {
        // The reason KYC is a separate call: a failed photo must not cost the
        // typed business details.
        Storage::fake('public');

        $this->actingAs($this->salesmanUser(), 'sanctum');
        $id = $this->postJson('/api/vendors/draft', $this->payload())->json('data.vendor.id');

        $this->post("/api/vendors/{$id}/kyc", [
            'shop_photo' => UploadedFile::fake()->create('huge.jpg', 20000, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseHas('vendors', ['id' => $id, 'business_name' => 'Cool Air Services']);
    }

    // Access control.

    public function test_a_vendor_cannot_create_drafts(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Vendor)->create([
            'must_change_password' => false,
        ]), 'sanctum');

        $this->postJson('/api/vendors/draft', $this->payload())->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/vendors/draft', $this->payload())->assertStatus(401);
    }

    public function test_missing_required_fields_are_reported_per_field(): void
    {
        $this->actingAs($this->salesmanUser(), 'sanctum');

        $this->postJson('/api/vendors/draft', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['business_name', 'owner_name', 'phone', 'email']]]);
    }
}
