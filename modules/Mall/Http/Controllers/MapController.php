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
                // 轨迹点用拜访定位；缺拜访定位则回落客户档案坐标
                $p->lat = $p->checkin_lat ?? $p->customer_lat;
                $p->lng = $p->checkin_lng ?? $p->customer_lng;
                $p->has_location = $p->lat !== null && $p->lng !== null;

                return $p;
            })->values();

        return $this->success([
            'date' => $date,
            'employee_name' => $points->first()->employee_name ?? '未知',
            'points' => $points,
            'located_count' => $points->where('has_location', true)->count(),
            'total_count' => $points->count(),
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
