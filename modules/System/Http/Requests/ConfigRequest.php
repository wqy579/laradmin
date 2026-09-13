<?php

namespace Modules\System\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class ConfigRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        if ($method === 'store') {
            return $this->getCreateRules();
        }

        if ($method === 'update') {
            return $this->getUpdateRules();
        }

        if (in_array($method, ['batchDelete'])) {
            return [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ];
        }

        if (in_array($method, ['batchUpdateStatus'])) {
            return [
                'ids' => 'required|array',
                'ids.*' => 'integer',
                'status' => 'required|boolean',
            ];
        }

        return [];
    }

    private function getCreateRules(): array
    {
        $itemType = $this->input('item_type', 'config');

        if ($itemType === 'group') {
            return [
                'name' => 'required|string|max:100',
                'parent_id' => 'nullable|integer|exists:system_setting,id',
                'item_type' => 'required|string|in:group,config',
            ];
        }

        return [
            'key' => 'required|string|max:100|unique:system_setting,key',
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:string,text,number,boolean,select,radio,checkbox,file,json',
            'item_type' => 'required|string|in:group,config',
            'parent_id' => 'nullable|integer|exists:system_setting,id',
            'value' => 'nullable|string',
            'description' => 'nullable|string',
            'sort' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];
    }

    private function getUpdateRules(): array
    {
        $id = $this->route('id');
        $itemType = $this->input('item_type');

        if ($itemType === 'group') {
            return [
                'name' => 'sometimes|required|string|max:100',
                'parent_id' => 'nullable|integer|exists:system_setting,id',
            ];
        }

        return [
            'key' => 'sometimes|required|string|max:100|unique:system_setting,key,' . $id,
            'name' => 'sometimes|required|string|max:100',
            'type' => 'sometimes|required|string|in:string,text,number,boolean,select,radio,checkbox,file,json',
            'parent_id' => 'nullable|integer|exists:system_setting,id',
            'value' => 'nullable|string',
            'description' => 'nullable|string',
            'sort' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入名称',
            'key.required' => '请输入配置键',
            'key.unique' => '配置键已存在',
            'type.required' => '请选择类型',
            'type.in' => '类型不正确',
            'item_type.required' => '请选择项目类型',
            'item_type.in' => '项目类型不正确',
            'parent_id.exists' => '父级不存在',
            'ids.required' => '请选择数据',
            'status.required' => '请选择状态',
        ];
    }
}
