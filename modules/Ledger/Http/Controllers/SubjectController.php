<?php

namespace Modules\Ledger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Services\SubjectService;

/**
 * 会计科目控制器
 */
class SubjectController extends Controller
{
    use ResponseTrait;

    public function __construct(private SubjectService $service) {}

    /** 树（列表页用，可按编码/名称/类别/状态筛选后再树化） */
    public function index(Request $request)
    {
        $code = trim((string) $request->input('code', ''));
        $name = trim((string) $request->input('name', ''));
        $category = $request->input('category');
        $status = $request->input('status');

        // 筛选命中的科目 + 其祖先链（保证树完整）
        $query = Subject::query();
        if ($code !== '') {
            $query->where('code', 'like', '%'.$code.'%');
        }
        if ($name !== '') {
            $query->where('name', 'like', '%'.$name.'%');
        }
        if ($category && $category !== 'all') {
            $query->where('category', $category);
        }
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($code === '' && $name === '' && ! $category && ! $status) {
            return $this->success(['tree' => $this->service->tree()]);
        }

        // 命中的叶子集合 → 收集祖先 id，返回过滤后的子树
        $matched = $query->get();
        $keepIds = $matched->pluck('id')->all();
        foreach ($matched as $m) {
            $pid = $m->parent_id;
            while ($pid) {
                $keepIds[] = $pid;
                $parent = Subject::find($pid);
                $pid = $parent?->parent_id ?? 0;
            }
        }
        $keepIds = array_unique($keepIds);
        $all = Subject::orderBy('sort')->orderBy('code')->get()->filter(fn ($s) => in_array($s->id, $keepIds));
        $byParent = $all->groupBy('parent_id');

        $build = function (int $parentId) use (&$build, $byParent) {
            $nodes = $byParent->get($parentId, collect());
            $tree = [];
            foreach ($nodes as $node) {
                $item = $node->toArray();
                $item['category_label'] = Subject::categoryLabel($node->category);
                $item['direction_label'] = Subject::directionLabel($node->balance_direction);
                $item['children'] = $build($node->id);
                $tree[] = $item;
            }

            return $tree;
        };

        return $this->success(['tree' => $build(0)]);
    }

    /** 树（不分页，全量） */
    public function tree()
    {
        return $this->success(['tree' => $this->service->tree()]);
    }

    /** 末级科目下拉 */
    public function options(Request $request)
    {
        return $this->success(['options' => $this->service->leafOptions($request->input('category'))]);
    }

    public function show(int $id)
    {
        $subject = Subject::findOrFail($id);

        return $this->success(['subject' => $this->formatSubject($subject)]);
    }

    public function store(Request $request)
    {
        try {
            $subject = $this->service->create($request->all());

            return $this->success(['subject' => $this->formatSubject($subject)], '保存成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('保存失败：'.$e->getMessage(), 500);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $subject = $this->service->update($id, $request->all());

            return $this->success(['subject' => $this->formatSubject($subject)], '保存成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('保存失败：'.$e->getMessage(), 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->service->delete($id);

            return $this->success(null, '删除成功');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('删除失败：'.$e->getMessage(), 500);
        }
    }

    public function toggleStatus(int $id)
    {
        try {
            $subject = $this->service->toggleStatus($id);
            $msg = $subject->status === Subject::STATUS_ENABLED ? '已启用' : '已停用';

            return $this->success(['subject' => $this->formatSubject($subject)], $msg);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    public function export(Request $request)
    {
        $code = trim((string) $request->input('code', ''));
        $name = trim((string) $request->input('name', ''));
        $category = $request->input('category');
        $status = $request->input('status');

        $query = Subject::query();
        if ($code !== '') {
            $query->where('code', 'like', '%'.$code.'%');
        }
        if ($name !== '') {
            $query->where('name', 'like', '%'.$name.'%');
        }
        if ($category && $category !== 'all') {
            $query->where('category', $category);
        }
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($code === '' && $name === '' && ! $category && ! $status) {
            $tree = $this->service->tree();
        } else {
            $matched = $query->get();
            $keepIds = $matched->pluck('id')->all();
            foreach ($matched as $m) {
                $pid = $m->parent_id;
                while ($pid) {
                    $keepIds[] = $pid;
                    $parent = Subject::find($pid);
                    $pid = $parent?->parent_id ?? 0;
                }
            }
            $keepIds = array_unique($keepIds);
            $all = Subject::orderBy('sort')->orderBy('code')->get()->filter(fn ($s) => in_array($s->id, $keepIds));
            $byParent = $all->groupBy('parent_id');

            $build = function (int $parentId) use (&$build, $byParent) {
                $nodes = $byParent->get($parentId, collect());
                $tree = [];
                foreach ($nodes as $node) {
                    $item = $node->toArray();
                    $item['category_label'] = Subject::categoryLabel($node->category);
                    $item['direction_label'] = Subject::directionLabel($node->balance_direction);
                    $item['children'] = $build($node->id);
                    $tree[] = $item;
                }
                return $tree;
            };
            $tree = $build(0);
        }

        $csv = "\u{FEFF}科目编码,科目名称,科目类别,余额方向,是否末级,级次,辅助核算,状态,备注\n";
        $flatten = function (array $nodes, int $depth = 0) use (&$flatten, &$csv) {
            foreach ($nodes as $node) {
                $indent = str_repeat('  ', $depth);
                $aux = is_array($node['aux_types'] ?? null) ? implode('/', $node['aux_types']) : '';
                $csv .= sprintf(
                    "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $this->csvCell($node['code']),
                    $this->csvCell($indent.$node['name']),
                    Subject::categoryLabel($node['category']),
                    Subject::directionLabel($node['balance_direction']),
                    $node['is_leaf'] ? '末级' : '—',
                    $node['level'],
                    $aux,
                    $node['status'] === Subject::STATUS_ENABLED ? '启用' : '停用',
                    $this->csvCell($node['remark'] ?? '')
                );
                if (! empty($node['children'])) {
                    $flatten($node['children'], $depth + 1);
                }
            }
        };
        $flatten($tree);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ledger_subjects.csv"',
        ]);
    }

    private function formatSubject(Subject $subject): array
    {
        return [
            'id' => $subject->id,
            'parent_id' => $subject->parent_id,
            'code' => $subject->code,
            'name' => $subject->name,
            'category' => $subject->category,
            'category_label' => Subject::categoryLabel($subject->category),
            'balance_direction' => $subject->balance_direction,
            'direction_label' => Subject::directionLabel($subject->balance_direction),
            'is_leaf' => (bool) $subject->is_leaf,
            'level' => $subject->level,
            'aux_types' => $subject->aux_types ?? [],
            'sort' => $subject->sort,
            'status' => $subject->status,
            'remark' => $subject->remark,
            'created_at' => $subject->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function csvCell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
