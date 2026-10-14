<?php

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Subject;
use Modules\Ledger\Models\VoucherEntry;

/**
 * 会计科目业务逻辑：树构建、新增/编辑/停用/删除校验、末级科目下拉。
 */
class SubjectService
{
    /** 科目树（含 children 递归） */
    public function tree(): array
    {
        $all = Subject::orderBy('sort')->orderBy('code')->get();
        $byParent = $all->groupBy('parent_id');

        return $this->buildTree($byParent, 0);
    }

    private function buildTree($byParent, int $parentId): array
    {
        $nodes = $byParent->get($parentId, collect());
        $tree = [];
        foreach ($nodes as $node) {
            $item = $node->toArray();
            $item['category_label'] = Subject::categoryLabel($node->category);
            $item['direction_label'] = Subject::directionLabel($node->balance_direction);
            $item['children'] = $this->buildTree($byParent, $node->id);
            $tree[] = $item;
        }

        return $tree;
    }

    /** 末级科目下拉（is_leaf=true 且 enabled） */
    public function leafOptions(?string $category = null): array
    {
        $q = Subject::where('is_leaf', true)->where('status', Subject::STATUS_ENABLED)->orderBy('code');
        if ($category) {
            $q->where('category', $category);
        }

        return $q->get(['id', 'code', 'name', 'category', 'balance_direction', 'aux_types'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'label' => $s->code.' '.$s->name,
                'category' => $s->category,
                'category_label' => Subject::categoryLabel($s->category),
                'balance_direction' => $s->balance_direction,
                'aux_types' => $s->aux_types ?? [],
            ])->all();
    }

    /** 新增科目 */
    public function create(array $data): Subject
    {
        return DB::transaction(function () use ($data) {
            $parentId = (int) ($data['parent_id'] ?? 0);
            $parent = $parentId ? Subject::find($parentId) : null;
            $level = $parent ? $parent->level + 1 : 1;
            $category = $parent ? $parent->category : ($data['category'] ?? Subject::CATEGORY_ASSET);
            $code = trim((string) ($data['code'] ?? ''));
            $name = trim((string) ($data['name'] ?? ''));

            $this->validateCode($code, $parentId, $parent);
            $this->validateName($name);
            $this->validateUniqueCode($code, null);
            $this->validateLevelRule($code, $parent);

            $subject = new Subject;
            $subject->parent_id = $parentId;
            $subject->code = $code;
            $subject->name = $name;
            $subject->category = $category;
            $subject->balance_direction = $data['balance_direction'] ?? Subject::DIRECTION_DEBIT;
            $subject->is_leaf = (bool) ($data['is_leaf'] ?? true);
            $subject->level = $level;
            $subject->aux_types = $this->normalizeAuxTypes($data['aux_types'] ?? null);
            $subject->sort = (int) ($data['sort'] ?? 0);
            $subject->status = $data['status'] ?? Subject::STATUS_ENABLED;
            $subject->remark = $data['remark'] ?? null;
            $subject->save();

            // 新增子级后，父科目若仍是末级则自动转为非末级
            if ($parent && $parent->is_leaf) {
                $parent->is_leaf = false;
                $parent->save();
            }

            return $subject->fresh();
        });
    }

    /** 编辑科目 */
    public function update(int $id, array $data): Subject
    {
        return DB::transaction(function () use ($id, $data) {
            $subject = Subject::findOrFail($id);

            $name = trim((string) ($data['name'] ?? $subject->name));
            $this->validateName($name);
            $subject->name = $name;

            // 改编码：有发生额或余额禁止改
            $newCode = trim((string) ($data['code'] ?? $subject->code));
            if ($newCode !== $subject->code) {
                if ($this->hasMovementOrBalance($subject)) {
                    throw new \DomainException('该科目有发生额或余额，不能修改编码与余额方向');
                }
                $parent = $subject->parent_id ? Subject::find($subject->parent_id) : null;
                $this->validateLevelRule($newCode, $parent);
                $this->validateUniqueCode($newCode, $id);
                $subject->code = $newCode;
            }

            // 改余额方向：有发生额或余额禁止改
            if (isset($data['balance_direction']) && $data['balance_direction'] !== $subject->balance_direction) {
                if ($this->hasMovementOrBalance($subject)) {
                    throw new \DomainException('该科目有发生额或余额，不能修改编码与余额方向');
                }
                $subject->balance_direction = $data['balance_direction'];
            }

            $subject->aux_types = $this->normalizeAuxTypes($data['aux_types'] ?? null);
            $subject->sort = (int) ($data['sort'] ?? $subject->sort);
            $subject->remark = $data['remark'] ?? $subject->remark;

            // is_leaf：已被凭证使用不可取消末级
            if (array_key_exists('is_leaf', $data) && ! $data['is_leaf'] && $subject->is_leaf) {
                if ($this->hasVoucherEntries($subject)) {
                    throw new \DomainException('该科目已有凭证记录，不能取消末级');
                }
                $subject->is_leaf = false;
            } elseif (array_key_exists('is_leaf', $data)) {
                $subject->is_leaf = (bool) $data['is_leaf'];
            }

            // category 只在顶级可改（子级继承父级，不可改）
            if ($subject->parent_id === 0 && isset($data['category'])) {
                $subject->category = $data['category'];
            }

            $subject->save();

            return $subject->fresh();
        });
    }

