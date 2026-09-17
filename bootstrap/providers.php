<?php

return [
    App\Providers\AppServiceProvider::class,
    Modules\Auth\Providers\AuthServiceProvider::class,
    Modules\System\Providers\SystemServiceProvider::class,
    Modules\Stock\Providers\StockServiceProvider::class,
    Modules\Order\Providers\OrderServiceProvider::class,
    Modules\Business\Providers\BusinessServiceProvider::class,
];
