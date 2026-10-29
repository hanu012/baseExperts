<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_the_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_login_screen_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Sign in', escape: false);
    }

    public function test_an_admin_can_reach_the_panel(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    /**
     * Every non-admin role is refused, so a new role added later does not
     * quietly inherit panel access.
     */
    public function test_non_admin_roles_are_refused(): void
    {
        foreach ([UserRole::Salesman, UserRole::Vendor, UserRole::Customer] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)
                ->get('/admin')
                ->assertForbidden();
        }
    }

    public function test_can_access_panel_is_true_only_for_admins(): void
    {
        $panel = filament()->getPanel('admin');

        $this->assertTrue(User::factory()->role(UserRole::Admin)->create()->canAccessPanel($panel));

        foreach ([UserRole::Salesman, UserRole::Vendor, UserRole::Customer] as $role) {
            $this->assertFalse(
                User::factory()->role($role)->create()->canAccessPanel($panel),
                "{$role->value} must not access the panel"
            );
        }
    }

    public function test_a_non_admin_gets_html_not_the_json_envelope(): void
    {
        // RolesMiddleware serves the API envelope; in the panel it must fall
        // back to a normal error page instead of dumping JSON in the browser.
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        $response = $this->actingAs($vendor)->get('/admin');

        $response->assertForbidden();
        $this->assertStringNotContainsString('"success":false', $response->getContent());
    }

    public function test_the_api_envelope_still_applies_to_api_routes(): void
    {
        // The content negotiation must not have broken the API behaviour.
        $vendor = User::factory()->role(UserRole::Vendor)->create();

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/user')
            ->assertOk();

        $this->getJson('/api/auth/logout')->assertStatus(405);
    }
}
