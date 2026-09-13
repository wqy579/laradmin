<?php

namespace Modules\System\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class WebSocketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'checkOnline', 'disconnectUser' => [
                'user_id' => 'required|integer',
            ],
            'sendToUser' => [
                'user_id' => 'required|integer',
                'type' => 'required|string',
                'data' => 'required|array',
            ],
            'sendToUsers' => [
                'user_ids' => 'required|array',
                'user_ids.*' => 'integer',
                'type' => 'required|string',
                'data' => 'required|array',
            ],
            'broadcast' => [
                'type' => 'required|string',
                'data' => 'required|array',
                'exclude_user_id' => 'nullable|integer',
            ],
            'sendToChannel' => [
                'channel' => 'required|string',
                'type' => 'required|string',
                'data' => 'required|array',
            ],
            'sendNotification' => [
                'title' => 'required|string|max:255',
                'message' => 'required|string|max:1000',
                'type' => 'nullable|string|in:info,success,warning,error',
                'extra_data' => 'nullable|array',
            ],
            'sendNotificationToUsers' => [
                'user_ids' => 'required|array',
                'user_ids.*' => 'integer',
                'title' => 'required|string|max:255',
                'message' => 'required|string|max:1000',
                'type' => 'nullable|string|in:info,success,warning,error',
                'extra_data' => 'nullable|array',
            ],
            'pushDataUpdate' => [
                'user_ids' => 'required|array',
                'user_ids.*' => 'integer',
                'resource_type' => 'required|string',
                'action' => 'required|string|in:create,update,delete',
                'data' => 'required|array',
            ],
            'pushDataUpdateToChannel' => [
                'channel' => 'required|string',
                'resource_type' => 'required|string',
                'action' => 'required|string|in:create,update,delete',
                'data' => 'required|array',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'user_id.required' => '请输入用户ID',
            'user_ids.required' => '请输入用户ID列表',
            'type.required' => '请输入消息类型',
            'data.required' => '请输入消息数据',
            'channel.required' => '请输入频道名',
            'title.required' => '请输入标题',
            'message.required' => '请输入消息内容',
            'resource_type.required' => '请输入资源类型',
            'action.required' => '请输入操作类型',
            'action.in' => '操作类型不正确',
        ];
    }
}
