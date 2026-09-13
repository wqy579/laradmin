<?php

namespace App\Http\Requests\System;

use App\Http\Requests\BaseFormRequest;
use App\Models\System\DictionaryItem;
use App\Models\System\Dictionary;

class NotificationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'batchMarkAsRead' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ],
            'batchDelete' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ],
            'send' => $this->sendRules(),
            default => [],
        };
    }

    protected function sendRules(): array
    {
        $typeValues = $this->getDictionaryValues('notification_type');
        $categoryValues = $this->getDictionaryValues('notification_category');

        return [
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'type' => 'required|string|in:' . implode(',', $typeValues),
            'category' => 'nullable|string|in:' . implode(',', $categoryValues),
            'data' => 'nullable|array',
            'action_type' => 'nullable|string|in:link,modal,none',
            'action_data' => 'nullable|array',
        ];
    }

    protected function getDictionaryValues(string $code): array
    {
        $dictionary = Dictionary::where('code', $code)->first();
        if (!$dictionary) {
            // 回退到模型常量
            return $code === 'notification_type'
                ? ['info', 'success', 'warning', 'error', 'task', 'system']
                : ['system', 'task', 'message', 'reminder', 'announcement'];
        }

        return DictionaryItem::where('dictionary_id', $dictionary->id)
            ->where('status', true)
            ->pluck('value')
            ->toArray();
    }

    public function messages(): array
    {
        return [
            'ids.required' => '请选择通知',
            'title.required' => '请输入标题',
            'content.required' => '请输入内容',
            'type.required' => '请选择类型',
            'type.in' => '类型不正确',
            'category.in' => '分类不正确',
        ];
    }
}
