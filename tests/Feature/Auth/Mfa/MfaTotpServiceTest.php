<?php

namespace Tests\Feature\Auth\Mfa;

use App\Services\Mfa\MfaTotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The TOTP implementation, pinned against RFC 6238 Appendix B.
 *
 * The published vectors are the only thing that proves this is interoperable
 * with Google Authenticator and friends — a self-consistent implementation
 * that generates and verifies its own wrong codes would pass every test we
 * invented ourselves.
 */
class MfaTotpServiceTest extends TestCase
{
    /** RFC 6238 Appendix B uses the ASCII seed "12345678901234567890". */
    private const RFC_SEED = '12345678901234567890';

    private MfaTotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = new MfaTotpService;
    }

    /**
     * RFC 6238 Appendix B, the SHA1 rows. The RFC prints 8 digits; this
     * implementation emits the low 6, which is what authenticator apps use,
     * so each expectation is the RFC value's last six characters.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function rfcVectors(): array
    {
        return [
            '1970-01-01 00:00:59' => [59, '287082'],          // RFC: 94287082
            '2005-03-18 01:58:29' => [1111111109, '081804'],  // RFC: 07081804
            '2005-03-18 01:58:31' => [1111111111, '050471'],  // RFC: 14050471
            '2009-02-13 23:31:30' => [1234567890, '005924'],  // RFC: 89005924
            '2033-05-18 03:33:20' => [2000000000, '279037'],  // RFC: 69279037
            '2603-10-11 11:33:20' => [20000000000, '353130'], // RFC: 65353130
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_test_vectors(int $timestamp, string $expected): void
    {
        $secret = $this->totp->base32Encode(self::RFC_SEED);

        $this->assertSame($expected, $this->totp->codeAt($secret, $this->totp->stepAt($timestamp)));
    }

    /** Base32 has to round-trip, or the secret a user types in is not the one we stored. */
    public function test_base32_round_trips(): void
    {
        foreach ([self::RFC_SEED, 'a', '', random_bytes(20), random_bytes(13)] as $raw) {
            $this->assertSame($raw, $this->totp->base32Decode($this->totp->base32Encode($raw)));
        }
    }

    public function test_a_generated_secret_is_valid_base32_of_the_expected_length(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertTrue($this->totp->isValidSecret($secret));
        $this->assertSame(20, strlen($this->totp->base32Decode($secret)));
    }

    public function test_two_generated_secrets_differ(): void
    {
        $this->assertNotSame($this->totp->generateSecret(), $this->totp->generateSecret());
    }

    // --------------------------------------------------------------- verifying

    public function test_the_current_code_is_accepted(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $step = $this->totp->stepAt($now);

        $this->assertSame($step, $this->totp->verify($secret, $this->totp->codeAt($secret, $step), null, $now));
    }

    public function test_the_previous_window_is_accepted(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $previous = $this->totp->stepAt($now) - 1;

        $this->assertSame($previous, $this->totp->verify($secret, $this->totp->codeAt($secret, $previous), null, $now));
    }

    /** Clock drift runs both ways: a phone slightly ahead must still work. */
    public function test_the_next_window_is_accepted(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $next = $this->totp->stepAt($now) + 1;

        $this->assertSame($next, $this->totp->verify($secret, $this->totp->codeAt($secret, $next), null, $now));
    }

    public function test_a_code_two_windows_old_is_expired(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $stale = $this->totp->stepAt($now) - 2;

        $this->assertNull($this->totp->verify($secret, $this->totp->codeAt($secret, $stale), null, $now));
    }

    public function test_a_code_two_windows_ahead_is_refused(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $ahead = $this->totp->stepAt($now) + 2;

        $this->assertNull($this->totp->verify($secret, $this->totp->codeAt($secret, $ahead), null, $now));
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertNull($this->totp->verify($secret, '000000'));
        $this->assertNull($this->totp->verify($secret, '999999'));
    }

    public function test_another_secrets_code_is_refused(): void
    {
        $mine = $this->totp->generateSecret();
        $theirs = $this->totp->generateSecret();
        $now = time();

        $code = $this->totp->codeAt($theirs, $this->totp->stepAt($now));

        $this->assertNull($this->totp->verify($mine, $code, null, $now));
    }

    public function test_malformed_input_is_refused_rather_than_throwing(): void
    {
        $secret = $this->totp->generateSecret();

        foreach (['', '1', '12345', '1234567', 'abcdef', '!!!!!!'] as $code) {
            $this->assertNull($this->totp->verify($secret, $code), "accepted {$code}");
        }

        // A secret that is not Base32 must not reach the decoder.
        $this->assertNull($this->totp->verify('not-base32!', '123456'));
        $this->assertFalse($this->totp->isValidSecret('not-base32!'));
        $this->assertFalse($this->totp->isValidSecret(''));
    }

    /** Spaces are how authenticator apps display codes; users paste them in. */
    public function test_a_code_with_spaces_is_accepted(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $code = $this->totp->codeAt($secret, $this->totp->stepAt($now));

        $spaced = substr($code, 0, 3).' '.substr($code, 3);

        $this->assertNotNull($this->totp->verify($secret, $spaced, null, $now));
    }

    // ----------------------------------------------------------------- replay

    /**
     * A code stays valid for its whole 30-second step, so "correct" is not the
     * same question as "may be used". Passing the last accepted step refuses
     * anything at or below it.
     */
    public function test_a_replayed_step_is_refused(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $step = $this->totp->stepAt($now);
        $code = $this->totp->codeAt($secret, $step);

        $this->assertSame($step, $this->totp->verify($secret, $code, null, $now));

        // Same code, same window, already used.
        $this->assertNull($this->totp->verify($secret, $code, $step, $now));
    }

    public function test_a_step_older_than_the_last_used_one_is_refused(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $step = $this->totp->stepAt($now);

        // The previous window would normally be accepted, but not once a later
        // step has already been spent.
        $previous = $this->totp->codeAt($secret, $step - 1);

        $this->assertNotNull($this->totp->verify($secret, $previous, null, $now));
        $this->assertNull($this->totp->verify($secret, $previous, $step, $now));
    }

    public function test_the_next_step_still_works_after_one_is_used(): void
    {
        $secret = $this->totp->generateSecret();
        $now = time();
        $step = $this->totp->stepAt($now);

        $this->assertSame(
            $step + 1,
            $this->totp->verify($secret, $this->totp->codeAt($secret, $step + 1), $step, $now),
        );
    }

    // ------------------------------------------------------------------ uri

    public function test_the_provisioning_uri_carries_what_an_authenticator_needs(): void
    {
        config(['mfa.totp.issuer' => 'LeasyBack']);
        $secret = $this->totp->generateSecret();

        $uri = $this->totp->provisioningUri($secret, 'kunde@example.test');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString(rawurlencode('LeasyBack:kunde@example.test'), $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString('issuer=LeasyBack', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
