<?php

namespace Tests\Feature\Fcm;

use App\Services\Fcm\FcmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * BUILD_PLAN 7.2 — both HTTP calls FcmClient makes (the OAuth2 token
 * exchange and the actual messages:send POST) go through Http::fake(),
 * so this proves the exact request FCM would have received without
 * any real Firebase credentials.
 */
class FcmClientTest extends TestCase
{
    private const FIXTURE_PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC4m4P2vDq87QX4
    KMoFza5GDIuyroonU2X4EMyZR7D7kf1D0R5aW1EfaQAFxTRaVlYeXHOroDy7URdg
    /7jUJGlNR6gKSiCibXLGMgmUPgstLElGH066zldjUfp/XfUnIcCvupfFYu1IfRMm
    96GDpGZtxgCYtSCUtp58ZuL8nhoHQgtMKIDrQp80QiKC0L1sp/nVGVj+teyqXffX
    T/MKhW4HEynPVpXe6KEEnvjMIM+eQlRZ2JkbU3BdCgdVePvDeYxg5w2XFKPOYKu/
    5kksmeLNzEJwTrYFJU1AZAhq6IJdQs4UYqa+ahKg26KU4e8FUbSbHQHeP+7EKcn4
    otcd19nLAgMBAAECggEAA4h9ocfGRTf+GlrhfvOJ528b1cG8a1zcjnJeQ0kmRhi4
    5OB7bCKwrnq3R7HK1J3JZfX1nfr7npQo1km4P/f21SyCNuwzReVbwhcfRgL0xzRA
    eylkCHQ+VoX+Cgw1m6TSoZDFkQwMwYuCeQdaEOehF26OM8Rnr4daCJFJiXWXLVaN
    N4VfGHIe6g7vvoSx99pvcb74zTpWzVzw3D9eq0lVH47AUv8SMXgL2apVN/BxTcRf
    L/XhEDWAOy4yKEv1TMQs/pP0/PBMbJ/76jieR+BfEqtHNSAzajh1APp492d3OVy9
    J0ujgEzqio/AaJTh4NuZTx1yIGaCK+/iJeqTLKrozQKBgQDmaRUVYQa4l2cTm0BM
    2pshKFHp0DJr3uUgZqiLfw/3jdy0Qhnmpu444X6XBKrugGJus0Evq5GRHUiDB3YV
    lzn6bFRnYqmZGr7IAYHJcB5kmC2Dk1fvx0bKzbCTnVmaLfmMUqsJhQ0oTJLIpotK
    kUoaenMxWB3tGCKCFPjFOWwa9wKBgQDNHC9RIE3Jt3roPLnqSNwmLLmGh9GQfVBs
    5z/Cmg0v6zodwm0JkIxvVujZpsrWJ5g6KT3JoVxDkSUG+D8G7PEvI/6k9e0Ug5YF
    /YNmJLmXBP5ZjFK7iubFQzEcNXU5xv/r2ErE+W+aKgRxnBv48QtYBHdaYfWkL5Qm
    9+f5g6lOzQKBgAi7quTojIyqkGmZ1NIU5xRWpuQp0/9qr1yPB4xiAITth5P9fWXU
    peraASZQMvpfO1vex3W7FwVdCsaMndkrpjLrsDdK8gqvjNOf2v97lGtTqUX3a7nW
    38QID81IhYDmhTLgX0M5G8qPPHEGfvkQkLJ4Oa2BHYFDDOvJR7SR/Jr5AoGANdPS
    uxieMXTcZXwiUlDCraYJHjwgjCnG5H2fpwNkuJGj09GFagAsSr/lJdF249LKSWEv
    XO3i17yMmhKl/7xI41Uv67y6diq+QV4xkKnMpsxhr8B6qcsfGt+yULPaysnludAu
    dxj659tlBSex05f2oSey5t5UZ70wxTVEBKA/23UCgYBcHnUrVNpfBWzHRvbulhWn
    mSECKh9IlA2hZYk0dDrfxEPb1EHyHtAEFO13wvm0lP++VX3fzQRwIp46TLYxN9NT
    XTtQ8ZP+BNagHtEFCXpSz4ia5d67v0vj8HpVJSzgs/k9pdC+ENvi8kGo5vnGhN4d
    5r7pMiksXXp41vBgloVv3A==
    -----END PRIVATE KEY-----
    PEM;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.fcm.project_id' => 'test-project',
            'services.fcm.client_email' => 'test@test-project.iam.gserviceaccount.com',
            'services.fcm.private_key_base64' => base64_encode(self::FIXTURE_PRIVATE_KEY),
        ]);
    }

    public function test_send_posts_the_exact_expected_payload_to_fcm(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/1']),
        ]);

        $result = (new FcmClient())->send('device-token-123', [
            'notification' => ['title' => 'Hello', 'body' => 'World'],
            'data' => ['type' => 'lead_received'],
        ]);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
                && $request->hasHeader('Authorization', 'Bearer fake-access-token')
                && $request->data() === [
                    'message' => [
                        'token' => 'device-token-123',
                        'notification' => ['title' => 'Hello', 'body' => 'World'],
                        'data' => ['type' => 'lead_received'],
                    ],
                ];
        });
    }

    public function test_the_token_exchange_signs_a_jwt_and_posts_form_encoded(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        (new FcmClient())->send('device-token-123', ['notification' => ['title' => 'x', 'body' => 'y']]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return true; // not the request under test
            }

            $body = $request->data();

            return $body['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && substr_count($body['assertion'], '.') === 2; // header.claims.signature
        });
    }

    public function test_the_access_token_is_cached_across_multiple_sends(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        $client = new FcmClient();
        $client->send('token-a', ['notification' => ['title' => 'x', 'body' => 'y']]);
        $client->send('token-b', ['notification' => ['title' => 'x', 'body' => 'y']]);

        Http::assertSentCount(3); // 1 token exchange + 2 sends, not 2 + 2
    }

    public function test_a_failed_fcm_response_returns_false_not_an_exception(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => 'invalid token'], 400),
        ]);

        $result = (new FcmClient())->send('bad-token', ['notification' => ['title' => 'x', 'body' => 'y']]);

        $this->assertFalse($result);
    }

    public function test_a_failed_token_exchange_throws(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(RuntimeException::class);

        (new FcmClient())->send('device-token-123', ['notification' => ['title' => 'x', 'body' => 'y']]);
    }

    public function test_a_blank_project_id_throws_before_any_http_call(): void
    {
        config(['services.fcm.project_id' => null]);
        Http::fake();

        try {
            (new FcmClient())->send('device-token-123', ['notification' => ['title' => 'x', 'body' => 'y']]);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
    }
}
