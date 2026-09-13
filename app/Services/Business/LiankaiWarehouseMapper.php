<?php

namespace App\Services\Business;

use Illuminate\Support\Facades\DB;

/**
 * 连凯仓库名称 → LarAdmin 车辆映射服务
 *
 * 连凯系统中每辆车有一个独立仓库（如"803车辆仓库"、"程瑞军车辆仓库"），
 * 本服务将这些仓库名字符串匹配到 LarAdmin 的 vehicles 表。
 *
 * 匹配规则（优先级从高到低）：
 *   1. 车牌号匹配："803车辆仓库" → vehicles.plate_no LIKE '%803%'
 *   2. 司机姓名匹配："程瑞军车辆仓库" → vehicles.driver_name = '程瑞军'
 *   3. 特殊规则：
 *      - "总仓库"       → null（不属于任何车）
 *      - "自提车辆仓库" → null（自提，无固定车辆）
 *      - "新车仓库"     → null（新车待分配）
 */
class LiankaiWarehouseMapper
{
    /** @var array<string, int|null> 缓存映射结果 */
    protected static array $cache = [];

    /**
     * 将连凯仓库名字符串映射为车辆 ID。
     * 未匹配到车辆时返回 null（总仓库、自提等）。
     *
     * @param  string  $warehouseName  连凯仓库名，如"803车辆仓库"
     * @return int|null                匹配到的 vehicle.id，或 null
     */
    public static function map(string $warehouseName): ?int
    {
        if (isset(self::$cache[$warehouseName])) {
            return self::$cache[$warehouseName];
        }

        $result = self::doMap($warehouseName);
        self::$cache[$warehouseName] = $result;
        return $result;
    }

    /**
     * 批量映射，返回 [warehouse_name => vehicle_id] 数组。
     *
     * @param  array<string>  $names
     * @return array<string, int|null>
     */
    public static function mapMany(array $names): array
    {
        $results = [];
        foreach ($names as $name) {
            $results[$name] = self::map($name);
        }
        return $results;
    }

    /**
     * 重置缓存（重新同步时使用）
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    // -----------------------------------------------------------------------
    // 内部实现
    // -----------------------------------------------------------------------

    protected static function doMap(string $name): ?int
    {
        // 特殊名称直接返回 null
        if ($name === '总仓库' || $name === '自提车辆仓库') {
            return null;
        }

        // 去掉后缀提取标识
        $identifier = self::extractIdentifier($name);
        if ($identifier === null) {
            return null;
        }

        // 1. 尝试车牌号精确/包含匹配
        $byPlate = DB::table('vehicles')
            ->where('is_active', true)
            ->where(function ($q) use ($identifier) {
                $q->where('plate_no', $identifier)
                  ->orWhere('plate_no', 'like', '%' . $identifier . '%');
            })
            ->select('id')
            ->first();
        if ($byPlate) {
            return (int) $byPlate->id;
        }

        // 2. 尝试司机姓名精确匹配
        $byDriver = DB::table('vehicles')
            ->where('is_active', true)
            ->where('driver_name', $identifier)
            ->select('id')
            ->first();
        if ($byDriver) {
            return (int) $byDriver->id;
        }

        return null;
    }

    /**
     * 从仓库名中提取标识（车牌号或司机名）。
     * 例如："803车辆仓库" → "803"，"程瑞军车辆仓库" → "程瑞军"
     */
    protected static function extractIdentifier(string $name): ?string
    {
        // 去掉常见后缀
        foreach (['车辆仓库', '仓库'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return mb_substr($name, 0, -mb_strlen($suffix));
            }
        }
        return null;
    }
}
