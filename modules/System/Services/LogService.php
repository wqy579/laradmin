<?php

namespace Modules\System\Services;

use Modules\System\Models\Log;
use Illuminate\Support\Facades\Auth;

class LogService
{
    public function create(array $data): Log
    {
        return Log::create($data);
    }

    public function getList(array $params): array
    {
        $query = $this->buildQuery($params);

        $query->orderBy('created_at', 'desc');

        $pageSize = $params['page_size'] ?? 20;
        $list = $query->paginate($pageSize);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    /**
     * 构建查询（用于导出等场景）
     *
     * @param  array  $params
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getListQuery(array $params)
    {
        return $this->buildQuery($params);
    }

    /**
     * 构建基础查询
     *
     * @param  array  $params
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildQuery(array $params)
    {
        $query = Log::query()->with('user:id,username');

        if (!empty($params['user_id'])) {
            $query->where('user_id', $params['user_id']);
        }

        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . $params['username'] . '%');
        }

        if (!empty($params['module'])) {
            $query->where('module', $params['module']);
        }

        if (!empty($params['action'])) {
            $query->where('action', $params['action']);
        }

        if (!empty($params['method'])) {
            $query->where('method', $params['method']);
        }

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (!empty($params['start_date']) && !empty($params['end_date'])) {
            $query->whereBetween('created_at', [$params['start_date'], $params['end_date']]);
        }

        if (!empty($params['ip'])) {
            $query->where('ip', 'like', '%' . $params['ip'] . '%');
        }

        return $query;
    }

    public function getById(int $id): ?Log
    {
        return Log::with('user')->find($id);
    }

    public function delete(int $id): bool
    {
        $log = Log::findOrFail($id);
        return $log->delete();
    }

    public function clearLogs(string $days = '30'): bool
    {
        Log::where('created_at', '<', now()->subDays($days))->delete();
        return true;
    }

    public function batchDelete(array $ids): bool
    {
        Log::whereIn('id', $ids)->delete();
        return true;
    }

    public function getStatistics(array $params = []): array
    {
        $query = Log::query();

        if (!empty($params['method'])) {
            $query->where('method', $params['method']);
        }

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (!empty($params['start_date']) && !empty($params['end_date'])) {
            $query->whereBetween('created_at', [$params['start_date'], $params['end_date']]);
        }

        $total = $query->count();
        $successCount = (clone $query)->where('status', 'success')->count();
        $errorCount = (clone $query)->where('status', 'error')->count();

        // 计算平均响应时间
        $avgTime = (clone $query)->avg('execution_time');
        $avgTime = $avgTime ? round($avgTime, 2) : 0;

        return [
            'total' => $total,
            'success' => $successCount,
            'error' => $errorCount,
            'avg_time' => $avgTime,
        ];
    }

    /**
     * 导出日志
     *
     * @param  array  $params
     * @return string
     */
    public function export(array $params): string
    {
        $query = $this->buildQuery($params);
        $query->orderBy('created_at', 'desc');

        $logs = $query->limit(10000)->get();

        // 创建导出数据
        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'ID' => $log->id,
                '用户名' => $log->username,
                '模块' => $log->module,
                '操作' => $log->action,
                '请求方式' => $log->method,
                'URL' => $log->url,
                'IP地址' => $log->ip,
                '状态码' => $log->status_code,
                '状态' => $log->status === 'success' ? '成功' : '失败',
                '执行时间' => $log->execution_time . 'ms',
                '创建时间' => $log->created_at,
            ];
        }

        // 生成CSV文件
        $filename = '系统日志_' . date('YmdHis') . '.csv';
        $filepath = storage_path('app/exports/' . $filename);

        // 确保目录存在
        if (!file_exists(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        $file = fopen($filepath, 'w');
        // 添加BOM以支持Excel中文显示
        fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // 写入表头
        if (!empty($data)) {
            fputcsv($file, array_keys($data[0]));

            // 写入数据
            foreach ($data as $row) {
                fputcsv($file, $row);
            }
        }

        fclose($file);

        return $filepath;
    }
}
