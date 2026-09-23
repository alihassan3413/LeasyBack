<?php

namespace App\Http\Controllers\B2b;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switching which company a multi-company user is acting as — or back to
 * their private side, for an account that has one.
 *
 * B2bContext::switchTo() refuses any company the user is not an active member
 * of, so the request body cannot be used to act as an arbitrary company — the
 * validation below is only there to produce a readable error instead of a
 * silent no-op. A null `b2b_id` means "act as myself" and is only honoured for
 * accounts with a private side (Privatkunde); switchToPersonal() refuses it
 * for everyone else, so a Firmenkunde cannot escape company scoping this way.
 */
class CompanyContextController extends Controller
{
    public function __construct(private readonly B2bContext $context) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'b2b_id' => ['nullable', 'uuid', Rule::in(
                array_map(fn ($membership) => $membership->b2bId, $this->context->memberships($user))
            )],
        ], [
            'b2b_id.in' => 'Sie gehören diesem Unternehmen nicht an.',
        ]);

        if (($validated['b2b_id'] ?? null) === null) {
            if (! $this->context->switchToPersonal($user)) {
                return back()->withErrors(['b2b_id' => 'Ihr Konto hat keinen privaten Bereich.']);
            }

            return to_route('dashboard')->with('success', 'Sie sind jetzt in Ihrem privaten Bereich.');
        }

        if (! $this->context->switchTo($user, $validated['b2b_id'])) {
            return back()->withErrors(['b2b_id' => 'Sie gehören diesem Unternehmen nicht an.']);
        }

        // A full redirect, not `back()`: the previous page was rendered with
        // the other company's data and permissions throughout.
        return to_route('dashboard')->with('success', 'Unternehmen gewechselt.');
    }
}
