<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The admin every test in this class acts as. Held so assertions can
     * check what was recorded *against* them — approval decisions name the
     * deciding admin, and "some admin did it" is not what that column is
     * for.
     */
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($this->admin);
    }

    public function test_the_list_page_renders(): void
    {
        User::factory()->count(3)->create();

        Livewire::test(ListUsers::class)->assertSuccessful();
    }

    public function test_role_tabs_filter_the_list(): void
    {
        $vendor = User::factory()->role(UserRole::Vendor)->create();
        $customer = User::factory()->role(UserRole::Customer)->create();

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'vendor')
            ->assertCanSeeTableRecords([$vendor])
            ->assertCanNotSeeTableRecords([$customer]);
    }

    public function test_the_view_modal_opens_for_each_role(): void
    {
        foreach ([UserRole::Salesman, UserRole::Vendor, UserRole::Customer] as $role) {
            $user = User::factory()->role($role)->create();

            Livewire::test(ListUsers::class)
                ->mountTableAction('view', $user)
                ->assertHasNoTableActionErrors();
        }
    }

    /**
     * SPEC section 5.2 asks for CRUD across all four roles. This is the
     * admin-creates-directly path, distinct from self-registration.
     */
    public function test_an_admin_can_create_a_salesman(): void
    {
        $city = Zone::factory()->active()->create(['name' => 'Gandhinagar']);
        Zone::factory()->active()->create(['name' => 'Sector 2', 'parent_id' => $city->id]);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Ravi Salesman',
                'email' => 'ravi@example.com',
                'role' => 'salesman',
                'salesman' => [
                    'employee_code' => 'EMP-001',
                    'phone' => '9900000001',
                    'region' => 'Sector 2 · Gandhinagar',
                    'is_active' => true,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'ravi@example.com')->sole();

        $this->assertSame(UserRole::Salesman, $user->role);
        $this->assertNotNull($user->salesman);
        $this->assertSame('EMP-001', $user->salesman->employee_code);
        $this->assertSame('Sector 2 · Gandhinagar', $user->salesman->region);
        // No longer on the form, so the column defaults apply.
        $this->assertSame(0, $user->salesman->fresh()->commission_rate_bps);
        $this->assertSame(0, $user->salesman->fresh()->monthly_target_paise);
    }

    /**
     * Only real zones can be picked — a region typed into a crafted request
     * is refused rather than stored as free text.
     */
    public function test_a_salesman_region_must_be_an_active_zone(): void
    {
        Zone::factory()->active()->create(['name' => 'Ambawadi']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Ravi Salesman',
                'email' => 'ravi3@example.com',
                'role' => 'salesman',
                'salesman' => [
                    'employee_code' => 'EMP-003',
                    'phone' => '9900000003',
                    'region' => 'Somewhere Made Up',
                    'is_active' => true,
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['salesman.region']);
    }

    /**
     * Regions typed in before this was a dropdown are not zone names.
     * Editing such a salesman must neither blank the region nor fail.
     */
    public function test_editing_a_salesman_keeps_a_region_from_before_the_dropdown(): void
    {
        $user = User::factory()->role(UserRole::Salesman)->create();
        Salesman::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-OLD',
            'phone' => '9900000004',
            'region' => 'Ahmedabad · Gujarat',
        ]);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Ahmedabad · Gujarat', $user->salesman->fresh()->region);
    }

    /**
     * The notification is stored and rebuilt before it renders, which is
     * what broke the old inline copy code. Asserted on the rebuilt buttons.
     */
    public function test_the_temporary_password_can_be_copied_and_shared(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Riya Patel',
                'email' => 'riya@example.com',
                'role' => 'salesman',
                'salesman' => ['employee_code' => 'EMP-009', 'phone' => '9900000009', 'is_active' => true],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $notification = collect(session('filament.notifications'))
            ->map(fn (array $data) => \Filament\Notifications\Notification::fromArray($data))
            ->sole(fn ($n) => str_starts_with($n->getTitle(), 'Temporary password for'));

        $actions = collect($notification->getActions())->keyBy(fn ($action) => $action->getName());
        $password = strtok($notification->getBody(), "\n");

        $this->assertTrue(Hash::check($password, User::where('email', 'riya@example.com')->sole()->password));

        $this->assertSame('copy-temporary-credential', $actions['copy']->getEvent());
        $this->assertSame($password, $actions['copy']->getEventData()['text']);

        $this->assertSame('share-temporary-credential', $actions['share']->getEvent());
        $this->assertStringContainsString($password, $actions['share']->getEventData()['text']);
        $this->assertStringContainsString('riya@example.com', $actions['share']->getEventData()['text']);

        $this->assertStringStartsWith('https://wa.me/?text=', $actions['whatsapp']->getUrl());
        $this->assertStringContainsString(rawurlencode($password), $actions['whatsapp']->getUrl());
    }

    public function test_the_panel_loads_the_copy_and_share_handlers(): void
    {
        $this->get(UserResource::getUrl('index'))
            ->assertOk()
            ->assertSee("Livewire.on('copy-temporary-credential'", escape: false)
            ->assertSee("Livewire.on('share-temporary-credential'", escape: false);
    }

    public function test_a_salesman_must_change_their_password_on_first_login(): void
    {
        // SPEC section 2.1.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Ravi', 'email' => 'ravi2@example.com', 'role' => 'salesman',
                'salesman' => ['employee_code' => 'EMP-002', 'phone' => '9900000002'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // The flag lives on `users`, not `salesmen`: UserResource issues a
        // temporary password to every role it creates, so the forced change
        // has to apply to all of them, not just salesmen.
        $this->assertTrue(User::where('email', 'ravi2@example.com')->sole()->must_change_password);
    }

    public function test_every_created_role_must_change_its_temp_password(): void
    {
        // Admins are created in Super Admin → Admins (SuperAdminPanelTest).
        $roles = [
            'customer' => ['customer' => []],
            'vendor' => ['vendor' => [
                'business_name' => 'Cool Air', 'owner_name' => 'B', 'phone' => '9900000099',
            ]],
        ];

        foreach ($roles as $role => $profile) {
            $email = $role.'-temp@example.com';

            Livewire::test(CreateUser::class)
                ->fillForm(array_merge(['name' => ucfirst($role), 'email' => $email, 'role' => $role], $profile))
                ->call('create')
                ->assertHasNoFormErrors();

            $this->assertTrue(
                User::where('email', $email)->sole()->must_change_password,
                "{$role} was not flagged for a password change"
            );
        }
    }

    public function test_an_admin_created_vendor_starts_as_a_draft(): void
    {
        // SPEC section 7 reserves pending_verification for self-registered
        // vendors; an admin-created one has no subscription yet.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Cool Air', 'email' => 'vendor@example.com', 'role' => 'vendor',
                'vendor' => [
                    'business_name' => 'Cool Air Services',
                    'owner_name' => 'Bhavin',
                    'phone' => '9900000010',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vendor = User::where('email', 'vendor@example.com')->sole()->vendor;

        $this->assertNotNull($vendor);
        $this->assertSame('draft', $vendor->status);
        $this->assertFalse($vendor->is_suspended);
    }

    public function test_an_admin_can_create_a_customer(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Meera', 'email' => 'meera@example.com', 'role' => 'customer',
                'customer' => ['phone' => '9900000020', 'pincode' => '380009'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = User::where('email', 'meera@example.com')->sole()->customer;

        $this->assertNotNull($customer);
        $this->assertSame('380009', $customer->pincode);
    }

    public function test_only_the_matching_profile_row_is_created(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Meera', 'email' => 'meera2@example.com', 'role' => 'customer',
                'customer' => ['phone' => '9900000021'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'meera2@example.com')->sole();

        $this->assertNotNull($user->customer);
        $this->assertNull($user->salesman);
        $this->assertNull($user->vendor);
    }

    public function test_created_accounts_are_approved_on_creation(): void
    {
        // The admin filling in the form IS the vetting (SPEC section 3.1) —
        // sending the account to the pending queue would ask them to
        // approve their own work.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Meera', 'email' => 'meera3@example.com', 'role' => 'customer',
                'customer' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'meera3@example.com')->sole();

        $this->assertTrue($user->isApproved());
        // Recorded against the acting admin, not left blank — the audit
        // trail has to show who let the account in.
        $this->assertSame($this->admin->id, $user->approval_decided_by);
        $this->assertNotNull($user->approval_decided_at);
    }

    public function test_a_temp_password_is_generated_and_usable(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Meera', 'email' => 'meera4@example.com', 'role' => 'customer',
                'customer' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'meera4@example.com')->sole();

        // Stored hashed, never plaintext.
        $this->assertNotEmpty($user->password);
        $this->assertStringStartsWith('$2y$', $user->password);
    }

    public function test_the_generated_password_avoids_ambiguous_characters(): void
    {
        // Read aloud down a phone line or typed off a WhatsApp message.
        for ($i = 0; $i < 50; $i++) {
            $password = CreateUser::generatePassword();

            $this->assertSame(20, strlen($password));
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password);
        }
    }

    public function test_the_role_cannot_be_changed_after_creation(): void
    {
        $user = User::factory()->role(UserRole::Customer)->create();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertFormFieldIsDisabled('role');
    }

    /**
     * The assertion above only checks how the field RENDERS. A disabled field
     * whose value is still dehydrated round-trips through the request, so a
     * crafted submission could set it regardless of what the UI shows — this
     * was exploitable until the field was made dehydrated-on-create-only.
     *
     * Promoting a customer this way would also bypass UserPolicy::create(),
     * which restricts making admins to super-admins.
     */
    public function test_a_crafted_submission_cannot_change_the_role(): void
    {
        $victim = User::factory()->role(UserRole::Customer)->create();

        Livewire::test(EditUser::class, ['record' => $victim->getKey()])
            ->fillForm(['role' => UserRole::Admin->value])
            ->call('save');

        $this->assertSame(UserRole::Customer, $victim->fresh()->role);
    }

    public function test_a_crafted_submission_cannot_grant_permissions(): void
    {
        // The permissions field is no longer on this screen at all (Super
        // Admin → Admins), so an injected value has nothing to land in.
        $subAdmin = User::factory()->subAdmin(['users.viewAny', 'users.update'])->create();
        $victim = User::factory()->role(UserRole::Vendor)->create();

        $this->actingAs($subAdmin);

        Livewire::test(EditUser::class, ['record' => $victim->getKey()])
            ->fillForm(['permissions' => ['*']])
            ->call('save');

        $this->assertNull($victim->fresh()->permissions);
    }

    public function test_deleting_from_the_panel_tombstones_the_email(): void
    {
        $user = User::factory()->role(UserRole::Customer)->create(['email' => 'gone@example.com']);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->callAction('deleteWithTombstone');

        $reloaded = User::withTrashed()->find($user->id);

        $this->assertTrue($reloaded->trashed());
        $this->assertTrue($reloaded->isTombstoned());
        $this->assertSame('gone@example.com', $reloaded->original_email);
    }

    public function test_restoring_from_the_panel_returns_the_original_email(): void
    {
        $user = User::factory()->role(UserRole::Customer)->create(['email' => 'back@example.com']);
        $user->deleteWithTombstone();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->callAction('restoreWithOriginalEmail');

        $reloaded = User::withTrashed()->find($user->id);

        $this->assertFalse($reloaded->trashed());
        $this->assertSame('back@example.com', $reloaded->email);
        $this->assertNull($reloaded->original_email);
    }

    public function test_restore_is_hidden_for_a_live_account_and_delete_for_a_deleted_one(): void
    {
        $live = User::factory()->role(UserRole::Customer)->create();

        Livewire::test(EditUser::class, ['record' => $live->getKey()])
            ->assertActionVisible('deleteWithTombstone')
            ->assertActionHidden('restoreWithOriginalEmail');

        $live->deleteWithTombstone();

        Livewire::test(EditUser::class, ['record' => $live->getKey()])
            ->assertActionHidden('deleteWithTombstone')
            ->assertActionVisible('restoreWithOriginalEmail');
    }

    /**
     * Bulk delete exists, but deliberately isn't Filament's stock
     * DeleteBulkAction — it's a custom action gated by a type-to-confirm
     * field on top of the usual confirmation modal, since a checkbox
     * selection is an easy way to get this wrong.
     */
    public function test_bulk_delete_exists_but_is_not_the_stock_action(): void
    {
        Livewire::test(ListUsers::class)
            ->assertTableBulkActionExists('deleteSelected')
            ->assertTableBulkActionDoesNotExist(\Filament\Tables\Actions\DeleteBulkAction::class);
    }

    public function test_bulk_delete_without_the_typed_confirmation_deletes_nothing(): void
    {
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('deleteSelected', [$vendor], data: ['confirmation' => 'delete'])
            ->assertHasTableBulkActionErrors(['confirmation' => 'in']);

        $this->assertNull($vendor->fresh()->deleted_at);
    }

    public function test_bulk_delete_removes_selected_users_and_tombstones_their_email(): void
    {
        $vendor = User::factory()->role(UserRole::Vendor)->create(['email' => 'bulk-v@example.com']);
        $customer = User::factory()->role(UserRole::Customer)->create();
        $vendor->createToken('phone');

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('deleteSelected', [$vendor, $customer], data: ['confirmation' => 'DELETE'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNotNull($vendor->fresh()->deleted_at);
        $this->assertNotNull($customer->fresh()->deleted_at);

        // The tombstone itself, not just the soft delete: a plain delete()
        // keeps the address on the unique index and leaves tokens live.
        $this->assertSame('bulk-v@example.com', $vendor->fresh()->original_email);
        $this->assertNotSame('bulk-v@example.com', $vendor->fresh()->email);
        $this->assertSame(0, $vendor->tokens()->count());
        User::factory()->role(UserRole::Vendor)->create(['email' => 'bulk-v@example.com']);
    }

    /**
     * Approve, Reject and Reset password are custom actions, which Filament
     * does not authorize on its own. A sub-admin who can only view users
     * must not be able to run them — Reset password on the super-admin's
     * row would otherwise hand them the super-admin's login.
     */
    public function test_a_view_only_sub_admin_cannot_approve_reject_or_reset_passwords(): void
    {
        $subAdmin = User::factory()->subAdmin(['users.viewAny'])->create();
        $this->actingAs($subAdmin);

        $pending = User::factory()->role(UserRole::Vendor)->create();
        $pending->forceFill(['approval_status' => 'pending'])->save();

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('approve', $pending)
            ->assertTableActionHidden('reject', $pending);

        $this->assertFalse($pending->fresh()->isApproved());
    }

    public function test_the_edit_page_delete_needs_the_delete_permission(): void
    {
        $subAdmin = User::factory()->subAdmin(['users.viewAny', 'users.update'])->create();
        $this->actingAs($subAdmin);

        $vendor = User::factory()->role(UserRole::Vendor)->create();

        Livewire::test(EditUser::class, ['record' => $vendor->getRouteKey()])
            ->assertActionHidden('deleteWithTombstone');
    }

    /**
     * UserPolicy::delete() forbids a non-super-admin deleting an admin, and
     * forbids anyone deleting themselves. A custom BulkAction does not
     * auto-authorize per record the way DeleteBulkAction does, so this is
     * re-checked by hand inside the action — this test is what actually
     * proves that check runs, not just that it exists in the policy.
     */
    public function test_bulk_delete_skips_admin_accounts_and_the_actor_themself_for_a_sub_admin(): void
    {
        $subAdmin = User::factory()->subAdmin(['users.viewAny', 'users.delete'])->create();
        $this->actingAs($subAdmin);

        $otherAdmin = User::factory()->role(UserRole::Admin)->create();
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction(
                'deleteSelected',
                [$subAdmin, $otherAdmin, $vendor],
                data: ['confirmation' => 'DELETE'],
            )
            ->assertHasNoTableBulkActionErrors();

        $this->assertNull($subAdmin->fresh()->deleted_at, 'the acting user must never bulk-delete themselves');
        $this->assertNull($otherAdmin->fresh()->deleted_at, 'a sub-admin must never bulk-delete an admin');
        $this->assertNotNull($vendor->fresh()->deleted_at);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Dup', 'email' => 'taken@example.com', 'role' => 'customer',
                'customer' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_a_non_admin_cannot_reach_the_resource(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Vendor)->create());

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    /**
     * The two rules must not drift into each other: the panel may create any
     * role, the public API may not.
     */
    public function test_the_api_still_refuses_self_registration_as_salesman(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'role' => 'salesman',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    /** Admins are made in Super Admin → Admins now, never on this screen. */
    public function test_the_users_screen_cannot_create_an_admin(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Second Admin', 'email' => 'admin2@example.com', 'role' => 'admin'])
            ->call('create')
            ->assertHasFormErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'admin2@example.com']);
    }

    public function test_admin_accounts_are_not_listed_here(): void
    {
        $otherAdmin = User::factory()->role(UserRole::Admin)->create();
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$vendor])
            ->assertCanNotSeeTableRecords([$otherAdmin, $this->admin]);
    }

    /**
     * The approval queue (SPEC section 3.1). With no mail going out, this
     * action is the only thing that makes a self-registered account usable
     * — so it is worth asserting it writes all three columns, not just the
     * status.
     */
    public function test_approving_a_pending_user_records_the_full_decision(): void
    {
        $pending = User::factory()->pending()->create();

        Livewire::test(ListUsers::class)
            ->callTableAction('approve', $pending)
            ->assertHasNoTableActionErrors();

        $pending->refresh();

        $this->assertTrue($pending->isApproved());
        $this->assertSame($this->admin->id, $pending->approval_decided_by);
        $this->assertNotNull($pending->approval_decided_at);
    }

    public function test_rejecting_a_user_stores_the_reason_shown_to_them(): void
    {
        $pending = User::factory()->pending()->create();

        Livewire::test(ListUsers::class)
            ->callTableAction('reject', $pending, data: [
                'approval_note' => 'Business address could not be confirmed.',
            ])
            ->assertHasNoTableActionErrors();

        $pending->refresh();

        $this->assertTrue($pending->isRejected());
        $this->assertSame(
            'Business address could not be confirmed.',
            $pending->approval_note,
        );
    }

    /**
     * The reason is what the app shows a rejected user. Rejecting without
     * one would leave them staring at a generic failure, so the field is
     * required rather than optional-with-a-fallback.
     */
    public function test_rejecting_without_a_reason_is_refused(): void
    {
        $pending = User::factory()->pending()->create();

        Livewire::test(ListUsers::class)
            ->callTableAction('reject', $pending, data: ['approval_note' => ''])
            ->assertHasTableActionErrors(['approval_note']);

        $this->assertTrue($pending->refresh()->isAwaitingApproval());
    }

    /**
     * Bulk approve exists; bulk reject deliberately does not, because one
     * pasted reason across a mixed selection is worse than none. Asserted
     * as absent rather than present-but-disabled, per CLAUDE.md — this
     * fails loudly if someone adds it back.
     */
    public function test_there_is_no_bulk_reject_action(): void
    {
        Livewire::test(ListUsers::class)
            ->assertTableBulkActionExists('approve')
            ->assertTableBulkActionDoesNotExist('reject');
    }

    public function test_bulk_approve_skips_already_approved_accounts(): void
    {
        $pending = User::factory()->pending()->create();
        $alreadyApproved = User::factory()->create();

        $decidedAt = $alreadyApproved->approval_decided_at;

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('approve', [$pending, $alreadyApproved]);

        $this->assertTrue($pending->refresh()->isApproved());

        // Untouched, not re-stamped — re-deciding an existing approval
        // would rewrite who approved it and when.
        $this->assertEquals(
            $decidedAt->toDateTimeString(),
            $alreadyApproved->refresh()->approval_decided_at->toDateTimeString(),
        );
    }

    /**
     * Passwords are reset only by the user, from the app's emailed link.
     * Asserting absence (not just hidden) so a later edit re-adding a
     * panel reset fails loudly here.
     */
    public function test_the_panel_offers_no_password_reset(): void
    {
        $user = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionDoesNotExist('resetPassword', record: $user);
    }
}
