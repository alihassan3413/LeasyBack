<?php

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\removeStrategies;

/*
|--------------------------------------------------------------------------
| Partner API reference — Scribe configuration
|--------------------------------------------------------------------------
|
| A second Scribe config, consumed as `scribe:generate --config=scribe_partner`
| and driven end to end by `php artisan partner:docs`.
|
| Separate from `config/scribe.php` on purpose. That one documents the internal
| auth module for our own developers; this one is the artefact we hand to a
| third party. They have different audiences, different branding, different
| output locations and — most importantly — different protection: the auth docs
| sit on the default `/docs` route, and nothing partner-facing may be generated
| into `public/`.
|
| Design decisions, recorded so they are not re-litigated:
|
| 1. `routes` matches `api/v1/partner/*` and nothing else. A route that is not
|    a partner route must not be able to leak into a partner's reference by
|    somebody widening a prefix.
| 2. `type` is `static` with an output path under `storage/app/private`. Static
|    gives one self-contained directory — HTML, assets, OpenAPI, Postman — that
|    zips and travels; `private` keeps it off the public web root. It is served
|    to staff through the authenticated routes in `routes/partner_docs.php`.
| 3. Response calls are removed. Every endpoint documents itself with explicit
|    `#[Response(...)]` attributes, so generating the reference never touches
|    the database, never issues a token and never mints a real signed URL.
| 4. `try_it_out` is disabled. This documentation is read by people who do not
|    have a credential to the environment it is generated on, and a Try-It-Out
|    button on a page describing bearer tokens invites pasting a live one into
|    a browser.
|
*/

if (! class_exists(AuthIn::class)) {
    return [];
}

return [

    'title' => 'LeasyBack Partner API — v1',

    'description' => 'Machine-to-machine integration API for fleet partners, served under '
        .'/api/v1/partner. Vehicles, return orders, status and timeline, documents, offers and '
        .'outbound webhooks. Bearer-token authenticated, versioned in the URL, and scoped to a '
        .'single company by the token itself.',

    /*
    | The branded header and the whole non-endpoint reference (authentication,
    | abilities, errors, webhooks, onboarding) are generated from code by
    | `partner:docs`, which writes `.scribe_partner/append.md` before the
    | generator runs. `intro_text` deliberately carries only the branding and
    | the styling that Scribe's default theme has no hook for, so there is no
    | prose here to drift out of step with the code.
    */
    'intro_text' => '',

    /*
    | The host every generated example and the OpenAPI `servers` entry points
    | at. It is what a partner will paste into a terminal, so it must be the
    | host *they* call and not whatever `APP_URL` happens to be on the machine
    | that ran the build — a reference generated on a laptop otherwise ships
    | with `http://localhost` in every curl example.
    */
    'base_url' => env('PARTNER_API_DOCS_BASE_URL') ?: config('app.url'),

    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/v1/partner/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            'exclude' => [],
        ],
    ],

    'type' => 'static',

    'theme' => 'default',

    'static' => [
        // One self-contained, private directory. `partner:docs` prints the
        // absolute path and it is the only place a partner-facing artefact
        // is ever written.
        'output_path' => 'storage/app/private/partner-api-docs',
    ],

    // Unused for `static`, but Scribe reads the key regardless.
    'laravel' => [
        'add_routes' => false,
        'docs_url' => '/partner-api/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        'enabled' => false,
        'base_url' => null,
        'use_csrf' => false,
        'csrf_url' => null,
    ],

    'auth' => [
        'enabled' => true,
        'default' => true,

        'in' => AuthIn::BEARER->value,
        'name' => 'Authorization',

        // No response calls are made (see `strategies` below), so there is
        // nothing here to fill in and nothing to leak. The placeholder is what
        // appears in every generated example.
        'use_value' => null,

        'placeholder' => 'lbp_sbx_{your token}',

        'extra_info' => 'Tokens are issued out of band by LeasyBack, once per partner and per '
            ."environment, and are shown exactly once at provisioning time.\n\n"
            .'Send yours on every request as `Authorization: Bearer lbp_sbx_…`. `GET /health` and '
            .'`GET /me` require a valid token but no scope, so a freshly issued credential can be '
            ."verified before any endpoint has been enabled for it.\n\n"
            .'See **Authentication and authorization** below for the two gates every feature '
            .'endpoint applies.',
    ],

    'example_languages' => [
        'bash',
        'javascript',
        'php',
    ],

    'postman' => [
        'enabled' => true,
        'overrides' => [
            'info.name' => 'LeasyBack Partner API v1',
            'info.version' => 'v1',
        ],
    ],

    'openapi' => [
        'enabled' => true,
        'version' => '3.0.3',
        'overrides' => [
            'info.title' => 'LeasyBack Partner API',
            'info.version' => 'v1',
        ],
        'generators' => [],
    ],

    'groups' => [
        'default' => 'Partner API',
        'order' => [],
    ],

    'logo' => './images/leasyback-logo.svg',

    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        'faker_seed' => 1234,
        // Never touch the database to build an example. Every response in this
        // reference is an explicit attribute on the controller.
        'models_source' => [],
    ],

    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        // Removed rather than configured: a response call would need a real
        // token, would write real rows through the write endpoints, and would
        // put whatever the local database happens to hold into an artefact we
        // hand to a third party.
        'responses' => removeStrategies(
            Defaults::RESPONSES_STRATEGIES,
            [Strategies\Responses\ResponseCalls::class]
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    'database_connections_to_transact' => [],

    'fractal' => [
        'serializer' => null,
    ],
];
