<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\PaymentReconciliationResource;
use App\Filament\Resources\PaymentReconciliationResource\Pages\ListPaymentReconciliations;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanQuota;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cash-collection reconciliation (SPEC section 5 item 9 / section
 * 5.9) — the first thing that ever writes payments.admin_verified_at.
 */
class PaymentReconciliationResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($this->admin);
    }

    private function subscription(): Subscription
    {
        $vendor = Vendor::create([
            'user_id' => User::factory()->role(UserRole::Vendor)->create()->id,
            'business_name' => 'Cool Air Services '.fake()->unique()->numberBetween(1, 999999),
            'owner_name' => 'Asha Patel',
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $plan = Plan::factory()->create();
        PlanQuota::where('plan_id', $plan->id)->update(['priority_rank' => 1]);

        return Subscription::create([
            'vendor_id' => $vendor->id,
            'plan_id' => $plan->id,
            'source' => 'self',
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays(300),
            'price_paise' => $plan->price_paise,
            'duration_days' => $plan->duration_days,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'subscription_id' => $this->subscription()->id,
            'amount_paise' => 99_900,
            'mode' => 'cash',
            'status' => 'captured',
            'admin_verified_at' => null,
        ], $overrides));
    }

    // ── Queue scoping ────────────────────────────────────────────────────

    public function test_the_queue_lists_unverified_cash_payments_only(): void
    {
        $unverifiedCash = $this->payment();
        $verifiedCash = $this->payment(['admin_verified_at' => now()]);
        $online = $this->payment(['mode' => 'online']);
        $free = $this->payment(['mode' => 'free', 'amount_paise' => 0]);

        Livewire::test(ListPaymentReconciliations::class)
            ->assertCanSeeTableRecords([$unverifiedCash])
            ->assertCanNotSeeTableRecords([$verifiedCash, $online, $free]);
    }

    // ── Mark verified ────────────────────────────────────────────────────

    public function test_mark_verified_sets_admin_verified_at(): void
    {
        $payment = $this->payment();

        Livewire::test(ListPaymentReconciliations::class)
            ->callTableAction('verify', $payment);

        $this->assertNotNull($payment->fresh()->admin_verified_at);
    }

    public function test_mark_verified_writes_a_real_audit_log_entry(): void
    {
        $payment = $this->payment();

        Livewire::test(ListPaymentReconciliations::class)
            ->callTableAction('verify', $payment);

        $entry = AuditLog::where('auditable_type', $payment->getMorphClass())
            ->where('auditable_id', $payment->id)
            ->where('action', 'updated')
            ->sole();

        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertArrayHasKey('admin_verified_at', $entry->new_values);
    }

    public function test_a_verified_payment_drops_out_of_the_queue_immediately(): void
    {
        $payment = $this->payment();

        Livewire::test(ListPaymentReconciliations::class)
            ->callTableAction('verify', $payment)
            ->assertCanNotSeeTableRecords([$payment->fresh()]);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_a_sub_admin_without_the_payments_permission_cannot_access_the_queue(): void
    {
        $subAdmin = User::factory()->role(UserRole::Admin)->create(['permissions' => []]);
        $this->actingAs($subAdmin);

        $this->get(PaymentReconciliationResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_sub_admin_with_view_only_cannot_verify(): void
    {
        $payment = $this->payment();

        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [Permission::PaymentsViewAny->value],
        ]);
        $this->actingAs($subAdmin);

        Livewire::test(ListPaymentReconciliations::class)
            ->assertTableActionHidden('verify', $payment);
    }

    public function test_a_sub_admin_with_verify_can_act(): void
    {
        $payment = $this->payment();

        $subAdmin = User::factory()->role(UserRole::Admin)->create([
            'permissions' => [
                Permission::PaymentsViewAny->value,
                Permission::PaymentsVerify->value,
            ],
        ]);
        $this->actingAs($subAdmin);

        Livewire::test(ListPaymentReconciliations::class)
            ->callTableAction('verify', $payment);

        $this->assertNotNull($payment->fresh()->admin_verified_at);
    }
}
