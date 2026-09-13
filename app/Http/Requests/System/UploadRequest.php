<?php

namespace App\Http\Requests\System;

use App\Http\Requests\BaseFormRequest;

class UploadRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'upload' => [
                'file' => 'required|file|max:10240',
            ],
            'uploadMultiple' => [
                'files' => 'required|array',
                'files.*' => 'file|max:10240',
            ],
            'uploadBase64' => [
                'base64' => 'required|string',
            ],
            'delete' => [
                'path' => 'required|string',
            ],
            'batchDelete' => [
                'paths' => 'required|array',
            ],
            'initChunk' => [
                'file_name' => 'required|string|max:255',
                'file_size' => 'required|integer|min:1',
                'file_hash' => 'required|string|size:32',
                'chunk_size' => 'sometimes|integer|min:1048576',
                'directory' => 'sometimes|string|max:100',
            ],
            'uploadChunk' => [
                'upload_id' => 'required|string',
                'chunk_index' => 'required|integer|min:0',
                'chunk' => 'required|file|max:10240',
            ],
            'mergeChunks' => [
                'upload_id' => 'required|string',
            ],
            'getUploadedChunks' => [
                'upload_id' => 'required|string',
            ],
            'cancelChunk' => [
                'upload_id' => 'required|string',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'file.required' => '请上传文件',
            'file.max' => '文件大小不能超过10MB',
            'files.required' => '请上传文件',
            'base64.required' => '请输入Base64数据',
            'path.required' => '请输入文件路径',
            'paths.required' => '请输入文件路径列表',
            'upload_id.required' => '请输入上传ID',
            'file_name.required' => '请输入文件名',
            'file_hash.size' => '文件哈希值必须是32位',
        ];
    }
}