    /** 停用/启用 */
    public function toggleStatus(int $id): Subject
    {
        $subject = Subject::findOrFail($id);
        $subject->status = $subject->status === Subject::STATUS_ENABLED
            ? Subject::STATUS_DISABLED
            : Subject::STATUS_ENABLED;
        $subject->save();

        return $subject;
    }

    /** 删除：已被凭证使用的科目禁止删除 */
    public function delete(int $id): void
    {
        $subject = Subject::findOrFail($id);
        if ($this->hasVoucherEntries($subject)) {
            throw new \DomainException('该科目已有凭证记录，不能删除，可改为停用');
        }
        if ($subject->children()->exists()) {
            throw new \DomainException('该科目有下级科目，不能删除');
        }
        if ($this->hasMovementOrBalance($subject)) {
            throw new \DomainException('该科目有发生额或余额，不能删除，可改为停用');
        }
        $subject->delete();
    }

    /** 是否有凭证分录 */
    public function hasVoucherEntries(Subject $subject): bool
    {
        return VoucherEntry::where('subject_id', $subject->id)->exists();
    }

    /** 是否有发生额或余额（本期凭证分录金额或期初余额） */
    public function hasMovementOrBalance(Subject $subject): bool
    {
        $hasEntry = VoucherEntry::where('subject_id', $subject->id)
            ->where(function ($q) {
                $q->where('debit_amount', '!=', 0)->orWhere('credit_amount', '!=', 0);
            })->exists();
        if ($hasEntry) {
            return true;
        }

        return DB::table('ledger_opening_balances')
            ->where('subject_id', $subject->id)
            ->where(function ($q) {
                $q->where('opening_debit', '!=', 0)
                    ->orWhere('opening_credit', '!=', 0)
                    ->orWhere('year_debit', '!=', 0)
                    ->orWhere('year_credit', '!=', 0);
            })->exists();
    }

    private function normalizeAuxTypes($auxTypes): ?array
    {
        if (empty($auxTypes)) {
            return null;
        }
        if (is_string($auxTypes)) {
            $decoded = json_decode($auxTypes, true);

            return is_array($decoded) ? array_values($decoded) : array_filter(array_map('trim', explode(',', $auxTypes)));
        }

        return array_values($auxTypes);
    }

    private function validateCode(string $code, int $parentId, ?Subject $parent): void
    {
        if ($code === '') {
            throw new \DomainException('科目编码不能为空');
        }
        $this->validateLevelRule($code, $parent);
    }

    /** 编码必须符合「父级编码+本级序号」的层级规则，如 1001 下建 100101 */
    private function validateLevelRule(string $code, ?Subject $parent): void
    {
        if ($parent) {
            $parentCode = $parent->code;
            if (! str_starts_with($code, $parentCode)) {
                throw new \DomainException('编码不符合层级规则，应以父级编码 '.$parentCode.' 为前缀');
            }
            $suffix = substr($code, strlen($parentCode));
            if ($suffix === '' || ! preg_match('/^[0-9]+$/', $suffix)) {
                throw new \DomainException('编码不符合层级规则，父级编码后须接数字序号');
            }
        }
    }

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new \DomainException('科目名称不能为空');
        }
        if (mb_strlen($name) > 50) {
            throw new \DomainException('科目名称最多 50 字');
        }
    }

    /** 同级唯一 */
    private function validateUniqueCode(string $code, ?int $exceptId): void
    {
        $q = Subject::where('code', $code);
        if ($exceptId) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            throw new \DomainException('科目编码已存在，同级不能重复');
        }
    }
}
