<?php

namespace App\Http\Controllers\System\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\UploadRequest;
use App\Services\System\UploadService;
use Illuminate\Http\Request;

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
            $options = [
                'user_id' => $request->input('user_id'),
                'description' => $request->input('description'),
            ];

            $result = $this->uploadService->upload($file, $directory, $options);
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
            $options = [
                'user_id' => $request->input('user_id'),
            ];

            $results = $this->uploadService->uploadMultiple($files, $directory, $options);
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
            $options = [
                'user_id' => $request->input('user_id'),
            ];

            $result = $this->uploadService->uploadBase64($base64, $directory, $fileName, $options);
            return $this->success($result, '上传成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除文件
     */
    public function delete(UploadRequest $request)
    {
        try {
            $path = $request->input('path');
            $driver = $request->input('driver');
            $this->uploadService->deleteFile($path, $driver);
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量删除文件
     */
    public function batchDelete(UploadRequest $request)
    {
        try {
            $paths = $request->input('paths', []);
            $driver = $request->input('driver');
            foreach ($paths as $path) {
                $this->uploadService->deleteFile($path, $driver);
            }
            return $this->success(null, '批量删除成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 初始化切片上传
     */
    public function initChunk(UploadRequest $request)
    {
        try {
            $result = $this->uploadService->initChunkUpload(
                $request->input('file_name'),
                $request->input('file_size'),
                $request->input('file_hash'),
                $request->input('chunk_size', 2 * 1024 * 1024),
                $request->input('directory', 'uploads'),
                [
                    'user_id' => $request->input('user_id'),
                    'description' => $request->input('description'),
                ]
            );

            if (isset($result['uploaded']) && $result['uploaded']) {
                return $this->success([
                    'uploaded' => true,
                    'data' => $result['data'],
                ], '文件已存在，秒传成功');
            }

            return $this->success($result, '初始化成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 上传单个分片
     */
    public function uploadChunk(UploadRequest $request)
    {
        try {
            $result = $this->uploadService->uploadChunk(
                $request->input('upload_id'),
                $request->input('chunk_index'),
                $request->file('chunk')
            );

            return $this->success($result, '分片上传成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 合并分片
     */
    public function mergeChunks(UploadRequest $request)
    {
        try {
            $result = $this->uploadService->mergeChunks($request->input('upload_id'));
            return $this->success($result, '文件合并成功');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取已上传的分片（断点续传）
     */
    public function getUploadedChunks(UploadRequest $request)
    {
        try {
            $result = $this->uploadService->getUploadedChunks($request->input('upload_id'));
            return $this->success($result);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 取消切片上传
     */
    public function cancelChunk(UploadRequest $request)
    {
        try {
            $this->uploadService->cancelChunkUpload($request->input('upload_id'));
            return $this->success(null, '已取消上传');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
