<?php

namespace Modules\System\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\System\Models\Config;

class ConfigService
{
    public function getList(array $params): array
    {
        $query = Config::query();

        // 默认只查配置项
        if (! isset($params['item_type'])) {
            $query->where('item_type', 'config');
        } elseif ($params['item_type']) {
            $query->where('item_type', $params['item_type']);
        }

        if (isset($params['parent_id']) && $params['parent_id'] !== '') {
            $query->where('parent_id', $params['parent_id'] ?: null);
        }

        if (! empty($params['group'])) {
            $query->where('group', $params['group']);
        }

        if (! empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('name', 'like', '%'.$params['keyword'].'%')
                    ->orWhere('key', 'like', '%'.$params['keyword'].'%');
            });
        }

        if (isset($params['status']) && $params['status'] !== '') {
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

    public function getById(int $id): ?Config
    {
        return Config::find($id);
    }

    public function getByKey(string $key): ?Config
    {
        return Config::where('key', $key)->first();
    }

    public function getByGroup(string $group): array
    {
        return Config::where('group', $group)
            ->where('item_type', 'config')
            ->where('status', true)
            ->orderBy('sort')
            ->get()
            ->toArray();
    }

    public function getAllConfigs(array $params = []): array
    {
        $query = Config::where('status', true);

        if (! empty($params['item_type'])) {
            $query->where('item_type', $params['item_type']);
        } else {
            $query->where('item_type', 'config');
        }

        return $query->orderBy('sort')->orderBy('id')->get()->toArray();
    }

    public function getByParentId(int $parentId): array
    {
        return Config::where('parent_id', $parentId)
            ->where('item_type', 'config')
            ->where('status', true)
            ->orderBy('sort')
            ->get()
            ->toArray();
    }

    public function getAllConfig(): array
    {
        $cacheKey = 'system:configs:all';
        $configs = Cache::get($cacheKey);

        if ($configs === null) {
            $configs = Config::where('status', true)
                ->where('item_type', 'config')
                ->orderBy('sort')
                ->get()
                ->keyBy('key')
                ->toArray();
            Cache::put($cacheKey, $configs, 3600);
        }

        return $configs;
    }

    public function getConfigValue(string $key, $default = null)
    {
        $configs = $this->getAllConfig();

        if (! isset($configs[$key])) {
            return $default;
        }

        $config = $configs[$key];

        return $config['value'] ?? $config['default_value'] ?? $default;
    }

    public function getTree(): array
    {
        $groups = Config::where('item_type', 'group')
            ->where('status', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->toArray();

        return $this->buildTree($groups);
    }

    private function buildTree(array $items, $parentId = null): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ($item['parent_id'] == $parentId) {
                $children = $this->buildTree($items, $item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }

        return $tree;
    }

    public function create(array $data): Config
    {
        $itemType = $data['item_type'] ?? 'config';

        if ($itemType === 'group') {
            // 校验 parent_id 必须指向分组
            if (! empty($data['parent_id'])) {
                $parent = Config::find($data['parent_id']);
                if (! $parent || ! $parent->isGroup()) {
                    throw new \Exception('父级必须是分组类型');
                }
            }

            $data['key'] = $data['key'] ?? ('group_'.Str::random(8));
            $data['type'] = 'string';
        } else {
            // 配置项必须归属于某个分组
            if (empty($data['parent_id'])) {
                throw new \Exception('配置项必须归属于某个分组');
            }
            $parent = Config::find($data['parent_id']);
            if (! $parent || ! $parent->isGroup()) {
                throw new \Exception('父级必须是分组类型');
            }
        }

        $config = Config::create($data);
        $this->clearCache();

        return $config;
    }

    public function update(int $id, array $data): Config
    {
        $config = Config::findOrFail($id);

        if (isset($data['item_type']) && $data['item_type'] === 'group') {
            if (! empty($data['parent_id'])) {
                if ($data['parent_id'] == $id) {
                    throw new \Exception('不能将自己设为父级');
                }
                $parent = Config::find($data['parent_id']);
                if (! $parent || ! $parent->isGroup()) {
                    throw new \Exception('父级必须是分组类型');
                }
            }
        } else {
            if (isset($data['parent_id']) && ! empty($data['parent_id'])) {
                $parent = Config::find($data['parent_id']);
                if (! $parent || ! $parent->isGroup()) {
                    throw new \Exception('父级必须是分组类型');
                }
            }
        }

        $config->update($data);
        $this->clearCache();

        return $config;
    }

    public function delete(int $id): bool
    {
        $config = Config::findOrFail($id);

        if ($config->is_system) {
            throw new \Exception('系统配置不能删除');
        }

        // 删除分组时递归删除子项
        if ($config->isGroup()) {
            $this->deleteChildren($id);
        }

        $config->delete();
        $this->clearCache();

        return true;
    }

    private function deleteChildren(int $parentId): void
    {
        $children = Config::where('parent_id', $parentId)->get();
        foreach ($children as $child) {
            if ($child->isGroup()) {
                $this->deleteChildren($child->id);
            }
            $child->delete();
        }
    }

    public function batchDelete(array $ids): bool
    {
        $configs = Config::whereIn('id', $ids)->where('is_system', false)->get();
        foreach ($configs as $config) {
            if ($config->isGroup()) {
                $this->deleteChildren($config->id);
            }
            $config->delete();
        }
        $this->clearCache();

        return true;
    }

    public function batchUpdateStatus(array $ids, bool $status): bool
    {
        Config::whereIn('id', $ids)->update(['status' => $status]);
        $this->clearCache();

        return true;
    }

    public function batchSave(array $items): bool
    {
        foreach ($items as $item) {
            if (! empty($item['id'])) {
                Config::where('id', $item['id'])->update([
                    'value' => $item['value'] ?? null,
                ]);
            }
        }
        $this->clearCache();

        return true;
    }

    private function clearCache(): void
    {
        Cache::forget('system:configs:all');
    }

    public function getGroups(): array
    {
        return Config::where('item_type', 'group')
            ->where('status', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->toArray();
    }
}
