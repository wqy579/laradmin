<?php

namespace Modules\System\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\System\Http\Requests\AttachmentRequest;
use Modules\System\Services\AttachmentService;

class Attachment extends Controller
{
    protected AttachmentService $attachmentService;

    public function __construct(AttachmentService $attachmentService)
    {
        $this->attachmentService = $attachmentService;
    }

    /**
     * 附件列表
     */
    public function index(Request $request)
    {
        $result = $this->attachmentService->getList($request->all());

        return $this->success($result);
    }

    /**
     * 附件详情
     */
    public function show(int $id)
    {
        $attachment = $this->attachmentService->getById($id);
        if (! $attachment) {
            return $this->notFound('附件不存在');
        }

        return $this->success($attachment);
    }

    /**
     * 根据ID列表获取附件信息
     */
    public function getByIds(AttachmentRequest $request)
    {
        $validated = $request->validated();
        $attachments = $this->attachmentService->getByIds($validated['ids']);

        return $this->success($attachments);
    }

    /**
     * 删除附件
     */
    public function destroy(int $id)
    {
        try {
            $this->attachmentService->delete($id);

            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量删除附件
     */
    public function batchDelete(AttachmentRequest $request)
    {
        $validated = $request->validated();
        $this->attachmentService->batchDelete($validated['ids']);

        return $this->success(null, '批量删除成功');
    }

    /**
     * 更新附件描述
     */
    public function update(AttachmentRequest $request, int $id)
    {
        try {
            $validated = $request->validated();
            $attachment = $this->attachmentService->updateDescription(
                $id,
                $validated['description'] ?? ''
            );

            return $this->success($attachment, '更新成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 按日期获取文件夹列表
     */
    public function directories(Request $request)
    {
        $dirs = $this->attachmentService->getDateDirectories($request->only(['type']));

        return $this->success($dirs);
    }

    /**
     * 附件统计信息
     */
    public function statistics(Request $request)
    {
        $stats = $this->attachmentService->getStatistics($request->only(['type']));

        return $this->success($stats);
    }

    /**
     * 获取文件类型分布
     */
    public function typeDistribution()
    {
        $distribution = $this->attachmentService->getTypeDistribution();

        return $this->success($distribution);
    }
}
