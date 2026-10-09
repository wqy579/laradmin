<?php

use App\Providers\AppServiceProvider;
use Modules\Auth\Providers\AuthServiceProvider;
use Modules\BorrowReturn\Providers\BorrowReturnServiceProvider;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Dashboard\Providers\DashboardServiceProvider;
use Modules\Delivery\Providers\DeliveryServiceProvider;
use Modules\Exchange\Providers\ExchangeServiceProvider;
use Modules\Miniapp\Providers\MiniappServiceProvider;
use Modules\Office\Providers\OfficeServiceProvider;
use Modules\Order\Providers\OrderServiceProvider;
use Modules\Report\Providers\ReportServiceProvider;
use Modules\Stock\Providers\StockServiceProvider;
use Modules\System\Providers\SystemServiceProvider;
use Modules\VanSales\Providers\VanSalesServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    SystemServiceProvider::class,
    StockServiceProvider::class,
    OrderServiceProvider::class,
    BusinessServiceProvider::class,
    DeliveryServiceProvider::class,
    ReportServiceProvider::class,
    OfficeServiceProvider::class,
    MiniappServiceProvider::class,
    VanSalesServiceProvider::class,
    BorrowReturnServiceProvider::class,
    ExchangeServiceProvider::class,
    DashboardServiceProvider::class,
];
