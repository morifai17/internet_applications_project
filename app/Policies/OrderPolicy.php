<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Customers may only see their own orders. Administrators may see every order
 * (admin routes are additionally protected by the "admin" middleware).
 */
final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->isAdmin() || $order->user_id === $user->id;
    }
}
