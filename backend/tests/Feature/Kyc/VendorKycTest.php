<?php

namespace Tests\Feature\Kyc;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Events\VendorDocumentUpdated;
use App\Exceptions\KycIncompleteException;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\RelationManagers\VendorDocumentsRelationManager;
use App\Models\DocumentType;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Notifications\VendorDocumentNotification;
use App\Services\VendorKycService;
use App\Services\VendorVerificationService;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vendor KYC documents (VendorKycService, decided 2026-10-28): document
 * types set by the admin, uploads from the vendor and salesman apps, the
 * admin's review, and the rule that a vendor account is approved only once
 * every compulsory type is Verified.
 *
 * Uses the seeded defaults: Aadhaar (compulsory, last 4 digits, back) and
 * PAN (compulsory, pattern), plus optional Driving Licence (expiry, back).
 */
class VendorKycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentTypeSeeder::class);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->sole();
    }

    private function pendingVendor(?Salesman $salesman = null): User
    {
        $user = User::factory()->role(UserRole::Vendor)->pending()->create(['must_change_password' => false]);

        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'draft',
            'created_by_salesman_id' => $salesman?->id,
        ]);

        return $user->fresh();
    }

    private function salesman(): User
    {
        $user = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);
        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-'.$user->id,
            'phone' => (string) fake()->unique()->numberBetween(8000000000, 8999999999),
        ]);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function aadhaar(array $overrides = []): array
    {
        return array_merge([
            'document_type_id' => $this->type('aadhaar')->id,
            'number' => '1234',
            'front' => UploadedFile::fake()->image('front.jpg'),
            'back' => UploadedFile::fake()->image('back.jpg'),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function pan(array $overrides = []): array
    {
        return array_merge([
            'document_type_id' => $this->type('pan')->id,
            'number' => 'abcde1234f',
            'front' => UploadedFile::fake()->image('pan.jpg'),
        ], $overrides);
    }

    private function verifyAll(Vendor $vendor): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        foreach (['aadhaar', 'pan'] as $code) {
            $vendor->documents()->updateOrCreate(
                ['document_type_id' => $this->type($code)->id],
                ['status' => VendorDocument::UPLOADED, 'front_path' => 'x.jpg'],
            );
        }

        foreach ($vendor->documents()->get() as $document) {
            app(VendorKycService::class)->verify($document, $admin);
        }
    }

    // ── Vendor app ───────────────────────────────────────────────────────

    public function test_a_pending_vendor_sees_every_active_type_as_pending_upload(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $response = $this->getJson('/api/vendors/me/documents')
            ->assertOk()
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.missing_compulsory', ['Aadhaar Card', 'PAN Card'])
            ->assertJsonPath('data.documents.0.document_type.code', 'aadhaar')
            ->assertJsonPath('data.documents.0.status', 'pending_upload')
            ->assertJsonPath('data.documents.0.id', null)
            ->assertJsonPath('data.documents.0.can_upload', true);

        $this->assertCount(6, $response->json('data.documents'));
    }

    /** The approval gate still holds everywhere else for a pending vendor. */
    public function test_a_pending_vendor_reaches_documents_but_nothing_else(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->getJson('/api/vendors/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_PENDING_APPROVAL');
    }

    public function test_aadhaar_keeps_only_the_last_four_digits(): void
    {
        $user = $this->pendingVendor();
        $this->actingAs($user, 'sanctum');

        $this->post('/api/vendors/me/documents', $this->aadhaar(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.documents.0.status', 'uploaded')
            ->assertJsonPath('data.documents.0.number', 'XXXX XXXX 1234')
            ->assertJsonPath('data.documents.0.can_upload', true);

        $document = $user->vendor->documents()->where('document_type_id', $this->type('aadhaar')->id)->sole();

        Storage::disk('local')->assertExists($document->front_path);
        Storage::disk('local')->assertExists($document->back_path);
        $this->assertSame($user->id, $document->uploaded_by);
    }

    public function test_a_full_aadhaar_number_is_refused(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->post('/api/vendors/me/documents', $this->aadhaar(['number' => '123456789012']), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.number.0', 'Enter only the last 4 digits.');
    }

    public function test_the_back_photo_is_required_where_the_type_asks_for_it(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->post('/api/vendors/me/documents', $this->aadhaar(['back' => null]), ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_a_number_in_the_wrong_format_is_refused(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->post('/api/vendors/me/documents', $this->pan(['number' => '12345']), ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->post('/api/vendors/me/documents', $this->pan(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.documents.1.number', 'ABCDE1234F');
    }

    public function test_an_expiry_date_must_be_in_the_future(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $licence = [
            'document_type_id' => $this->type('driving_license')->id,
            'number' => 'GJ01 20200012345',
            'front' => UploadedFile::fake()->image('f.jpg'),
            'back' => UploadedFile::fake()->image('b.jpg'),
        ];

        $this->post('/api/vendors/me/documents', $licence, ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/vendors/me/documents', $licence + ['expiry_date' => now()->subDay()->toDateString()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.expiry_date.0', 'This document has expired. Upload a current one.');
        $this->post('/api/vendors/me/documents', $licence + ['expiry_date' => now()->addYear()->toDateString()], ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_a_pdf_is_accepted(): void
    {
        $this->actingAs($this->pendingVendor(), 'sanctum');

        $this->post('/api/vendors/me/documents', $this->pan([
            'front' => UploadedFile::fake()->create('pan.pdf', 200, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertOk();
    }

    public function test_a_verified_document_cannot_be_replaced_from_the_app(): void
    {
        $user = $this->pendingVendor();
        $this->verifyAll($user->vendor);
        $this->actingAs($user, 'sanctum');

        $this->post('/api/vendors/me/documents', $this->aadhaar(), ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOCUMENT_LOCKED');
    }

    public function test_a_document_sent_back_shows_the_reason_and_can_be_uploaded_again(): void
    {
        Notification::fake();
        $user = $this->pendingVendor();
        $this->actingAs($user, 'sanctum');
        $this->post('/api/vendors/me/documents', $this->aadhaar(), ['Accept' => 'application/json'])->assertOk();

        $document = $user->vendor->documents()->where('document_type_id', $this->type('aadhaar')->id)->sole();
        app(VendorKycService::class)->requestReupload($document, User::factory()->role(UserRole::Admin)->create(), 'Photo is blurred');

        $this->getJson('/api/vendors/me/documents')
            ->assertJsonPath('data.documents.0.status', 'reupload')
            ->assertJsonPath('data.documents.0.review_note', 'Photo is blurred')
            ->assertJsonPath('data.documents.0.can_upload', true);

        Notification::assertSentTo($user, VendorDocumentNotification::class);

        $this->post('/api/vendors/me/documents', $this->aadhaar(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.documents.0.status', 'uploaded')
            ->assertJsonPath('data.documents.0.review_note', null);
    }

    // ── Salesman app ─────────────────────────────────────────────────────

    public function test_a_salesman_uploads_for_a_vendor_they_onboarded(): void
    {
        $salesman = $this->salesman();
        $vendor = $this->pendingVendor($salesman->salesman)->vendor;
        $this->actingAs($salesman, 'sanctum');

        $this->post("/api/vendors/{$vendor->id}/documents", $this->aadhaar(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.documents.0.status', 'uploaded');

        $this->getJson("/api/vendors/{$vendor->id}/documents")->assertOk();
    }

    public function test_a_salesman_cannot_reach_another_salesmans_vendor(): void
    {
        $vendor = $this->pendingVendor($this->salesman()->salesman)->vendor;
        $this->actingAs($this->salesman(), 'sanctum');

        $this->getJson("/api/vendors/{$vendor->id}/documents")->assertForbidden();
        $this->post("/api/vendors/{$vendor->id}/documents", $this->aadhaar(), ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    // ── The approval rule ────────────────────────────────────────────────

    public function test_a_vendor_cannot_be_approved_until_compulsory_documents_are_verified(): void
    {
        $user = $this->pendingVendor();

        try {
            $user->recordApprovalDecision(ApprovalStatus::Approved, User::factory()->role(UserRole::Admin)->create());
            $this->fail('Approval should have been refused.');
        } catch (KycIncompleteException $e) {
            $this->assertSame(['Aadhaar Card', 'PAN Card'], $e->missing);
        }

        $this->assertFalse($user->fresh()->isApproved());

        $this->verifyAll($user->vendor);
        $user->fresh()->recordApprovalDecision(ApprovalStatus::Approved);

        $this->assertTrue($user->fresh()->isApproved());
    }

    public function test_an_optional_or_switched_off_type_does_not_block_approval(): void
    {
        $user = $this->pendingVendor();
        $this->type('pan')->update(['is_active' => false]);

        $this->assertSame(['Aadhaar Card'], app(VendorKycService::class)->missingCompulsory($user->vendor));
    }

    public function test_the_verification_queue_leaves_the_vendor_untouched_when_documents_are_missing(): void
    {
        $user = $this->pendingVendor();
        $user->vendor->update(['status' => 'pending_verification']);

        $this->expectException(KycIncompleteException::class);

        try {
            app(VendorVerificationService::class)->approve($user->vendor, User::factory()->role(UserRole::Admin)->create());
        } finally {
            $this->assertSame('pending_verification', $user->vendor->fresh()->status);
        }
    }

    public function test_other_roles_are_not_affected(): void
    {
        $customer = User::factory()->role(UserRole::Customer)->pending()->create();

        $customer->recordApprovalDecision(ApprovalStatus::Approved);

        $this->assertTrue($customer->fresh()->isApproved());
    }

    // ── Live updates ─────────────────────────────────────────────────────

    public function test_verifying_a_document_is_pushed_live_to_the_vendor(): void
    {
        $user = $this->pendingVendor();
        $document = $user->vendor->documents()->create([
            'document_type_id' => $this->type('aadhaar')->id,
            'status' => VendorDocument::UPLOADED,
            'front_path' => 'x.jpg',
        ]);

        Event::fake([VendorDocumentUpdated::class]);

        app(VendorKycService::class)->verify($document, User::factory()->role(UserRole::Admin)->create());

        Event::assertDispatched(VendorDocumentUpdated::class, function (VendorDocumentUpdated $event) use ($user, $document) {
            return $event->broadcastOn()->name === 'private-App.Models.User.'.$user->id
                && $event->broadcastAs() === 'kyc.document'
                && $event->broadcastWith() === [
                    'document_id' => $document->id,
                    'document_type_id' => $document->document_type_id,
                    'status' => 'verified',
                ];
        });
    }

    /** Opening the list creates blank rows — that is not news. */
    public function test_blank_pending_rows_and_unchanged_status_send_nothing(): void
    {
        $user = $this->pendingVendor();

        Event::fake([VendorDocumentUpdated::class]);

        app(VendorKycService::class)->syncTypes($user->vendor);
        $user->vendor->documents()->first()->update(['number' => 'same status']);

        Event::assertNotDispatched(VendorDocumentUpdated::class);
    }

    // ── Expiry ───────────────────────────────────────────────────────────

    public function test_documents_past_their_expiry_become_expired_and_the_vendor_is_told(): void
    {
        Notification::fake();
        $user = $this->pendingVendor();
        $document = $user->vendor->documents()->create([
            'document_type_id' => $this->type('driving_license')->id,
            'status' => VendorDocument::VERIFIED,
            'expiry_date' => now()->subDay()->toDateString(),
            'front_path' => 'x.jpg',
        ]);
        $current = $user->vendor->documents()->create([
            'document_type_id' => $this->type('passport')->id,
            'status' => VendorDocument::VERIFIED,
            'expiry_date' => now()->addMonth()->toDateString(),
            'front_path' => 'y.jpg',
        ]);

        $this->artisan('kyc:expire-documents')->assertSuccessful();

        $this->assertSame(VendorDocument::EXPIRED, $document->fresh()->status);
        $this->assertSame(VendorDocument::VERIFIED, $current->fresh()->status);
        Notification::assertSentTo($user, VendorDocumentNotification::class);
    }

    // ── Admin panel ──────────────────────────────────────────────────────

    public function test_approve_is_greyed_out_in_the_users_list_until_documents_are_verified(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
        $user = $this->pendingVendor();

        Livewire::test(ListUsers::class)->assertTableActionDisabled('approve', $user);

        $this->verifyAll($user->vendor);

        Livewire::test(ListUsers::class)->assertTableActionEnabled('approve', $user);
    }

    public function test_the_admin_verifies_a_document_on_the_documents_tab(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
        $user = $this->pendingVendor();
        $document = $user->vendor->documents()->create([
            'document_type_id' => $this->type('aadhaar')->id,
            'status' => VendorDocument::UPLOADED,
            'front_path' => 'x.jpg',
        ]);

        Livewire::test(VendorDocumentsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
            ->assertCanSeeTableRecords([$document])
            ->callTableAction('verify', $document);

        $this->assertSame(VendorDocument::VERIFIED, $document->fresh()->status);
    }

    public function test_the_admin_asks_for_a_reupload_with_a_reason(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
        $user = $this->pendingVendor();
        $document = $user->vendor->documents()->create([
            'document_type_id' => $this->type('pan')->id,
            'status' => VendorDocument::UPLOADED,
            'front_path' => 'x.jpg',
        ]);

        Livewire::test(VendorDocumentsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
            ->callTableAction('requestReupload', $document, ['reason' => 'Name does not match'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(VendorDocument::REUPLOAD, $document->fresh()->status);
        $this->assertSame('Name does not match', $document->fresh()->review_note);
    }
}
