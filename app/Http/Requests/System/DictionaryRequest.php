<?php

namespace App\Http\Requests\System;

use App\Http\Requests\BaseFormRequest;

class DictionaryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $method = $this->route()->getActionMethod();

        return match ($method) {
            'store' => [
                'name' => 'required|string|max:100',
                'code' => 'required|string|max:50|unique:system_dictionary,code',
                'description' => 'nullable|string',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|boolean',
            ],
            'update' => [
                'name' => 'sometimes|required|string|max:100',
                'code' => 'sometimes|required|string|max:50|unique:system_dictionary,code,' . $this->route('id'),
                'description' => 'nullable|string',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|boolean',
            ],
            'storeItem' => [
                'dictionary_id' => 'required|exists:system_dictionary,id',
                'label' => 'required|string|max:100',
                'value' => 'required|string|max:100',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|boolean',
                'description' => 'nullable|string|max:200',
            ],
            'updateItem' => [
                'dictionary_id' => 'sometimes|required|exists:system_dictionary,id',
                'label' => 'sometimes|required|string|max:100',
                'value' => 'sometimes|required|string|max:100',
                'sort' => 'nullable|integer|min:0',
                'status' => 'nullable|boolean',
                'description' => 'nullable|string|max:200',
            ],
            'batchDelete', 'batchDeleteItems' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ],
            'batchUpdateStatus', 'batchUpdateItemsStatus' => [
                'ids' => 'required|array',
                'ids.*' => 'integer',
                'status' => 'required|boolean',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入名称',
            'code.required' => '请输入编码',
            'code.unique' => '编码已存在',
            'dictionary_id.required' => '请选择字典',
            'dictionary_id.exists' => '字典不存在',
            'label.required' => '请输入标签',
            'value.required' => '请输入值',
            'ids.required' => '请选择数据',
            'status.required' => '请选择状态',
        ];
    }
}
