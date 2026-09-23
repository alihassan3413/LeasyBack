<?php

namespace App\Modules\PartnerApi\Data;

use App\Modules\PartnerApi\Models\PartnerApiToken;

/**
 * The one moment a partner token exists in plaintext.
 *
 * Returned by PartnerTokenService::issue() and consumed immediately by the
 * provisioning/rotation commands, which print it once. Nothing persists the
 * plaintext, and there is no path that recovers it afterwards — a lost token
 * is rotated, not looked up.
 */
final class IssuedPartnerToken
{
    public function __construct(
        public readonly PartnerApiToken $token,
        public readonly string $plainTextToken,
    ) {}
}
