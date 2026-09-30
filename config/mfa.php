<?php

return [

    /*
     * Master switch. Off means the enrollment and challenge endpoints still
     * exist but nothing is ever demanded of anyone — used to ship the code
     * dark before turning it on.
     */
    'enabled' => (bool) env('MFA_ENABLED', true),

    /*
     * Demand a second factor from every account. Left off for the initial
     * rollout: `required_for` below decides who is asked while the rest of
     * the userbase is untouched.
     */
    'required' => (bool) env('MFA_REQUIRED', false),

    /*
     * Who must use a second factor while `required` is off.
     *
     *   admin     — UserType::Admin, the platform's own staff
     *   b2b_owner — B2bRole::Owner on the active membership, i.e. a company
     *               administrator who can invite members and see the fleet
     *
     * An empty list with `required` off means MFA is optional for everyone
     * and only applies to accounts that opted in.
     */
    'required_for' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MFA_REQUIRED_FOR', 'admin,b2b_owner')),
    ))),

    /*
     * Accounts that are never *forced* to enrol, addressed by email.
     *
     * Deliberately a list of people rather than a role: this application has
     * no super-admin tier, and inventing one in the schema to carry a single
     * exemption would outlive the exemption. Today it holds the one LeasyBack
     * company administrator, so that an environment can demand a second factor
     * of everyone else without that person losing their own way in.
     *
     * Empty by default, so production behaves exactly as `required` and
     * `required_for` describe and nobody is exempt unless an environment
     * deliberately names them.
     *
     * An exemption from the *requirement*, not a licence to skip a factor that
     * exists: someone on this list who has enrolled anyway is still asked for
     * their code, so naming them here cannot silently downgrade an account
     * that already had a second factor. That also means the list can simply be
     * emptied later, when MFA is offered to everyone, without stranding anyone
     * who opted in meanwhile.
     *
     * Every use is logged. Treat an entry here as a standing audit finding.
     */
    'exempt_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MFA_EXEMPT_EMAILS', '')),
    ))),

    'challenge' => [
        /*
         * How long a login ticket stays usable. Long enough to fetch a code
         * from an email client, short enough that a ticket left in a browser
         * tab is worthless.
         */
        'ttl_minutes' => (int) env('MFA_CHALLENGE_TTL_MINUTES', 10),

        /*
         * Wrong codes before the challenge is destroyed and the user starts
         * again from the password. Counted on the challenge, not the account,
         * so this cannot be used to lock someone out of their own login.
         */
        'max_attempts' => (int) env('MFA_CHALLENGE_MAX_ATTEMPTS', 5),
    ],

    'email' => [
        'ttl_minutes' => (int) env('MFA_EMAIL_TTL_MINUTES', 10),

        /* Seconds before "resend" does anything. */
        'resend_cooldown_seconds' => (int) env('MFA_EMAIL_RESEND_COOLDOWN', 30),

        /* Hard ceiling per window, so a stolen ticket cannot be used to mail-bomb. */
        'max_sends' => (int) env('MFA_EMAIL_MAX_SENDS', 5),
        'max_sends_window_minutes' => (int) env('MFA_EMAIL_MAX_SENDS_WINDOW', 15),
    ],

    'totp' => [
        /*
         * RFC 6238 defaults. These are not configuration in any real sense —
         * every authenticator app assumes them — but naming them here keeps
         * the algorithm readable at the call site.
         */
        'digits' => 6,
        'period' => 30,
        'algorithm' => 'sha1',

        /*
         * How many 30-second steps either side of now are accepted, to absorb
         * clock drift between the phone and the server. One step each way is
         * the usual compromise: ±30s of tolerance without widening the window
         * a stolen code is valid in.
         */
        'window' => 1,

        /* What the user sees as the account label in their authenticator. */
        'issuer' => env('MFA_TOTP_ISSUER', 'LeasyBack'),
    ],

    'recovery' => [
        'count' => 8,

        /* Characters per half; codes are shown as XXXXX-XXXXX. */
        'half_length' => 5,
    ],

];
