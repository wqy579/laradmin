<?php

use App\Providers\AppServiceProvider;
use Modules\Agreement\Providers\AgreementServiceProvider;
use Modules\Archive\Providers\ArchiveServiceProvider;
use Modules\Asset\Providers\AssetServiceProvider;
use Modules\Auth\Providers\AuthServiceProvider;
use Modules\BorrowReturn\Providers\BorrowReturnServiceProvider;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Cash\Providers\CashServiceProvider;
use Modules\Dashboard\Providers\DashboardServiceProvider;
use Modules\Delivery\Providers\DeliveryServiceProvider;
use Modules\Exchange\Providers\ExchangeServiceProvider;
use Modules\Hr\Providers\HrServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;
use Modules\Mall\Providers\MallServiceProvider;
use Modules\Member\Providers\MemberServiceProvider;
use Modules\Miniapp\Providers\MiniappServiceProvider;
use Modules\Office\Providers\OfficeServiceProvider;
use Modules\Order\Providers\OrderServiceProvider;
use Modules\Performance\Providers\PerformanceServiceProvider;
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
    LedgerServiceProvider::class,
    MemberServiceProvider::class,
    DashboardServiceProvider::class,
    PerformanceServiceProvider::class,
    CashServiceProvider::class,
    HrServiceProvider::class,
    AgreementServiceProvider::class,
    AssetServiceProvider::class,
    ArchiveServiceProvider::class,
    MallServiceProvider::class,
];
