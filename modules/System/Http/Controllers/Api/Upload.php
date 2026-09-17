<?php

namespace Modules\System\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\System\Http\Requests\UploadRequest;
use Modules\System\Services\UploadService;

class Upload extends Controller
{
    protected UploadService $uploadService;

    public function __construct(UploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    /**
     * 上传文件
     */
    public function upload(UploadRequest $request)
    {
        try {
            $file = $request->file('file');
            $directory = $request->input('directory', 'uploads');
            $result = $this->uploadService->upload($file, $directory);

            return $this->success($result, '上传成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量上传
     */
    public function uploadMultiple(UploadRequest $request)
    {
        try {
            $files = $request->file('files');
            $directory = $request->input('directory', 'uploads');

            $results = $this->uploadService->uploadMultiple($files, $directory);

            return $this->success($results, '上传成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Base64上传
     */
    public function uploadBase64(UploadRequest $request)
    {
        try {
            $base64 = $request->input('base64');
            $directory = $request->input('directory', 'uploads');
            $fileName = $request->input('file_name');

            $result = $this->uploadService->uploadBase64($base64, $directory, $fileName);

            return $this->success($result, '上传成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
