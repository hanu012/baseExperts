<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /api/user/preferences — language and the notification mute.
 *
 * These used to live only in device storage, so a reinstall or a second
 * device silently reset them.
 */
class PreferenceEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_english_and_notifications_on(): void
    {
        // fresh(), not the returned instance: these are column defaults, so
        // they exist in the row but not on the model the factory just built.
        $user = User::factory()->role(UserRole::Salesman)->create()->fresh();

        $this->assertSame('en', $user->language);
        $this->assertTrue($user->enable_notification);
    }

    public function test_a_user_can_mute_notifications(): void
    {
        $user = User::factory()->role(UserRole::Salesman)->create();

        $this->actingAs($user)
            ->patchJson('/api/user/preferences', ['enable_notification' => false])
            ->assertOk()
            ->assertJsonPath('data.enable_notification', false);

        $this->assertFalse($user->fresh()->enable_notification);
    }

    public function test_one_preference_can_be_sent_without_the_other(): void
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['enable_notification' => false]);

        $this->actingAs($user)
            ->patchJson('/api/user/preferences', ['language' => 'en'])
            ->assertOk();

        // The untouched preference must survive a partial update.
        $this->assertFalse($user->fresh()->enable_notification);
    }

    public function test_hindi_and_gujarati_can_be_chosen(): void
    {
        $user = User::factory()->role(UserRole::Customer)->create();

        foreach (['hi', 'gu', 'en'] as $language) {
            $this->actingAs($user)
                ->patchJson('/api/user/preferences', ['language' => $language])
                ->assertOk()
                ->assertJsonPath('data.language', $language);

            $this->assertSame($language, $user->fresh()->language);
        }
    }

    public function test_an_unsupported_language_is_rejected(): void
    {
        // Accepting an arbitrary tag would let a client set a locale that
        // then falls back silently forever.
        $user = User::factory()->role(UserRole::Customer)->create();

        $this->actingAs($user)
            ->patchJson('/api/user/preferences', ['language' => 'fr'])
            ->assertStatus(422);

        $this->assertSame('en', $user->fresh()->language);
    }

    public function test_every_app_role_may_use_it(): void
    {
        foreach ([UserRole::Vendor, UserRole::Salesman, UserRole::Customer] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)
                ->patchJson('/api/user/preferences', ['enable_notification' => false])
                ->assertOk();
        }
    }

    public function test_an_admin_cannot_use_it(): void
    {
        // Same exclusion as account deletion: the panel is not one of these
        // apps and has no such switch.
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)
            ->patchJson('/api/user/preferences', ['enable_notification' => false])
            ->assertStatus(403);
    }

    public function test_it_requires_authentication(): void
    {
        $this->patchJson('/api/user/preferences', ['enable_notification' => false])
            ->assertStatus(401);
    }
}
