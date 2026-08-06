<?php

namespace App\Modules\PartnerApi\Support;

/**
 * Every `error.code` this API can return, in one table.
 *
 * The codes are a published contract: partners branch on them, so **changing or
 * removing one is a breaking change**, and adding one has to be visible in a
 * diff rather than discovered by a partner in production. That is what this
 * class is for. It is the only place the reference documentation gets its error
 * table from, and `PartnerApiDocumentationTest` scans the module's source for
 * every code the exception classes, middleware and services can actually
 * produce and fails if the two sets differ in either direction.
 *
 * So: a new conflict, refusal or not-found thrown anywhere in the module fails
 * the suite until its code is described here, and an entry described here that
 * nothing can emit fails it too. (The scan reads source, so this docblock
 * deliberately quotes no code literal of its own — it would be found.)
 *
 * `type` is the coarse class a partner can handle wholesale; `code` is the
 * specific reason. Both are in every error envelope (see PartnerApiResponse).
 */
final class PartnerApiErrorCatalog
{
    /**
     * Ordered by the sequence a request meets them: transport and credential
     * first, then authorisation, then the request itself, then the resource.
     *
     * @return list<array{code: string, type: string, status: int|string, when: string}>
     */
    public static function all(): array
    {
        return [
            // Authentication — the credential itself.
            [
                'code' => 'missing_token',
                'type' => PartnerApiResponse::TYPE_AUTHENTICATION,
                'status' => 401,
                'when' => 'No `Authorization: Bearer …` header was sent.',
            ],
            [
                'code' => 'invalid_token',
                'type' => PartnerApiResponse::TYPE_AUTHENTICATION,
                'status' => 401,
                'when' => 'The token is unknown, or the integration client behind it no longer exists.',
            ],
            [
                'code' => 'token_revoked',
                'type' => PartnerApiResponse::TYPE_AUTHENTICATION,
                'status' => 401,
                'when' => 'The token was revoked. A rotation grace window that has closed lands here too.',
            ],
            [
                'code' => 'token_expired',
                'type' => PartnerApiResponse::TYPE_AUTHENTICATION,
                'status' => 401,
                'when' => 'The token is past its `expires_at`.',
            ],

            // Authorisation — who the credential is, and what it may do.
            [
                'code' => 'client_inactive',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The integration client is suspended. The same token works again once it is reactivated.',
            ],
            [
                'code' => 'integration_user_inactive',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The integration account behind the client is deactivated.',
            ],
            [
                'code' => 'company_inactive',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The company the token belongs to is deactivated.',
            ],
            [
                'code' => 'client_misconfigured',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The client is not fully configured, or its account is not an active member '
                    .'of its company. Ours to fix — raise it with your LeasyBack contact.',
            ],
            [
                'code' => 'insufficient_scope',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The token does not carry the ability this endpoint requires. '
                    .'`details.required_ability` names it; `GET /me` lists what you hold.',
            ],
            [
                'code' => 'insufficient_company_permission',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The token carries the ability, but the integration account may not do it '
                    .'in its company. `details.required_permission` names the permission.',
            ],
            [
                'code' => 'forbidden',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'A refusal from an authorisation check with no more specific code.',
            ],

            // The request itself.
            [
                'code' => 'ownership_input_not_allowed',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 400,
                'when' => 'The request carried a company or ownership field (`b2b_id`, `user_id`, …). '
                    .'Ownership comes from the token, always. `details.rejected_parameters` lists them.',
            ],
            [
                'code' => 'idempotency_key_required',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 400,
                'when' => 'This endpoint creates something and no `Idempotency-Key` header was sent.',
            ],
            [
                'code' => 'idempotency_key_invalid',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 400,
                'when' => 'The `Idempotency-Key` is empty or longer than 255 characters.',
            ],
            [
                'code' => 'idempotency_key_conflict',
                'type' => PartnerApiResponse::TYPE_CONFLICT,
                'status' => 409,
                'when' => 'This key was already used, with a different payload or against a different '
                    .'endpoint. Reusing a key is only safe for the identical request.',
            ],
            [
                'code' => 'idempotency_key_in_progress',
                'type' => PartnerApiResponse::TYPE_CONFLICT,
                'status' => 409,
                'when' => 'The original request for this key is still running. Retry shortly.',
            ],
            [
                'code' => 'validation_failed',
                'type' => PartnerApiResponse::TYPE_VALIDATION,
                'status' => 422,
                'when' => 'The payload failed validation. `details.fields` is a field => messages map.',
            ],
            [
                'code' => 'method_not_allowed',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 405,
                'when' => 'That HTTP method is not supported on this path.',
            ],
            [
                'code' => 'rate_limit_exceeded',
                'type' => PartnerApiResponse::TYPE_RATE_LIMIT,
                'status' => 429,
                'when' => 'The per-token budget, or the per-IP failed-authentication budget, is spent. '
                    .'`Retry-After` and `details.retry_after_seconds` say for how long.',
            ],

            // Resources.
            [
                'code' => 'vehicle_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'No such vehicle in the company this token belongs to. A vehicle in another '
                    .'company, and a B2C vehicle, answer the same way.',
            ],
            [
                'code' => 'order_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'No such order in the company this token belongs to.',
            ],
            [
                'code' => 'document_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'No such document, or it is an unpublished draft, or it belongs to another '
                    .'company. All three answer identically on purpose.',
            ],
            [
                'code' => 'offer_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'No such offer, or it has never been presented to a customer.',
            ],
            [
                'code' => 'webhook_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'No such webhook subscription for this integration client.',
            ],
            [
                'code' => 'webhook_delivery_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'No such delivery under that subscription.',
            ],
            [
                'code' => 'company_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'The company behind the token could not be resolved when writing. '
                    .'Ours to fix — raise it with your LeasyBack contact.',
            ],
            [
                'code' => 'resource_not_found',
                'type' => PartnerApiResponse::TYPE_NOT_FOUND,
                'status' => 404,
                'when' => 'The path does not exist, or the record is not visible to this token and has '
                    .'no more specific code.',
            ],

            // Conflicts with the state of the business record.
            [
                'code' => 'order_already_open',
                'type' => PartnerApiResponse::TYPE_CONFLICT,
                'status' => 409,
                'when' => 'The vehicle already has a return order that has not closed. Finish or cancel '
                    .'it before creating another.',
            ],
            [
                'code' => 'external_reference_conflict',
                'type' => PartnerApiResponse::TYPE_CONFLICT,
                'status' => 409,
                'when' => 'The `external_vehicle_id` or `external_order_id` is already mapped to a '
                    .'different record for this integration. Mappings are permanent.',
            ],
            [
                'code' => 'webhook_delivery_already_succeeded',
                'type' => PartnerApiResponse::TYPE_CONFLICT,
                'status' => 409,
                'when' => 'That delivery already succeeded; replaying it would send you a duplicate.',
            ],
            [
                'code' => 'vehicle_not_eligible',
                'type' => PartnerApiResponse::TYPE_VALIDATION,
                'status' => 422,
                'when' => 'The vehicle cannot enter the collection flow in its current state.',
            ],

            // Webhook subscription input.
            [
                'code' => 'webhook_url_not_allowed',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 400,
                'when' => 'The target URL failed the SSRF guard: not https, a disallowed port, '
                    .'credentials in the URL, or a host that resolves to a private, loopback, '
                    .'link-local or metadata address.',
            ],
            [
                'code' => 'webhook_event_type_unknown',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 400,
                'when' => 'An entry in `event_types` is not one of the published event types.',
            ],
            [
                'code' => 'webhook_event_types_required',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 400,
                'when' => 'The `event_types` list is empty. A subscription must name what it wants.',
            ],

            // Download links.
            [
                'code' => 'download_link_invalid',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The signed download URL is missing its signature, has been altered, or was '
                    .'minted for a client that is no longer active.',
            ],
            [
                'code' => 'download_link_expired',
                'type' => PartnerApiResponse::TYPE_AUTHORIZATION,
                'status' => 403,
                'when' => 'The signed download URL is past its 30-minute lifetime. Mint a new one.',
            ],

            // Provisioning problems on our side, and the two fallbacks.
            [
                'code' => 'invalid_integration_account',
                'type' => PartnerApiResponse::TYPE_INVALID_REQUEST,
                'status' => 400,
                'when' => 'The integration account is not usable for this write. '
                    .'Ours to fix — raise it with your LeasyBack contact.',
            ],
            [
                'code' => 'request_failed',
                'type' => 'varies',
                'status' => 'varies',
                'when' => 'A refusal with no more specific code. The status carries the meaning.',
            ],
            [
                'code' => 'internal_error',
                'type' => PartnerApiResponse::TYPE_SERVER,
                'status' => 500,
                'when' => 'Something failed on our side. Retry later, quoting `request_id` if it persists.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }
}
