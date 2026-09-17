<?php

// +----------------------------------------------------------------------
// | SentCMS [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2024 http://www.tensent.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: molong <molong@tensent.cn> <http://www.tensent.cn>
// +----------------------------------------------------------------------

namespace App\Traits;

use Illuminate\Support\Facades\Schema;

trait ModelTrait
{
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
            'deleted_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    protected function serializeDate($data)
    {
        return $data->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
    }

    /**
     * 过滤移除非当前表的字段参数
     */
    public function setFilterFields(array $params): array
    {
        $fields = Schema::getColumnListing($this->getTable());
        foreach ($params as $key => $param) {
            if (! in_array($key, $fields)) {
                unset($params[$key]);
            }
        }
        // 同时过滤时间戳字段【时间戳只允许自动更改，不允许手动设置】
        if ($this->timestamps === true && isset($params[self::CREATED_AT])) {
            unset($params[self::CREATED_AT]);
        }
        if ($this->timestamps === true && isset($params[self::UPDATED_AT])) {
            unset($params[self::UPDATED_AT]);
        }

        return $params;
    }
}
