<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\PlanResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\ZoneResource;
use App\Models\Category;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sub-admins scoped to specific modules (SPEC section 5.16).
 */
class PermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $permissions
     */
    private function admin(array $permissions): User
    {
        return User::factory()->role(UserRole::Admin)->create(['permissions' => $permissions]);
    }

    private function superAdmin(): User
    {
        return $this->admin([Permission::WILDCARD]);
    }

    // ── hasPermission ───────────────────────────────────────────────────

    public function test_permissions_fail_closed_when_empty_or_null(): void
    {
        // The property that makes everything else safe: a half-configured
        // admin gets nothing rather than everything.
        foreach ([[], null] as $permissions) {
            $admin = $this->admin([]);
            $admin->forceFill(['permissions' => $permissions])->save();

            $this->assertFalse($admin->fresh()->hasPermission('plans.update'));
            $this->assertFalse($admin->fresh()->isSuperAdmin());
        }
    }

    public function test_an_exact_grant_allows_only_that_ability(): void
    {
        $admin = $this->admin(['reviews.moderate', 'categories.viewAny']);

        $this->assertTrue($admin->hasPermission('reviews.moderate'));
        $this->assertTrue($admin->hasPermission('categories.viewAny'));
        $this->assertFalse($admin->hasPermission('categories.update'));
        $this->assertFalse($admin->hasPermission('plans.update'));
    }

    public function test_the_wildcard_grants_everything(): void
    {
        $admin = $this->superAdmin();

        foreach (Permission::values() as $ability) {
            $this->assertTrue($admin->hasPermission($ability), $ability);
        }
    }

    public function test_non_admin_roles_never_hold_permissions(): void
    {
        // Belt and braces behind canAccessPanel().
        foreach ([UserRole::Salesman, UserRole::Vendor, UserRole::Customer] as $role) {
            $user = User::factory()->role($role)->create(['permissions' => [Permission::WILDCARD]]);

            $this->assertFalse($user->hasPermission('plans.update'), $role->value);
            $this->assertFalse($user->isSuperAdmin());
        }
    }

    // ── Resource gating ─────────────────────────────────────────────────

    public function test_a_scoped_admin_sees_only_their_module(): void
    {
        $this->actingAs($this->admin(['categories.viewAny']));

        $this->assertTrue(CategoryResource::canViewAny());
        $this->assertFalse(PlanResource::canViewAny());
        $this->assertFalse(ZoneResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
    }

    public function test_view_access_does_not_imply_edit_access(): void
    {
        // The SPEC example: moderate reviews but not touch plans.
        $this->actingAs($this->admin(['plans.viewAny']));

        $this->assertTrue(PlanResource::canViewAny());
        $this->assertFalse(PlanResource::canCreate());
        $this->assertFalse(PlanResource::canEdit(Plan::factory()->create()));
    }

    public function test_edit_access_is_granted_by_the_matching_ability(): void
    {
        $this->actingAs($this->admin(['plans.viewAny', 'plans.update']));

        $this->assertTrue(PlanResource::canEdit(Plan::factory()->create()));
        $this->assertFalse(PlanResource::canCreate());
    }

    public function test_the_panel_route_is_forbidden_without_the_ability(): void
    {
        $this->actingAs($this->admin(['categories.viewAny']));

        $this->get(CategoryResource::getUrl('index'))->assertOk();
        $this->get(PlanResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_super_admin_reaches_everything(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertTrue(CategoryResource::canViewAny());
        $this->assertTrue(ZoneResource::canViewAny());
        $this->assertTrue(PlanResource::canViewAny());
        $this->assertTrue(UserResource::canViewAny());
    }

    /**
     * Categories and zones can be deleted (unused ones only — SafeDelete),
     * by an admin holding the delete permission. Plans still cannot: the
     * policy has no delete method, so Filament denies it even to a
     * super-admin.
     */
    public function test_delete_permissions_for_master_data(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertTrue(CategoryResource::canDelete(Category::factory()->create()));
        $this->assertTrue(ZoneResource::canDelete(\App\Models\Zone::factory()->create()));
        $this->assertFalse(PlanResource::canDelete(Plan::factory()->create()));

        $this->actingAs($this->admin(['categories.viewAny', 'categories.update']));

        $this->assertFalse(CategoryResource::canDelete(Category::factory()->create()));
    }

    // ── Privilege escalation ────────────────────────────────────────────

    /**
     * The guard that makes every other permission meaningful.
     */
    public function test_a_sub_admin_cannot_edit_another_admin(): void
    {
        $subAdmin = $this->admin(['users.viewAny', 'users.update']);
        $otherAdmin = $this->admin(['categories.viewAny']);

        $this->actingAs($subAdmin);

        $this->assertFalse(UserResource::canEdit($otherAdmin));
    }

    public function test_a_sub_admin_cannot_edit_themselves(): void
    {
        // Self-edit is the escalation path: the permissions field is on the
        // same form.
        $subAdmin = $this->admin(['users.viewAny', 'users.update']);

        $this->actingAs($subAdmin);

        $this->assertFalse(UserResource::canEdit($subAdmin));
    }

    public function test_a_sub_admin_can_still_manage_non_admin_users(): void
    {
        $subAdmin = $this->admin(['users.viewAny', 'users.update']);
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        $this->actingAs($subAdmin);

        $this->assertTrue(UserResource::canEdit($vendor));
    }

    public function test_only_a_super_admin_can_create_users(): void
    {
        $this->actingAs($this->admin(['users.viewAny', 'users.create']));
        $this->assertFalse(UserResource::canCreate());

        $this->actingAs($this->superAdmin());
        $this->assertTrue(UserResource::canCreate());
    }

    public function test_a_sub_admin_cannot_delete_an_admin(): void
    {
        $subAdmin = $this->admin(['users.viewAny', 'users.delete']);
        $otherAdmin = $this->admin([]);

        $this->actingAs($subAdmin);

        $this->assertFalse(UserResource::canDelete($otherAdmin));
    }

    public function test_nobody_can_delete_themselves(): void
    {
        // An admin locking themselves out has no in-panel recovery.
        $super = $this->superAdmin();

        $this->actingAs($super);

        $this->assertFalse(UserResource::canDelete($super));
    }

    public function test_the_users_screen_has_no_permissions_field(): void
    {
        // Permissions are set only in Super Admin → Admins, which a
        // sub-admin cannot open — so there is no field here to inject into.
        $this->actingAs($this->superAdmin());
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        \Livewire\Livewire::test(UserResource\Pages\EditUser::class, ['record' => $vendor->getKey()])
            ->assertFormFieldDoesNotExist('permissions');
    }

    public function test_permission_changes_are_audited(): void
    {
        // users is audited already, so a privilege change is logged for free
        // — which is exactly what SPEC section 5.14 wants recorded.
        $admin = $this->admin(['categories.viewAny']);
        \App\Models\AuditLog::query()->delete();

        $admin->update(['permissions' => ['categories.viewAny', 'plans.update']]);

        $entry = \App\Models\AuditLog::latest('id')->firstOrFail();

        $this->assertSame(['categories.viewAny'], $entry->old_values['permissions']);
        $this->assertContains('plans.update', $entry->new_values['permissions']);
    }
}
