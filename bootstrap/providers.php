<?php

use App\Providers\AppServiceProvider;
use Modules\Auth\Providers\AuthServiceProvider;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Miniapp\Providers\MiniappServiceProvider;
use Modules\Office\Providers\OfficeServiceProvider;
use Modules\Order\Providers\OrderServiceProvider;
use Modules\Report\Providers\ReportServiceProvider;
use Modules\Stock\Providers\StockServiceProvider;
use Modules\System\Providers\SystemServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    SystemServiceProvider::class,
    StockServiceProvider::class,
    OrderServiceProvider::class,
    BusinessServiceProvider::class,
    ReportServiceProvider::class,
    OfficeServiceProvider::class,
    MiniappServiceProvider::class,
];
