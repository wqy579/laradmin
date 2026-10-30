<?php

namespace Modules\Mall\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * F5 行程地图。
 *
 * 数据源（只读复用，不改写他人模块）：
 *   - visit_logs.checkin_lat/lng —— 业务员当日拜访轨迹点；
 *   - customers.latitude/longitude —— 配送点位（标记点）。
 *
 * 本控制器只做聚合查询，前端负责把经纬度归一化到画布画折线（#409EFF）
 * 并驱动「回放控件（播放/暂停/1x/2x）」。
 */
class MapController extends Controller
{
    /** 轨迹点渲染阈值：超过即抽稀，避免前端一次性渲染卡死（F5-HARDENING 第 3 项） */
    public const MAX_TRAJECTORY_POINTS = 1000;

    /** 业务员下拉列表：有拜访日志的业务员（去重） */
    public function salesmen(Request $request)
    {
        $date = $request->input('date') ?? now()->toDateString();

        $rows = DB::table('visit_logs as vl')
            ->join('auth_user as u', 'u.id', '=', 'vl.employee_id')
            ->leftJoin('employees as e', 'e.user_id', '=', 'vl.employee_id')
            ->whereDate('vl.checkin_time', $date)
            ->groupBy('vl.employee_id', 'u.real_name', 'u.username', 'e.code')
            ->orderBy('u.real_name')
            ->get([
                'vl.employee_id',
                'u.real_name as name',
                'u.username as username',
                DB::raw('COALESCE(e.code, u.username) as code'),
            ])
            ->map(fn ($r) => [
                'id' => (int) $r->employee_id,
                'name' => $r->name ?: $r->username,
                'code' => $r->code,
            ])
            ->values();

        return $this->success(['date' => $date, 'salesmen' => $rows]);
    }

    /** 当日轨迹：拜访点序列（含经纬度标记） */
    public function trajectory(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $points = DB::table('visit_logs as vl')
            ->leftJoin('customers as c', 'c.id', '=', 'vl.customer_id')
            ->leftJoin('auth_user as u', 'u.id', '=', 'vl.employee_id')
            ->where('vl.employee_id', (int) $validated['employee_id'])
            ->whereDate('vl.checkin_time', $date)
            ->orderBy('vl.checkin_time')
            ->get([
                'vl.id', 'vl.visit_no', 'vl.checkin_time', 'vl.checkout_time',
                'vl.checkin_lat', 'vl.checkin_lng', 'vl.checkin_address',
                'vl.visit_duration', 'vl.visit_result', 'vl.remark',
                'c.name as customer_name', 'c.code as customer_code',
                'c.address as customer_address', 'c.latitude as customer_lat', 'c.longitude as customer_lng',
                'u.real_name as employee_name',
            ])
            ->map(function ($p, $index) {
                $p->seq = $index + 1;
                // 轨迹点用拜访定位；缺拜访定位则回落客户档案坐标。
                // 统一 cast 成 float：MySQL DECIMAL 列读出来是 '30.8800000' 字符串，
                // SQLite 是原生 float，两端类型不一致会让前端拿不到数字。
                $p->lat = $p->checkin_lat !== null ? (float) $p->checkin_lat : ($p->customer_lat !== null ? (float) $p->customer_lat : null);
                $p->lng = $p->checkin_lng !== null ? (float) $p->checkin_lng : ($p->customer_lng !== null ? (float) $p->customer_lng : null);
                $p->has_location = $p->lat !== null && $p->lng !== null;

                return $p;
            })->values();

        // 大数据量抽稀：超过阈值按等距抽稀保留首尾点，避免前端一次性渲染卡死。
        // 返回原始总数与是否抽稀标记，前端据此提示（不丢首尾关键点）。
        $totalCount = $points->count();
        $downsampled = false;
        $rendered = $points;
        if ($totalCount > self::MAX_TRAJECTORY_POINTS) {
            $downsampled = true;
            $step = (int) ceil($totalCount / self::MAX_TRAJECTORY_POINTS);
            $kept = [];
            foreach ($points as $i => $p) {
                if ($i % $step === 0 || $i === $totalCount - 1) {
                    $kept[] = $p;
                }
            }
            // 抽稀后重编号 seq：前端回放以数组下标（seq-1）驱动，必须连续。
            $rendered = collect($kept)->values()->map(function ($p, $idx) {
                $p->seq = $idx + 1;

                return $p;
            });
        }

        return $this->success([
            'date' => $date,
            'employee_name' => $points->first()->employee_name ?? '未知',
            'points' => $rendered,
            'located_count' => $points->where('has_location', true)->count(),
            'total_count' => $totalCount,
            'rendered_count' => $rendered->count(),
            'downsampled' => $downsampled,
            'max_points' => self::MAX_TRAJECTORY_POINTS,
        ]);
    }

    /** 配送点位：全部带坐标的客户（标记点底图） */
    public function customers()
    {
        $rows = DB::table('customers')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '<>', 0)
            ->where('longitude', '<>', 0)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'address', 'latitude', 'longitude'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'address' => $c->address,
                'lat' => (float) $c->latitude,
                'lng' => (float) $c->longitude,
            ])
            ->values();

        return $this->success(['customers' => $rows]);
    }
}
