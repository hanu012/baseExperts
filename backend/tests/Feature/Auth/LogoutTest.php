<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $device): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => $device,
        ])->json('data.token');
    }

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create([
            'email' => 'asha@example.com',
            'password' => Hash::make('correct-horse-battery'),
            'role' => UserRole::Vendor,
        ]);
    }

    public function test_a_user_can_log_out(): void
    {
        $token = $this->login('pixel-8');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    }

    public function test_the_token_stops_working_after_logout(): void
    {
        $token = $this->login('pixel-8');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        // The guard caches the user it resolved during the logout request, so
        // it has to be reset for the next call to re-read the (now deleted)
        // token from the database the way a fresh HTTP request would.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logging_out_one_device_leaves_other_devices_signed_in(): void
    {
        $phone = $this->login('pixel-8');
        $tablet = $this->login('ipad-air');

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$tablet)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
