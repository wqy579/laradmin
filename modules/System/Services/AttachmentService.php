<?php

namespace Modules\System\Services;

use Modules\System\Models\Attachment;
use Illuminate\Support\Facades\Validator;

class AttachmentService
{
    public function __construct(
        protected UploadService $uploadService,
        protected StorageService $storageService,
    ) {}

    /**
     * 获取附件列表
     */
    public function getList(array $params): array
    {
        $query = Attachment::query();

        // 关键词搜索
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['keyword'] . '%')
                    ->orWhere('file_name', 'like', '%' . $params['keyword'] . '%');
            });
        }

        // 文件类型筛选
        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        // 扩展名筛选
        if (!empty($params['extension'])) {
            $query->where('extension', $params['extension']);
        }

        // 存储驱动筛选
        if (!empty($params['storage_driver'])) {
            $query->where('storage_driver', $params['storage_driver']);
        }

        // 上传用户筛选
        if (!empty($params['user_id'])) {
            $query->where('user_id', $params['user_id']);
        }

        // 按日期目录筛选
        if (!empty($params['date'])) {
            $query->whereDate('created_at', $params['date']);
        }

        // 日期范围
        if (!empty($params['start_date'])) {
            $query->where('created_at', '>=', $params['start_date']);
        }
        if (!empty($params['end_date'])) {
            $query->where('created_at', '<=', $params['end_date']);
        }

        $pageSize = $params['page_size'] ?? 20;

        // 排序
        $orderBy = $params['order_by'] ?? 'id';
        $orderDirection = $params['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        $list = $query->paginate($pageSize);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    /**
     * 获取附件详情
     */
    public function getById(int $id): ?Attachment
    {
        return Attachment::find($id);
    }

    /**
     * 根据ID列表获取附件
     */
    public function getByIds(array $ids): array
    {
        return Attachment::whereIn('id', $ids)->get()->toArray();
    }

    /**
     * 获取按日期分组的目录列表
     */
    public function getDateDirectories(array $params = []): array
    {
        $query = Attachment::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(size) as total_size');

        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        $query->groupBy('date')->orderBy('date', 'desc');

        return $query->get()->toArray();
    }

    /**
     * 删除附件
     */
    public function delete(int $id): bool
    {
        return $this->uploadService->deleteAttachment($id);
    }

    /**
     * 批量删除附件
     */
    public function batchDelete(array $ids): bool
    {
        return $this->uploadService->deleteAttachments($ids);
    }

    /**
     * 获取附件统计信息
     */
    public function getStatistics(array $params = []): array
    {
        $query = Attachment::query();

        if (!empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        $total = $query->count();
        $totalSize = (clone $query)->sum('size');

        $todayQuery = (clone $query)->whereDate('created_at', today());
        $todayCount = $todayQuery->count();
        $todaySize = (clone $todayQuery)->sum('size');

        return [
            'total' => $total,
            'total_size' => $totalSize,
            'today_count' => $todayCount,
            'today_size' => $todaySize,
        ];
    }

    /**
     * 获取文件类型分布
     */
    public function getTypeDistribution(): array
    {
        return Attachment::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
    }

    /**
     * 更新附件描述
     */
    public function updateDescription(int $id, string $description): Attachment
    {
        $attachment = Attachment::findOrFail($id);
        $attachment->update(['description' => $description]);
        return $attachment;
    }
}
