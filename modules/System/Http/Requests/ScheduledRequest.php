<?php

namespace Modules\System\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Modules\System\Models\Scheduled;

class ScheduledRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        if (in_array($method, ['store', 'update'])) {
            return [
                'name' => 'required|string|max:100',
                'command' => 'required|string|max:255',
                'type' => 'required|string|in:'.implode(',', [Scheduled::TYPE_ARTISAN, Scheduled::TYPE_JOB, Scheduled::TYPE_SHELL]),
                'expression' => 'nullable|string|max:100',
                'interval' => 'nullable|integer|min:1|max:86400',
                'timezone' => 'sometimes|string|max:50',
                'timeout' => 'sometimes|integer|min:1|max:3600',
                'without_overlapping' => 'sometimes|boolean',
                'max_tries' => 'sometimes|integer|min:1|max:10',
                'parameters' => 'sometimes|array',
                'description' => 'nullable|string|max:500',
                'status' => 'nullable|integer|in:0,1',
            ];
        }

        if ($method === 'batchDelete') {
            return [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ];
        }

        return [];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入任务名称',
            'command.required' => '请输入执行命令',
            'type.required' => '请选择任务类型',
            'type.in' => '任务类型不正确',
            'ids.required' => '请选择数据',
        ];
    }
}
