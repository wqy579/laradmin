<?php

namespace Modules\System\Services;

use Illuminate\Support\Facades\Cache;
use Modules\System\Models\Dictionary;
use Modules\System\Models\DictionaryItem;
use Modules\System\Services\WebSocket\WebSocketService;

class DictionaryService
{
    protected $webSocketService;

    public function __construct(WebSocketService $webSocketService)
    {
        $this->webSocketService = $webSocketService;
    }

    public function getList(array $params): array
    {
        $query = Dictionary::query();

        if (! empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%'.$params['keyword'].'%')
                    ->orWhere('code', 'like', '%'.$params['keyword'].'%');
            });
        }

        // 处理状态筛选：只接受布尔值或数字1/0
        if (array_key_exists('status', $params) && is_bool($params['status'])) {
            $query->where('status', $params['status']);
        }

        $pageSize = $params['page_size'] ?? 20;
        $list = $query->orderBy('sort')->orderBy('id')->paginate($pageSize);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    public function getAll(): array
    {
        $cacheKey = 'system:dictionary:all';
        $dictionary = Cache::get($cacheKey);

        if ($dictionary === null) {
            $dictionary = Dictionary::where('status', true)
                ->with(['activeItems'])
                ->orderBy('sort')
                ->get()
                ->toArray();
            Cache::put($cacheKey, $dictionary, 3600);
        }

        return $dictionary;
    }

    public function getById(int $id): ?array
    {
        $cacheKey = 'system:dictionary:'.$id;
        $dictionary = Cache::get($cacheKey);

        if ($dictionary === null) {
            $dictionary = Dictionary::with('items')->find($id);
            if ($dictionary) {
                $dictionary = $dictionary->toArray();
                // 格式化字典项值
                if (! empty($dictionary['items'])) {
                    $dictionary['items'] = $this->formatItemsByType($dictionary['items'], $dictionary['value_type']);
                }
                Cache::put($cacheKey, $dictionary, 3600);
            }
        }

        return $dictionary ?? null;
    }

    public function getByCode(string $code): ?array
    {
        $cacheKey = 'system:dictionary:code:'.$code;
        $dictionary = Cache::get($cacheKey);

        if ($dictionary === null) {
            $dictionaryModel = Dictionary::with('items')->where('code', $code)->first();
            if ($dictionaryModel) {
                $dictionary = $dictionaryModel->toArray();
                // 格式化字典项值
                if (! empty($dictionary['items'])) {
                    $dictionary['items'] = $this->formatItemsByType($dictionary['items'], $dictionary['value_type']);
                }
                Cache::put($cacheKey, $dictionary, 3600);
            } else {
                $dictionary = null;
            }
        }

        return $dictionary;
    }

    public function getItemsByCode(string $code): array
    {
        $cacheKey = 'system:dictionary:'.$code;
        $items = Cache::get($cacheKey);

        if ($items === null) {
            $dictionary = Dictionary::where('code', $code)->first();
            if ($dictionary) {
                $items = DictionaryItem::where('dictionary_id', $dictionary->id)
                    ->where('status', true)
                    ->orderBy('sort')
                    ->get()
                    ->toArray();
                // 格式化字典项值
                $items = $this->formatItemsByType($items, $dictionary->value_type);
                Cache::put($cacheKey, $items, 3600);
            } else {
                $items = [];
            }
        }

        return $items;
    }

    public function create(array $data): Dictionary
    {
        $dictionary = Dictionary::create($data);
        $this->clearCache();
        $this->notifyDictionaryUpdate('create', $dictionary->toArray());

        return $dictionary;
    }

    public function update(int $id, array $data): Dictionary
    {
        $dictionary = Dictionary::findOrFail($id);

        $dictionary->update($data);
        $this->clearCache();
        $this->notifyDictionaryUpdate('update', $dictionary->toArray());

        return $dictionary;
    }

    public function delete(int $id): bool
    {
        $dictionary = Dictionary::findOrFail($id);
        $dictionaryData = $dictionary->toArray();
        DictionaryItem::where('dictionary_id', $id)->delete();
        $dictionary->delete();
        $this->clearCache();
        $this->notifyDictionaryUpdate('delete', $dictionaryData);

        return true;
    }

    public function batchDelete(array $ids): bool
    {
        DictionaryItem::whereIn('dictionary_id', $ids)->delete();
        Dictionary::whereIn('id', $ids)->delete();
        $this->clearCache();
        $this->notifyDictionaryUpdate('batch_delete', ['ids' => $ids]);

        return true;
    }

    public function batchUpdateStatus(array $ids, bool $status): bool
    {
        Dictionary::whereIn('id', $ids)->update(['status' => $status]);
        $this->clearCache();
        $this->notifyDictionaryUpdate('batch_update_status', ['ids' => $ids, 'status' => $status]);

        return true;
    }

    private function clearCache($dictionaryId = null): void
    {
        // 清理所有字典列表缓存
        Cache::forget('system:dictionary:all');

        if ($dictionaryId) {
            // 清理特定字典的缓存
            $dictionary = Dictionary::find($dictionaryId);
            if ($dictionary) {
                Cache::forget('system:dictionary:'.$dictionaryId);
                Cache::forget('system:dictionary:code:'.$dictionary->code);
                Cache::forget('system:dictionary:'.$dictionary->code);
            }
        } else {
            // 清理所有字典缓存
            $codes = Dictionary::pluck('code')->toArray();
            foreach ($codes as $code) {
                Cache::forget('system:dictionary:'.$code);
                Cache::forget('system:dictionary:code:'.$code);
            }
        }
    }

    public function getItem(int $id): ?array
    {
        $item = DictionaryItem::query()->find($id);
        if (! $item) {
            return null;
        }

        return $item->toArray();
    }

    public function getItemsList(array $params): array
    {
        $query = DictionaryItem::query();

        if (! empty($params['dictionary_id'])) {
            $query->where('dictionary_id', $params['dictionary_id']);
        }

        // 处理状态筛选：只接受布尔值或数字1/0
        if (array_key_exists('status', $params) && is_bool($params['status'])) {
            $query->where('status', $params['status']);
        }

        $query->orderBy('sort')->orderBy('id');

        $pageSize = $params['page_size'] ?? 20;
        $list = $query->paginate($pageSize);

        return [
            'list' => $list->items(),
            'total' => $list->total(),
            'page' => $list->currentPage(),
            'page_size' => $list->perPage(),
        ];
    }

    public function createItem(array $data): DictionaryItem
    {
        $item = DictionaryItem::create($data);
        $this->clearCache($data['dictionary_id']);
        $this->notifyDictionaryItemUpdate('create', $item->toArray());

        return $item;
    }

    public function updateItem(int $id, array $data): DictionaryItem
    {
        $item = DictionaryItem::findOrFail($id);

        $item->update($data);
        $this->clearCache($item->dictionary_id);
        $this->notifyDictionaryItemUpdate('update', $item->toArray());

        return $item;
    }

    public function deleteItem(int $id): bool
    {
        $item = DictionaryItem::findOrFail($id);
        $dictionaryId = $item->dictionary_id;
        $itemData = $item->toArray();
        $item->delete();
        $this->clearCache($dictionaryId);
        $this->notifyDictionaryItemUpdate('delete', $itemData);

        return true;
    }

    public function batchDeleteItems(array $ids): bool
    {
        $items = DictionaryItem::whereIn('id', $ids)->get();
        $dictionaryIds = $items->pluck('dictionary_id')->unique()->toArray();

        DictionaryItem::whereIn('id', $ids)->delete();

        // 清理相关字典的缓存
        foreach ($dictionaryIds as $dictionaryId) {
            $this->clearCache($dictionaryId);
        }

        $this->notifyDictionaryItemUpdate('batch_delete', ['ids' => $ids, 'dictionary_ids' => $dictionaryIds]);

        return true;
    }

    public function batchUpdateItemsStatus(array $ids, bool $status): bool
    {
        $items = DictionaryItem::whereIn('id', $ids)->get();
        $dictionaryIds = $items->pluck('dictionary_id')->unique()->toArray();

        DictionaryItem::whereIn('id', $ids)->update(['status' => $status]);

        // 清理相关字典的缓存
        foreach ($dictionaryIds as $dictionaryId) {
            $this->clearCache($dictionaryId);
        }

        $this->notifyDictionaryItemUpdate('batch_update_status', ['ids' => $ids, 'dictionary_ids' => $dictionaryIds, 'status' => $status]);

        return true;
    }

    /**
     * 获取所有字典项（按字典分类）
     *
     * @return array 按字典code分类的字典项数据
     */
    public function getAllItems(): array
    {
        $cacheKey = 'system:dictionary-items:all';
        $allItems = Cache::get($cacheKey);

        if ($allItems === null) {
            // 获取所有启用的字典
            $dictionary = Dictionary::where('status', true)
                ->orderBy('sort')
                ->get();

            $result = [];
            foreach ($dictionary as $dictionary) {
                $items = $dictionary->activeItems->toArray();
                // 格式化字典项值
                $items = $this->formatItemsByType($items, $dictionary->value_type);

                $result[] = [
                    'code' => $dictionary->code,
                    'name' => $dictionary->name,
                    'description' => $dictionary->description,
                    'value_type' => $dictionary->value_type,
                    'items' => $items,
                ];
            }

            $allItems = $result;
            Cache::put($cacheKey, $allItems, 3600);
        }

        return $allItems;
    }

    /**
     * 通知前端字典分类已更新
     *
     * @param  string  $action  操作类型：create, update, delete, batch_delete, batch_update_status
     * @param  array  $data  字典数据
     */
    private function notifyDictionaryUpdate(string $action, array $data): void
    {
        $this->webSocketService->broadcast([
            'type' => 'dictionary_update',
            'data' => [
                'action' => $action,
                'resource_type' => 'dictionary',
                'data' => $data,
                'timestamp' => time(),
            ],
        ]);
    }

    /**
     * 通知前端字典项已更新
     *
     * @param  string  $action  操作类型：create, update, delete, batch_delete, batch_update_status
     * @param  array  $data  字典项数据
     */
    private function notifyDictionaryItemUpdate(string $action, array $data): void
    {
        $this->webSocketService->broadcast([
            'type' => 'dictionary_item_update',
            'data' => [
                'action' => $action,
                'resource_type' => 'dictionary_item',
                'data' => $data,
                'timestamp' => time(),
            ],
        ]);
    }

    /**
     * 根据值类型格式化字典项
     *
     * @param  array  $items  字典项数组
     * @param  string  $valueType  值类型：string, number, boolean, json
     * @return array 格式化后的字典项数组
     */
    private function formatItemsByType(array $items, string $valueType): array
    {
        return array_map(function ($item) use ($valueType) {
            switch ($valueType) {
                case 'number':
                    // 数字类型：将值转换为数字
                    $item['value'] = is_numeric($item['value']) ? (strpos($item['value'], '.') !== false ? (float) $item['value'] : (int) $item['value']) : $item['value'];
                    break;
                case 'boolean':
                    // 布尔类型：将 '1', 'true', 'yes' 转换为 true，其他为 false
                    $item['value'] = in_array(strtolower($item['value']), ['1', 'true', 'yes', 'on']);
                    break;
                case 'json':
                    // JSON类型：尝试解析JSON字符串，失败则保持原值
                    $decoded = json_decode($item['value'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $item['value'] = $decoded;
                    }
                    break;
                case 'string':
                default:
                    // 字符串类型：保持原值
                    break;
            }

            return $item;
        }, $items);
    }
}
