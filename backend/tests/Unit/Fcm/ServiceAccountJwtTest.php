<?php

namespace Tests\Unit\Fcm;

use App\Services\Fcm\ServiceAccountJwt;
use PHPUnit\Framework\TestCase;

/**
 * Pure signing logic (BUILD_PLAN 7.2) — no HTTP, no Laravel app boot.
 *
 * Uses a static fixture keypair rather than generating one at runtime
 * via openssl_pkey_new(): this dev machine's PHP openssl extension
 * can't find a valid openssl.cnf (confirmed via openssl_error_string()
 * — "configuration file routines::no such file"), so key *generation*
 * fails here even though signing/verifying against an existing PEM
 * (what the actual production code does) works fine. The fixture
 * below is a throwaway 2048-bit RSA keypair with no real-world use,
 * generated once via the openssl CLI (not PHP) specifically to avoid
 * that machine-specific gap.
 */
class ServiceAccountJwtTest extends TestCase
{
    private const PRIVATE_KEY = <<<'PEM'
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

    private const PUBLIC_KEY = <<<'PEM'
    -----BEGIN PUBLIC KEY-----
    MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuJuD9rw6vO0F+CjKBc2u
    RgyLsq6KJ1Nl+BDMmUew+5H9Q9EeWltRH2kABcU0WlZWHlxzq6A8u1EXYP+41CRp
    TUeoCkogom1yxjIJlD4LLSxJRh9Ous5XY1H6f131JyHAr7qXxWLtSH0TJvehg6Rm
    bcYAmLUglLaefGbi/J4aB0ILTCiA60KfNEIigtC9bKf51RlY/rXsql3310/zCoVu
    BxMpz1aV3uihBJ74zCDPnkJUWdiZG1NwXQoHVXj7w3mMYOcNlxSjzmCrv+ZJLJni
    zcxCcE62BSVNQGQIauiCXULOFGKmvmoSoNuilOHvBVG0mx0B3j/uxCnJ+KLXHdfZ
    ywIDAQAB
    -----END PUBLIC KEY-----
    PEM;

    /**
     * A second, unrelated fixture keypair's public half only — used
     * to prove a signature does NOT verify against the wrong key.
     */
    private const OTHER_PUBLIC_KEY = <<<'PEM'
    -----BEGIN PUBLIC KEY-----
    MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA7/leBCIQ7sDV4RuOqXI7
    OsjjhuoReSBnybxVOJYR4ngsSpb8S+/a3NhBI2WQaO4vzwb6QehFYEZ9P2NdYwiI
    POST7BrfBsCkk2uPampq5+vqmgeqivozDdNeWL1Kw5KdBMTT3PJETxvPIFi1vrdT
    Xs+9zx22YSV4M0KJ0TikN8bq+eA6mehPm0OXBJamBL9+Xhl+MyU8duD4kSfX6DEp
    9pPnSpbagM58uwzOXOMu9EijYMRCFTUT+fz1WQhQReASzguO9eHgE9tIFUKY38tK
    EZTqxCRQ7YFgLqNYXBhzqh9TOgJ/u/yMu+w1T+zBMfUldMP/ANRKNMLfSE+AsmVT
    aQIDAQAB
    -----END PUBLIC KEY-----
    PEM;

    public function test_the_signature_verifies_against_the_matching_public_key(): void
    {
        $jwt = ServiceAccountJwt::sign(['iss' => 'test@example.com', 'iat' => 1000], self::PRIVATE_KEY);

        [$headerSegment, $claimsSegment, $signatureSegment] = explode('.', $jwt);
        $signingInput = "{$headerSegment}.{$claimsSegment}";
        $signature = $this->base64UrlDecode($signatureSegment);

        $verified = openssl_verify($signingInput, $signature, self::PUBLIC_KEY, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, $verified);
    }

    public function test_the_signature_does_not_verify_against_a_different_key(): void
    {
        $jwt = ServiceAccountJwt::sign(['iss' => 'test@example.com'], self::PRIVATE_KEY);

        [$headerSegment, $claimsSegment, $signatureSegment] = explode('.', $jwt);
        $signingInput = "{$headerSegment}.{$claimsSegment}";
        $signature = $this->base64UrlDecode($signatureSegment);

        $verified = openssl_verify($signingInput, $signature, self::OTHER_PUBLIC_KEY, OPENSSL_ALGO_SHA256);

        $this->assertSame(0, $verified);
    }

    public function test_the_header_and_claims_segments_decode_to_the_expected_json(): void
    {
        $jwt = ServiceAccountJwt::sign(['iss' => 'test@example.com', 'aud' => 'https://oauth2.googleapis.com/token'], self::PRIVATE_KEY);

        [$headerSegment, $claimsSegment] = explode('.', $jwt);

        $header = json_decode($this->base64UrlDecode($headerSegment), true);
        $claims = json_decode($this->base64UrlDecode($claimsSegment), true);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $this->assertSame('test@example.com', $claims['iss']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
    }

    public function test_an_invalid_pem_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        ServiceAccountJwt::sign(['iss' => 'test@example.com'], 'not a real key');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
