<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\MediaModerationResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\PortfolioMediaRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\VendorDetailsRelationManager;
use App\Filament\Resources\VendorResource;
use App\Filament\Resources\VendorVerificationResource;
use App\Models\Media;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * People collapsed to a single Users entry: the vendor record and its
 * verification on the Account tab, the uploads on a Media tab — rather
 * than three sidebar entries keyed by an id you had to carry between
 * screens.
 */
class UserTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($this->admin);
    }

    private function vendorUser(string $status = 'pending_verification'): User
    {
        $user = User::factory()->role(UserRole::Vendor)->create();

        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => $status,
            'id_proof_type' => 'aadhaar',
        ]);

        return $user->fresh();
    }

    /**
     * The whole point of the change: one entry under People, not three.
     * Asserted as absence from navigation rather than as a deleted class
     * — the resources still back the tabs, so they must stay registered.
     */
    public function test_only_users_is_listed_in_the_people_navigation(): void
    {
        $this->assertFalse(VendorResource::shouldRegisterNavigation());
        $this->assertFalse(VendorVerificationResource::shouldRegisterNavigation());
        $this->assertFalse(MediaModerationResource::shouldRegisterNavigation());
    }

    /**
     * "More info" renders VendorResource's own infolist inline, so the
     * KYC documents and the plan breakdown are a tab away rather than a
     * link off the page.
     */
    public function test_the_more_info_tab_shows_the_vendor_record_inline(): void
    {
        $user = $this->vendorUser();

        $this->assertTrue(VendorDetailsRelationManager::canViewForRecord($user, EditUser::class));

        Livewire::test(VendorDetailsRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => EditUser::class,
        ])
            ->assertSuccessful()
            // Straight out of the reused infolist: business block, KYC
            // section, and the no-subscription state.
            ->assertSee('Cool Air Services')
            ->assertSee('KYC documents')
            ->assertSee('Not subscribed');
    }

    /**
     * A user with the vendor role but no vendor row yet has nothing to
     * show, so the tab stays away rather than rendering an empty shell.
     */
    public function test_the_more_info_tab_is_absent_without_a_vendor_row(): void
    {
        $roleOnly = User::factory()->role(UserRole::Vendor)->create();

        $this->assertFalse(VendorDetailsRelationManager::canViewForRecord($roleOnly, EditUser::class));
    }

    public function test_a_vendor_user_gets_the_media_tab(): void
    {
        $user = $this->vendorUser();

        $this->assertTrue(PortfolioMediaRelationManager::canViewForRecord($user, EditUser::class));
    }

    /**
     * A salesman has no uploads and never will. An empty Media tab on
     * their page would read as missing data rather than as a tab that
     * does not apply.
     */
    public function test_a_non_vendor_user_gets_no_media_tab(): void
    {
        $salesman = User::factory()->role(UserRole::Salesman)->create();

        $this->assertFalse(PortfolioMediaRelationManager::canViewForRecord($salesman, EditUser::class));
    }

    /**
     * Verification lives in the Account tab, next to the vendor fields it
     * judges — not as a tab or a sidebar entry of its own.
     *
     * Asserted by rendering rather than by invoking: the buttons are a
     * Forms\Components\Actions component, whose children live in action
     * containers that Component::getAction() does not reach, so
     * callFormComponentAction cannot drive them. The transition behind
     * them is VendorVerificationService, already covered end-to-end by
     * VendorVerificationResourceTest and UserResourceTest — what is new
     * here, and what this pins, is that the controls appear on the
     * account page at all.
     */
    public function test_the_account_tab_carries_vendor_verification(): void
    {
        $user = $this->vendorUser();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertSuccessful()
            ->assertSee('Verification')
            ->assertSee('Awaiting verification')
            ->assertSee('Approve')
            ->assertSee('Reject');
    }

    /**
     * Names of the decision buttons currently showing.
     *
     * Read off the component rather than the rendered HTML because the
     * section's own description contains the words "Approve" and
     * "reject", so assertDontSee could never tell a hidden button from
     * the prose above it.
     *
     * @return array<int, string>
     */
    private function visibleDecisions(User $user): array
    {
        $component = Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->instance()
            ->getForm('form')
            ->getComponent('vendorDecision');

        // Each child is an ActionContainer holding exactly one action,
        // registered by name — hence getActions() rather than a no-arg
        // getAction(), which this version does not have.
        return collect($component?->getChildComponents() ?? [])
            ->flatMap(fn ($container) => $container->getActions())
            ->filter(fn ($action) => $action->isVisible())
            ->map(fn ($action) => $action->getName())
            ->values()
            ->all();
    }

    public function test_a_pending_vendor_can_be_approved_or_rejected(): void
    {
        $this->assertEqualsCanonicalizing(
            ['approveVendor', 'rejectVendor'],
            $this->visibleDecisions($this->vendorUser()),
        );
    }

    /**
     * No "Approve" on a vendor that is already active, and no "Reject" on
     * one already rejected — a button that cannot change anything is
     * noise at best and a double-write at worst.
     */
    public function test_an_approved_vendor_is_not_offered_approve_again(): void
    {
        $this->assertSame(
            ['rejectVendor'],
            $this->visibleDecisions($this->vendorUser('active')),
        );
    }

    public function test_a_rejected_vendor_is_not_offered_reject_again(): void
    {
        $this->assertSame(
            ['approveVendor'],
            $this->visibleDecisions($this->vendorUser('rejected')),
        );
    }

    /**
     * Approve only stages the decision; nothing is written until Save
     * changes. Driven through the hidden state the button sets, since the
     * Actions component's buttons cannot be invoked from a test.
     */
    public function test_a_staged_approval_applies_only_on_save(): void
    {
        $user = $this->vendorUser();

        $page = Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.vendor_decision', 'approve');

        $this->assertSame('pending_verification', $user->vendor->fresh()->status);

        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame('active', $user->vendor->fresh()->status);
        $this->assertTrue($user->fresh()->isApproved());
    }

    public function test_a_staged_rejection_applies_with_its_reason_on_save(): void
    {
        $user = $this->vendorUser('active');

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.vendor_decision', 'reject')
            ->set('data.vendor_decision_reason', 'ID proof is unreadable')
            ->call('save')
            ->assertHasNoFormErrors();

        $vendor = $user->vendor->fresh();
        $this->assertSame('rejected', $vendor->status);
        $this->assertSame('ID proof is unreadable', $vendor->rejection_reason);
        $this->assertTrue($user->fresh()->isRejected());
    }

    /**
     * The staged value travels in the request, so a sub-admin without the
     * verify permission must not be able to approve by crafting it.
     */
    public function test_a_crafted_decision_from_a_sub_admin_without_verify_is_ignored(): void
    {
        $user = $this->vendorUser();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::UsersViewAny->value, Permission::UsersUpdate->value],
        ]));

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.vendor_decision', 'approve')
            ->call('save');

        $this->assertSame('pending_verification', $user->vendor->fresh()->status);
    }

    /**
     * A salesman has no vendor row, so the section must not render at all
     * — an empty Verification box on their page would imply the record is
     * missing rather than inapplicable.
     */
    public function test_a_non_vendor_user_has_no_verification_section(): void
    {
        $salesman = User::factory()->role(UserRole::Salesman)->create();

        Livewire::test(EditUser::class, ['record' => $salesman->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Verification');
    }

    /**
     * Unlike the old standalone queue this tab is NOT filtered to pending
     * — looking at one vendor means seeing everything they posted.
     */
    public function test_the_media_tab_lists_every_upload_not_just_pending(): void
    {
        $user = $this->vendorUser('active');

        $pending = Media::create([
            'mediable_type' => Vendor::class,
            'mediable_id' => $user->vendor->id,
            'type' => 'image',
            'path' => 'portfolio/pending.jpg',
            'disk' => 'public',
            'moderation_status' => 'pending',
        ]);

        $approved = Media::create([
            'mediable_type' => Vendor::class,
            'mediable_id' => $user->vendor->id,
            'type' => 'image',
            'path' => 'portfolio/approved.jpg',
            'disk' => 'public',
            'moderation_status' => 'approved',
        ]);

        Livewire::test(PortfolioMediaRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => EditUser::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$pending, $approved]);
    }

    /**
     * The thumbnail is deliberately small; the full file is behind a
     * Preview action rather than rendered inline, which is what made the
     * old queue unusable at two uploads per screen.
     */
    public function test_the_media_tab_offers_a_preview_action(): void
    {
        $user = $this->vendorUser('active');

        $media = Media::create([
            'mediable_type' => Vendor::class,
            'mediable_id' => $user->vendor->id,
            'type' => 'image',
            'path' => 'portfolio/shot.jpg',
            'disk' => 'public',
            'moderation_status' => 'pending',
        ]);

        Livewire::test(PortfolioMediaRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => EditUser::class,
        ])
            ->assertTableActionExists('preview')
            ->assertTableActionVisible('preview', $media);
    }

    public function test_moderating_from_the_media_tab_approves_the_upload(): void
    {
        $user = $this->vendorUser('active');

        $media = Media::create([
            'mediable_type' => Vendor::class,
            'mediable_id' => $user->vendor->id,
            'type' => 'image',
            'path' => 'portfolio/shot.jpg',
            'disk' => 'public',
            'moderation_status' => 'pending',
        ]);

        Livewire::test(PortfolioMediaRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => EditUser::class,
        ])->callTableAction('approve', $media);

        $this->assertSame('approved', $media->fresh()->moderation_status);
    }

    /**
     * One vendor's uploads must not appear on another's page — the
     * relation walks user → vendor → media and pins the morph type.
     */
    public function test_the_media_tab_shows_only_this_vendors_uploads(): void
    {
        $mine = $this->vendorUser('active');
        $theirs = $this->vendorUser('active');

        $otherUpload = Media::create([
            'mediable_type' => Vendor::class,
            'mediable_id' => $theirs->vendor->id,
            'type' => 'image',
            'path' => 'portfolio/not-mine.jpg',
            'disk' => 'public',
            'moderation_status' => 'pending',
        ]);

        Livewire::test(PortfolioMediaRelationManager::class, [
            'ownerRecord' => $mine,
            'pageClass' => EditUser::class,
        ])->assertCanNotSeeTableRecords([$otherUpload]);
    }

    /**
     * The form sits in the tab strip rather than above it, so the vendor
     * record and its uploads are not buried under a long form.
     */
    public function test_the_user_form_is_itself_a_tab(): void
    {
        $user = $this->vendorUser();

        $page = Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertSuccessful();

        $this->assertTrue($page->instance()->hasCombinedRelationManagerTabsWithContent());
        $this->assertSame('Account', $page->instance()->getContentTabLabel());
    }
}
