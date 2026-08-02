<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                // Deliberately not the raw model: excludes timestamps
                // (unused anywhere in the frontend), and adds user_type (used nowhere yet, but a
                // real field worth exposing). email_verified_at is kept
                // because Settings\Profile.vue genuinely reads it for its
                // email-verification banner — trimming it would silently
                // break an out-of-scope page. `avatar` is intentionally
                // NOT included: it was never populated before either (the
                // raw model only ever exposed `avatar_path`, a private
                // storage-disk path, under that name — never `avatar` —
                // so the frontend's optional `avatar` field has always
                // been undefined in practice). Converting avatar_path to a
                // public URL is UserProfile-module business logic (see
                // Api\UserProfileController::show()) and out of scope
                // here; wiring it up is a separate, deliberate task.
                //
                // `id` is the authenticated user's own primary key, needed
                // client-side to subscribe to the private broadcast channel
                // `App.Models.User.{id}` for real-time notifications. Channel
                // authorization (routes/channels.php) is what actually
                // protects the stream — the id itself is not a secret.
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'email_verified_at' => $request->user()->email_verified_at,
                    'user_type' => $request->user()->user_type,
                ] : null,
            ],
            'notifications' => [
                'unread_count' => $request->user() ? $request->user()->unreadNotifications()->count() : 0,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
            ],
        ]);
    }
}
