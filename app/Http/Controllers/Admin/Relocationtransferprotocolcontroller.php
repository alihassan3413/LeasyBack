<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\RelocationTransferProtocolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST admin/orders/{orderId}/transfer-protocol
 *
 * Saves an Überführung's Übergabeprotokoll as a link or PDF, which completes
 * the order. Admin-only through the route's admin middleware group.
 */
class RelocationTransferProtocolController extends Controller
{
    public function store(Request $request, string $orderId, RelocationTransferProtocolService $service): RedirectResponse
    {
        $order = LeasybackOrder::findOrFail($orderId);

        $validated = $request->validate(RelocationTransferProtocolService::rules());

        $service->save($order, $request->user(), $validated['url'] ?? null, $request->file('file'));

        return back()->with('success', 'Übergabeprotokoll gespeichert — die Überführung ist abgeschlossen.');
    }
}