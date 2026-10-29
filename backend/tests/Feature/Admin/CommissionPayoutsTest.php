<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Pages\CommissionPayouts;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Salesman;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Commission & Payouts (SPEC section 5 item 9).
 */
class CommissionPayoutsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    private function salesman(): Salesman
    {
        return Salesman::create([
            'user_id' => User::factory()->role(UserRole::Salesman)->create()->id,
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create([
            'user_id' => User::factory()->role(UserRole::Vendor)->create()->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);
    }

    private function commission(Salesman $salesman, array $overrides = []): Commission
    {
        $vendor = $this->vendor();
        $plan = Plan::factory()->create();
        PlanQuota::where('plan_id', $plan->id)->update(['priority_rank' => 1]);

        $subscription = Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'salesman_id' => $salesman->id,
            'source' => 'salesman',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return Commission::create(array_merge([
            'subscription_id' => $subscription->id,
            'salesman_id' => $salesman->id,
            'amount_paise' => 10_000,
            'rate_bps' => 1000,
            'status' => 'pending',
        ], $overrides));
    }

    // ── Listing & filters ────────────────────────────────────────────────

    public function test_the_page_lists_commissions(): void
    {
        $commission = $this->commission($this->salesman());

        Livewire::test(CommissionPayouts::class)
            ->assertCanSeeTableRecords([$commission]);
    }

    public function test_the_salesman_filter_narrows_correctly(): void
    {
        $salesmanA = $this->salesman();
        $salesmanB = $this->salesman();

        $commissionA = $this->commission($salesmanA);
        $commissionB = $this->commission($salesmanB);

        Livewire::test(CommissionPayouts::class)
            ->filterTable('salesman_id', $salesmanA->id)
            ->assertCanSeeTableRecords([$commissionA])
            ->assertCanNotSeeTableRecords([$commissionB]);
    }

    public function test_the_status_filter_narrows_correctly(): void
    {
        $salesman = $this->salesman();
        $pending = $this->commission($salesman, ['status' => 'pending']);
        $paid = $this->commission($salesman, ['status' => 'paid', 'paid_at' => now()]);

        Livewire::test(CommissionPayouts::class)
            ->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    // ── Mark as paid ─────────────────────────────────────────────────────

    public function test_mark_as_paid_requires_a_payout_reference(): void
    {
        $commission = $this->commission($this->salesman());

        Livewire::test(CommissionPayouts::class)
            ->callTableAction('markPaid', $commission, data: ['payout_reference' => ''])
            ->assertHasTableActionErrors(['payout_reference' => 'required']);

        $this->assertSame('pending', $commission->fresh()->status);
    }

    public function test_mark_as_paid_sets_status_paid_at_and_reference(): void
    {
        $commission = $this->commission($this->salesman());

        Livewire::test(CommissionPayouts::class)
            ->callTableAction('markPaid', $commission, data: ['payout_reference' => 'UTR123456']);

        $reloaded = $commission->fresh();

        $this->assertSame('paid', $reloaded->status);
        $this->assertNotNull($reloaded->paid_at);
        $this->assertSame('UTR123456', $reloaded->payout_reference);
    }

    public function test_mark_as_paid_writes_a_real_audit_log_entry(): void
    {
        $commission = $this->commission($this->salesman());
        $this->assertSame(0, AuditLog::where('auditable_type', $commission->getMorphClass())
            ->where('auditable_id', $commission->id)
            ->where('action', 'updated')
            ->count());

        Livewire::test(CommissionPayouts::class)
            ->callTableAction('markPaid', $commission, data: ['payout_reference' => 'UTR123456']);

        $entry = AuditLog::where('auditable_type', $commission->getMorphClass())
            ->where('auditable_id', $commission->id)
            ->where('action', 'updated')
            ->sole();

        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame('paid', $entry->new_values['status']);
        $this->assertSame('UTR123456', $entry->new_values['payout_reference']);
    }

    public function test_the_action_is_hidden_once_already_paid(): void
    {
        $commission = $this->commission($this->salesman(), ['status' => 'paid', 'paid_at' => now()]);

        Livewire::test(CommissionPayouts::class)
            ->assertTableActionHidden('markPaid', $commission);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_a_sub_admin_without_the_commissions_permission_cannot_access_the_page(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create(['permissions' => []]);
        $this->actingAs($subAdmin);

        $this->get(CommissionPayouts::getUrl())->assertForbidden();
    }

    public function test_a_sub_admin_with_view_only_cannot_mark_paid(): void
    {
        $commission = $this->commission($this->salesman());

        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::CommissionsViewAny->value],
        ]);
        $this->actingAs($subAdmin);

        Livewire::test(CommissionPayouts::class)
            ->assertTableActionHidden('markPaid', $commission);
    }

    public function test_a_sub_admin_with_mark_paid_can_act(): void
    {
        $commission = $this->commission($this->salesman());

        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [
                Permission::CommissionsViewAny->value,
                Permission::CommissionsMarkPaid->value,
            ],
        ]);
        $this->actingAs($subAdmin);

        Livewire::test(CommissionPayouts::class)
            ->callTableAction('markPaid', $commission, data: ['payout_reference' => 'UTR1']);

        $this->assertSame('paid', $commission->fresh()->status);
    }
}
