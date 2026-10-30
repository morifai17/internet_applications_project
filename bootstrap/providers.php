<?php

use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Products\ProductsServiceProvider;
use App\Modules\Users\UsersServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    UsersServiceProvider::class,
    ProductsServiceProvider::class,
    OrdersServiceProvider::class,
    NotificationsServiceProvider::class,
];
