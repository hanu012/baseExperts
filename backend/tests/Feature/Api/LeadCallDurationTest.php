<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approximate call length on Call leads, the customer's My calls list, and
 * the vendor's Recent calls summary.
 */
class LeadCallDurationTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(): User
    {
        $user = User::factory()->role(UserRole::Customer)->create(['must_change_password' => false]);
        Customer::create(['user_id' => $user->id]);

        return $user->fresh();
    }

    private function vendorUser(): User
    {
        $user = User::factory()->role(UserRole::Vendor)->create(['must_change_password' => false]);
        Vendor::create([
            'user_id' => $user->id,
            'business_name' => 'Cool Air Services',
            'owner_name' => 'Bhavin Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    private function lead(User $customer, User $vendor, string $channel = 'call', array $attributes = []): Lead
    {
        $subcategory = Subcategory::factory()->for(Category::factory())->create();

        return Lead::create(array_merge([
            'customer_id' => $customer->customer->id,
            'vendor_id' => $vendor->vendor->id,
            'subcategory_id' => $subcategory->id,
            'channel' => $channel,
        ], $attributes));
    }

    public function test_the_customer_records_the_call_length_once(): void
    {
        $customer = $this->customerUser();
        $lead = $this->lead($customer, $this->vendorUser());

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/duration", ['duration_seconds' => 185])
            ->assertOk()
            ->assertJsonPath('data.call_duration_seconds', 185);

        // A second report (e.g. a retry) does not overwrite it.
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/duration", ['duration_seconds' => 999])
            ->assertOk()
            ->assertJsonPath('data.call_duration_seconds', 185);

        $this->assertSame(185, $lead->fresh()->call_duration_seconds);
    }

    public function test_a_very_long_call_is_capped_at_an_hour(): void
    {
        $customer = $this->customerUser();
        $lead = $this->lead($customer, $this->vendorUser());

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/duration", ['duration_seconds' => 5 * 3600])
            ->assertOk();

        $this->assertSame(Lead::MAX_CALL_DURATION_SECONDS, $lead->fresh()->call_duration_seconds);
    }

    public function test_another_customers_lead_is_not_found(): void
    {
        $lead = $this->lead($this->customerUser(), $this->vendorUser());

        $this->actingAs($this->customerUser(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/duration", ['duration_seconds' => 60])
            ->assertNotFound();

        $this->assertNull($lead->fresh()->call_duration_seconds);
    }

    public function test_a_whatsapp_lead_has_no_duration(): void
    {
        $customer = $this->customerUser();
        $lead = $this->lead($customer, $this->vendorUser(), 'whatsapp');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/duration", ['duration_seconds' => 60])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOT_A_CALL');
    }

    public function test_an_old_lead_cannot_be_given_a_duration(): void
    {
        $customer = $this->customerUser();
        $lead = $this->lead($customer, $this->vendorUser());
        $lead->forceFill(['created_at' => now()->subHours(Lead::DURATION_REPORT_WINDOW_HOURS + 1)])->save();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/duration", ['duration_seconds' => 60])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOO_LATE');
    }

    public function test_my_calls_lists_only_the_customers_own_newest_first(): void
    {
        $customer = $this->customerUser();
        $vendor = $this->vendorUser();
        $older = $this->lead($customer, $vendor);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = $this->lead($customer, $vendor, 'call', ['call_duration_seconds' => 120]);
        $this->lead($this->customerUser(), $vendor);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/customers/me/calls')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.vendor_name', 'Cool Air Services')
            ->assertJsonPath('data.0.call_duration_seconds', 120);
    }

    public function test_the_vendor_leads_list_carries_the_duration(): void
    {
        $vendor = $this->vendorUser();
        $this->lead($this->customerUser(), $vendor, 'call', ['call_duration_seconds' => 95]);

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/vendors/me/leads')
            ->assertOk()
            ->assertJsonPath('data.0.call_duration_seconds', 95);
    }

    public function test_the_vendor_summary_counts_today_and_averages_call_length(): void
    {
        $vendor = $this->vendorUser();
        $customer = $this->customerUser();
        $this->lead($customer, $vendor, 'call', ['call_duration_seconds' => 60]);
        $this->lead($customer, $vendor, 'call', ['call_duration_seconds' => 180]);
        $this->lead($customer, $vendor, 'whatsapp');
        $old = $this->lead($customer, $vendor, 'call');
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/vendors/me/leads/summary')
            ->assertOk()
            ->assertJsonPath('data.calls_today', 2)
            ->assertJsonPath('data.whatsapp_today', 1)
            ->assertJsonPath('data.calls_last_7_days', 2)
            ->assertJsonPath('data.average_call_seconds', 120);
    }
}
