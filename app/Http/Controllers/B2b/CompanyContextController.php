<?php

namespace App\Http\Controllers\B2b;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Switching which company a multi-company user is acting as.
 *
 * B2bContext::switchTo() refuses any company the user is not an active member
 * of, so the request body cannot be used to act as an arbitrary company — the
 * validation below is only there to produce a readable error instead of a
 * silent no-op.
 */
class CompanyContextController extends Controller
{
    public function __construct(private readonly B2bContext $context) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'b2b_id' => ['required', 'uuid', Rule::in(
                array_map(fn ($membership) => $membership->b2bId, $this->context->memberships($user))
            )],
        ], [
            'b2b_id.in' => 'Sie gehören diesem Unternehmen nicht an.',
        ]);

        if (! $this->context->switchTo($user, $validated['b2b_id'])) {
            return back()->withErrors(['b2b_id' => 'Sie gehören diesem Unternehmen nicht an.']);
        }

        // A full redirect, not `back()`: the previous page was rendered with
        // the other company's data and permissions throughout.
        return to_route('dashboard')->with('success', 'Unternehmen gewechselt.');
    }
}
