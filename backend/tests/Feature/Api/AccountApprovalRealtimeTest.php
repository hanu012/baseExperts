<?php

namespace Tests\Feature\Api;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Events\AccountApprovalDecided;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The live "you were approved" push to the app's verification screen
 * (Laravel Reverb): the event, its channel, and the routes the app needs.
 */
class AccountApprovalRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function pendingVendor(): User
    {
        return User::factory()->role(UserRole::Vendor)->create([
            'approval_status' => ApprovalStatus::Pending,
            'must_change_password' => false,
        ]);
    }

    public function test_an_approval_is_pushed_to_that_users_own_channel(): void
    {
        Event::fake([AccountApprovalDecided::class]);
        $user = $this->pendingVendor();

        $user->recordApprovalDecision(ApprovalStatus::Approved);

        Event::assertDispatched(AccountApprovalDecided::class, function (AccountApprovalDecided $event) use ($user) {
            $channel = $event->broadcastOn();

            return $channel instanceof PrivateChannel
                && $channel->name === 'private-App.Models.User.'.$user->id
                && $event->broadcastAs() === 'account.approval'
                && $event->broadcastWith() === ['approval_status' => 'approved', 'approval_note' => null];
        });
    }

    public function test_a_rejection_carries_the_admins_note(): void
    {
        Event::fake([AccountApprovalDecided::class]);
        $user = $this->pendingVendor();

        $user->recordApprovalDecision(ApprovalStatus::Rejected, null, 'Blurry ID photo');

        Event::assertDispatched(
            AccountApprovalDecided::class,
            fn (AccountApprovalDecided $event) => $event->broadcastWith() === [
                'approval_status' => 'rejected',
                'approval_note' => 'Blurry ID photo',
            ],
        );
    }

    /** The app re-reads GET /user on the push, so it must not arrive before the save is final. */
    public function test_inside_a_transaction_it_waits_for_the_commit(): void
    {
        Event::fake([AccountApprovalDecided::class]);
        $user = $this->pendingVendor();

        DB::transaction(function () use ($user) {
            $user->recordApprovalDecision(ApprovalStatus::Approved);

            Event::assertNotDispatched(AccountApprovalDecided::class);
        });

        Event::assertDispatched(AccountApprovalDecided::class);
    }

    public function test_a_pending_account_may_authorize_its_socket(): void
    {
        $user = $this->pendingVendor();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$user->id,
        ]);

        // Not refused by RequireApprovedAccount — the verification screen is
        // exactly who listens on this channel.
        $this->assertNotSame('ACCOUNT_PENDING_APPROVAL', $response->json('error.code'));
        $this->assertNotSame(403, $response->status());
    }

    public function test_only_the_owner_may_listen_on_a_user_channel(): void
    {
        $owner = $this->pendingVendor();
        $other = $this->pendingVendor();

        $callback = app('Illuminate\Contracts\Broadcasting\Broadcaster')->getChannels()['App.Models.User.{id}'];

        $this->assertTrue($callback($owner, $owner->id));
        $this->assertFalse($callback($other, $owner->id));
    }

    public function test_settings_tell_the_apps_where_the_socket_is(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.realtime', null);

        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'public-key');
        config()->set('broadcasting.connections.reverb.secret', 'never-sent');
        config()->set('broadcasting.connections.reverb.public', ['host' => 'api.example.com', 'port' => 443, 'scheme' => 'https']);

        $realtime = $this->getJson('/api/settings')->assertOk()->json('data.realtime');

        $this->assertSame([
            'driver' => 'reverb',
            'key' => 'public-key',
            'host' => 'api.example.com',
            'port' => 443,
            'scheme' => 'https',
        ], $realtime);
        $this->assertStringNotContainsString('never-sent', json_encode($realtime));
    }
}
