<?php

namespace Modules\System\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class AttachmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'getByIds' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ],
            'batchDelete' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ],
            'update' => [
                'description' => 'nullable|string|max:500',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'ids.required' => '请选择附件',
            'ids.array' => '附件ID格式不正确',
        ];
    }
}
