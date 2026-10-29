<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PATCH /api/user/profile and POST/DELETE /api/user/avatar — personal
 * details and photo on all three apps' profile screens.
 */
class ProfileEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Current#Pass2026';

    private function customer(): User
    {
        $user = User::factory()->role(UserRole::Customer)->create([
            'email' => 'ravi@example.com',
            'password' => Hash::make(self::PASSWORD),
            'must_change_password' => false,
        ]);
        Customer::create(['user_id' => $user->id, 'phone' => '9800000001']);

        return $user;
    }

    private function vendorUser(string $phone = '9811111111'): User
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air',
            'owner_name' => 'Asha',
            'phone' => $phone,
            'status' => 'active',
        ]);

        return $user;
    }

    // ── Details ──────────────────────────────────────────────────────────

    public function test_a_customer_can_change_name_and_phone(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['name' => 'Ravi Shah', 'phone' => '9822222222'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ravi Shah')
            ->assertJsonPath('data.phone', '9822222222');

        $this->assertSame('9822222222', $user->customer->fresh()->phone);
    }

    public function test_changing_email_needs_the_current_password(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['email' => 'new@example.com'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['current_password']]]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertStatus(422);

        $this->assertSame('ravi@example.com', $user->fresh()->email);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['email' => 'new@example.com', 'current_password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');
    }

    public function test_resending_the_same_email_needs_no_password(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['name' => 'Ravi', 'email' => 'RAVI@example.com'])
            ->assertOk();

        $this->assertSame('ravi@example.com', $user->fresh()->email);
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['email' => 'taken@example.com', 'current_password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_a_vendor_phone_is_unique_and_saved_on_the_vendor(): void
    {
        $this->vendorUser('9833333333');
        $user = $this->vendorUser('9844444444');

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['phone' => '9833333333'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['phone' => '9855555555'])
            ->assertOk()
            ->assertJsonPath('data.phone', '9855555555');

        $this->assertSame('9855555555', $user->vendor->fresh()->phone);
    }

    public function test_a_salesman_can_change_name_and_phone(): void
    {
        $user = User::factory()->role(UserRole::Salesman)->create(['must_change_password' => false]);
        Salesman::create(['user_id' => $user->id, 'employee_code' => 'EMP-1', 'phone' => '9866666666']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user/profile', ['name' => 'Meera', 'phone' => '9877777777'])
            ->assertOk()
            ->assertJsonPath('data.phone', '9877777777');

        $this->assertSame('Meera', $user->fresh()->name);
    }

    public function test_an_invalid_phone_is_refused(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->patchJson('/api/user/profile', ['phone' => 'call me'])
            ->assertStatus(422);
    }

    // ── Photo ────────────────────────────────────────────────────────────

    public function test_a_photo_can_be_set_replaced_and_removed(): void
    {
        Storage::fake('public');
        config()->set('filesystems.default', 'public');
        $user = $this->customer();

        $first = $this->actingAs($user, 'sanctum')
            ->post('/api/user/avatar', ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.avatar_url');

        $this->assertNotNull($first);
        $firstPath = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($firstPath);

        // Replacing it removes the old file.
        $this->actingAs($user, 'sanctum')
            ->post('/api/user/avatar', ['photo' => UploadedFile::fake()->image('me2.png')], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('public')->assertMissing($firstPath);

        $secondPath = $user->fresh()->avatar_path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/user/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_only_images_are_accepted_as_a_photo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->customer(), 'sanctum')
            ->post('/api/user/avatar', ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_the_signed_in_user_includes_phone_and_photo(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.phone', '9800000001')
            ->assertJsonPath('data.avatar_url', null);
    }
}
