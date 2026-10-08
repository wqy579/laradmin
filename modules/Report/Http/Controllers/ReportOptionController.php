<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 报表筛选项的数据源。
 *
 * 商品 / 客户这类大表不整表下发（几千行下拉会把页面拖死），
 * 只在传了 keyword 时按编码或名称模糊匹配返回前 50 条；
 * 仓库、品牌、分类这类字典级小表才整表返回。
 *
 * 枚举类选项（销售类型、单据来源、日期类型）直接在这里定义，
 * 前后端共用同一份文案，避免下拉里显示的和后端判等的不是一套值。
 */
class ReportOptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keyword = trim((string) $request->input('keyword', ''));

        return $this->success([
            'date_types' => $this->dateTypes(),
            'sale_types' => [
                ['value' => 'normal', 'label' => '正常销售'],
                ['value' => 'promotion', 'label' => '促销销售'],
                ['value' => 'special', 'label' => '特价销售'],
            ],
            'sources' => [
                ['value' => 'admin', 'label' => '后台录单'],
                ['value' => 'miniapp', 'label' => '小程序下单'],
                ['value' => 'app', 'label' => 'APP 下单'],
                ['value' => 'import', 'label' => '导入'],
            ],
            'zero_sale_options' => [
                ['value' => '', 'label' => '不显示零销售'],
                ['value' => 'append', 'label' => '追加显示零销售'],
                ['value' => 'only', 'label' => '仅显示零销售'],
            ],
            'warehouses' => DB::table('warehouses')->where('is_active', 1)->orderBy('code')->get(['id', 'code', 'name']),
            'brands' => DB::table('brands')->where('is_active', 1)->orderBy('sort')->orderBy('name')->get(['id', 'code', 'name']),
            'main_categories' => DB::table('product_categories')->where('is_main', 1)->where('is_active', 1)->orderBy('sort_order')->get(['id', 'name']),
            'sub_categories' => DB::table('product_categories')->where('is_main', 0)->where('is_active', 1)->orderBy('sort_order')->get(['id', 'name']),
            'customer_levels' => DB::table('customer_levels')->where('status', 1)->orderBy('sort')->get(['id', 'code', 'name']),
            'customer_categories' => $this->distinctValues('customers', 'category'),
            'channel_categories' => $this->distinctValues('customers', 'channel_category'),
            'channel_sub_categories' => $this->distinctValues('customers', 'channel_sub_category'),
            'payment_methods' => $this->distinctValues('receives', 'payment_method'),
            'salesmen' => $this->users(),
            'delivery_persons' => $this->users(),
            'products' => $keyword !== '' ? $this->searchProducts($keyword) : [],
            'customers' => $keyword !== '' ? $this->searchCustomers($keyword) : [],
        ]);
    }

    /**
     * 日期类型：把「方案里的叫法」和「实际生效的字段」一起回传，
     * 前端下拉文案用 label，鼠标悬停用 desc 说明它落在哪个字段上，不用猜。
     */
    private function dateTypes(): array
    {
        return [
            ['value' => 'payment', 'label' => '付款日期', 'desc' => '收款单 receives.receive_date'],
            ['value' => 'declare', 'label' => '申报日期', 'desc' => '销售订单 order_date'],
            ['value' => 'dispatch', 'label' => '配货日期', 'desc' => '销售订单 dispatch_date'],
            ['value' => 'reconcile', 'label' => '对账日期', 'desc' => '销售订单 reconcile_date'],
            ['value' => 'returned', 'label' => '回款日期', 'desc' => '资金流水 cash_flows.flow_date'],
        ];
    }

    /** 取某张表里该字段的非空取值，作为下拉选项（无需维护字典的小枚举） */
    private function distinctValues(string $table, string $column): array
    {
        return DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->limit(200)
            ->pluck($column)
            ->map(fn ($v) => ['value' => $v, 'label' => $v])
            ->all();
    }

    /** 业务员 / 跟车配送员 / 操作人 / 审核人 都取自后台用户表 */
    private function users(): array
    {
        return DB::table('auth_user')
            ->where('status', 1)
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'username', 'real_name'])
            ->map(fn ($u) => ['value' => $u->id, 'label' => $u->real_name ?: $u->username])
            ->all();
    }

    private function searchProducts(string $keyword): array
    {
        return DB::table('products')
            ->where('is_active', 1)
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
            })
            ->orderBy('code')
            ->limit(50)
            ->get(['id', 'code', 'name', 'spec'])
            ->all();
    }

    private function searchCustomers(string $keyword): array
    {
        return DB::table('customers')
            ->where('is_active', 1)
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
            })
            ->orderBy('code')
            ->limit(50)
            ->get(['id', 'code', 'name'])
            ->all();
    }
}
