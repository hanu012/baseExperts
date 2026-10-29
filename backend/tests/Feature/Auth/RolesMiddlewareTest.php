<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolesMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Throwaway routes so the middleware is exercised directly, without
        // waiting on the real role-guarded endpoints from later phases.
        Route::middleware(['auth:sanctum', 'role:admin'])
            ->get('/api/test/admin-only', fn () => response()->json(['ok' => true]));

        Route::middleware(['auth:sanctum', 'role:admin,salesman'])
            ->get('/api/test/staff-only', fn () => response()->json(['ok' => true]));
    }

    public function test_a_matching_role_is_allowed_through(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/test/admin-only')
            ->assertOk();
    }

    public function test_a_non_matching_role_is_forbidden_in_the_envelope(): void
    {
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/test/admin-only')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_any_of_several_listed_roles_is_accepted(): void
    {
        foreach ([UserRole::Admin, UserRole::Salesman] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/test/staff-only')
                ->assertOk();
        }
    }

    public function test_a_role_outside_the_list_is_still_rejected(): void
    {
        $customer = User::factory()->role(UserRole::Customer)->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/test/staff-only')
            ->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/test/admin-only')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
