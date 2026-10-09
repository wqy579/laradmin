<?php

use App\Providers\AppServiceProvider;
use Modules\Auth\Providers\AuthServiceProvider;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Order\Providers\OrderServiceProvider;
use Modules\Stock\Providers\StockServiceProvider;
use Modules\System\Providers\SystemServiceProvider;
use Modules\Delivery\Providers\DeliveryServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    SystemServiceProvider::class,
    StockServiceProvider::class,
    OrderServiceProvider::class,
    BusinessServiceProvider::class,
    DeliveryServiceProvider::class,
];
