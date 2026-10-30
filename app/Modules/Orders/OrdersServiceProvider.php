<?php

namespace App\Modules\Orders;

use App\Modules\ModuleServiceProvider;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
