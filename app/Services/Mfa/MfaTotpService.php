<?php

namespace App\Services\Mfa;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use SensitiveParameter;

/**
 * RFC 6238 time-based one-time passwords, and the RFC 4648 Base32 they are
 * carried in.
 *
 * Written out rather than pulled from a package because the algorithm is
 * twenty lines and the dependency would be permanent. Correctness is pinned by
 * the published test vectors in RFC 6238 Appendix B rather than by anything
 * this class asserts about itself.
 *
 * Nothing here touches the database or the request. It turns secrets and codes
 * into answers; deciding what to do with the answer belongs to the caller.
 */
class MfaTotpService
{
    /**
     * RFC 4648 Base32, without padding — the alphabet every authenticator app
     * expects a secret to be typed in.
     */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh shared secret, Base32 encoded.
     *
     * 20 random bytes is the length RFC 4226 recommends for HMAC-SHA1 and what
     * the reference implementations use; it encodes to 32 Base32 characters.
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * The `otpauth://` URI an authenticator app reads from a QR code.
     *
     * The issuer appears twice on purpose: once in the label for apps that
     * only read the label, once as a parameter for apps that read parameters.
     * That is what the Key URI specification asks for.
     */
    public function provisioningUri(#[SensitiveParameter] string $secret, string $account): string
    {
        $issuer = (string) config('mfa.totp.issuer');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper((string) config('mfa.totp.algorithm')),
            'digits' => (int) config('mfa.totp.digits'),
            'period' => (int) config('mfa.totp.period'),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The provisioning URI as a scannable SVG.
     *
     * Rendered server side so the secret never has to reach a client-side QR
     * library, and returned as markup rather than a file because nothing about
     * a one-time enrollment code is worth persisting.
     *
     * The SVG carries no width or height of its own — only a viewBox — so the
     * page decides how large it prints. Medium error correction is the level
     * authenticator apps assume; higher would enlarge the code for no gain on
     * a screen.
     */
    public function qrSvg(#[SensitiveParameter] string $provisioningUri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(size: 240, margin: 1),
            new SvgImageBackEnd,
        ));

        // The writer emits an XML declaration, which is invalid inside an
        // HTML document and would be rendered as text by the browser.
        return trim(preg_replace('/<\?xml[^>]*\?>\s*/', '', $writer->writeString($provisioningUri, ecLevel: ErrorCorrectionLevel::M())) ?? '');
    }

    /**
     * The counter step a timestamp falls in. The step, not the code, is what
     * the caller stores to refuse a replay.
     */
    public function stepAt(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), (int) config('mfa.totp.period'));
    }

    /**
     * The code for one exact step. Public so the tests can drive the RFC
     * vectors through it; the application verifies rather than generates.
     */
    public function codeAt(#[SensitiveParameter] string $secret, int $step): string
    {
        $key = $this->base32Decode($secret);
        $digits = (int) config('mfa.totp.digits');

        // The counter is a 64-bit big-endian integer. 'J' would be machine
        // order, so the halves are packed explicitly.
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);

        $hash = hash_hmac((string) config('mfa.totp.algorithm'), $counter, $key, true);

        // Dynamic truncation (RFC 4226 §5.3): the low nibble of the last byte
        // picks where to read four bytes from, and the top bit is cleared so
        // the result is positive on every platform.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The step a code is valid for, or null if it is valid for none.
     *
     * Returning the step rather than a boolean is what lets the caller refuse
     * a replay: a code is good for its whole 30-second window, so "was this
     * code correct" is not enough to decide whether to accept it.
     *
     * `$after` rejects any step at or below one already used by this account.
     * Every candidate is still compared, so the answer takes the same time
     * whether the code was wrong or merely reused.
     */
    public function verify(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
        ?int $after = null,
        ?int $timestamp = null,
    ): ?int {
        $digits = (int) config('mfa.totp.digits');
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== $digits || ! $this->isValidSecret($secret)) {
            return null;
        }

        $window = (int) config('mfa.totp.window');
        $current = $this->stepAt($timestamp);
        $matched = null;

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;

            // hash_equals on every candidate, and no early break, so a wrong
            // code and a reused one cost the same.
            if (hash_equals($this->codeAt($secret, $step), $code) && ($after === null || $step > $after)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public function isValidSecret(string $secret): bool
    {
        return $secret !== '' && preg_match('/^[A-Z2-7]+$/', $secret) === 1;
    }

    public function base32Encode(#[SensitiveParameter] string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    public function base32Decode(#[SensitiveParameter] string $secret): string
    {
        $bits = '';

        foreach (str_split(rtrim(strtoupper($secret), '=')) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        // Trailing bits that do not complete a byte are padding, not data.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
