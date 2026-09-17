<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 关系完整性守卫：模型上声明的每个关系，其外键列必须在目标表里真实存在。
 *
 * hasMany(X::class) 不写第二个参数时，Eloquent 按属主类名推断外键
 * （ReturnOrder → return_order_id），而数据库里那列可能叫别的名字（return_id）。
 * 这种错在类型检查、pint、route:list 里都不会有任何报错，直到运行时一条 SQL
 * 报 unknown column，整个接口 500。
 *
 * 退货单就是这么坏的：ReturnOrder::items() 漏传外键，store / update / approve /
 * show 全部 500，而它一条测试都没有覆盖。
 */
class RelationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_relation_points_to_a_real_column(): void
    {
        $bad = [];

        foreach ($this->modelClasses() as $class) {
            foreach ($this->relationMethods($class) as $method) {
                // 个别关系方法内部会再发起查询，无 DB 行的实例上调不动——跳过而不是中断
                try {
                    /** @var Relation $relation */
                    $relation = (new $class)->$method();
                } catch (\Throwable $e) {
                    continue;
                }

                if ($relation instanceof BelongsToMany) {
                    // 多对多走中间表，外键列名由两张表推导，另行核对，这里跳过
                    continue;
                }

                $fk = $relation->getForeignKeyName();
                $table = $relation instanceof BelongsTo
                    // 多对一：外键在属主表上（如 auth_user.department_id），不是被关联表
                    ? $relation->getParent()->getTable()
                    // 一对多 / 一对一：外键在被关联表上
                    : $relation->getRelated()->getTable();

                if (! Schema::hasTable($table)) {
                    $bad[] = "$class::$method() → 表 $table 不存在";
                } elseif (! Schema::hasColumn($table, $fk)) {
                    $bad[] = "$class::$method() → 表 $table 没有列 $fk（很可能是 hasMany 漏传外键名，Eloquent 按类名推错了）";
                }
            }
        }

        $this->assertSame(
            [],
            $bad,
            "以下关系的外键列不存在，调用必然 SQL 报错：\n".implode("\n", $bad)
        );
    }

    /** 所有模块与内核下的 Eloquent 模型 */
    private function modelClasses(): array
    {
        $classes = [];

        foreach (array_merge(
            glob(base_path('modules/*/Models/*.php')) ?: [],
            glob(base_path('app/Models/*.php')) ?: []
        ) as $file) {
            $namespace = str_starts_with($file, base_path().'modules/')
                ? 'Modules\\'.basename(dirname(dirname($file))).'\\Models\\'
                : 'App\\Models\\';
            $class = $namespace.basename($file, '.php');

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** 无参的公开关系方法（返回类型声明为 Relation 子类的都算） */
    private function relationMethods(string $class): array
    {
        $methods = [];

        foreach (get_class_methods($class) as $method) {
            $ref = new \ReflectionMethod($class, $method);

            if (! $ref->isPublic() || $ref->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $type = $ref->getReturnType();

            if ($type instanceof \ReflectionNamedType
                && class_exists($type->getName())
                && is_subclass_of($type->getName(), Relation::class)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }
}
