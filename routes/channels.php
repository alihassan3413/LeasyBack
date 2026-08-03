<?php

use App\Models\LeasybackOrder;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * The live feed for one order's message thread. Same OrderPolicy ability the
 * HTTP endpoints check, so a user can never subscribe to a thread they could
 * not have fetched — the socket is not a second, weaker door.
 */
Broadcast::channel('orders.{orderId}.messages', function (User $user, string $orderId) {
    $order = LeasybackOrder::find($orderId);

    return $order !== null && $user->can('viewMessages', $order);
});
