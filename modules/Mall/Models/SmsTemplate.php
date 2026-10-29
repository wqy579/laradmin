<?php

namespace Modules\Mall\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 短信模板。
 *
 * content 用 {变量名} 占位；variables 为 {变量名: 示例值} 映射，供实时预览替换。
 */
class SmsTemplate extends Model
{
    protected $table = 'sms_templates';

    protected $fillable = [
        'code', 'name', 'content', 'variables', 'remark', 'status', 'created_by',
    ];

    protected $casts = [
        'variables' => 'array',
        'status' => 'integer',
        'created_by' => 'integer',
    ];

    /** 从模板体提取 {变量名} 列表（去重保序） */
    public function extractVariables(): array
    {
        if (! preg_match_all('/\{([^{}]+)\}/u', $this->content, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /**
     * 实时预览：把模板体里的每个 {变量} 用示例值映射替换。
     * 无示例值的变量保留占位原样，方便前端看出缺省。
     */
    public function preview(array $exampleValues = []): string
    {
        $map = array_merge($this->variables ?? [], $exampleValues);

        return preg_replace_callback('/\{([^{}]+)\}/u', function ($m) use ($map) {
            $name = trim($m[1]);

            return isset($map[$name]) ? (string) $map[$name] : $m[0];
        }, $this->content);
    }

    /** 实际发送：所有变量必须有值，缺一个即抛异常 */
    public function render(array $values): string
    {
        $missing = array_values(array_filter($this->extractVariables(), fn ($v) => ! isset($values[$v])));
        if ($missing) {
            throw new \DomainException('缺少变量值：'.implode('、', $missing));
        }

        return preg_replace_callback('/\{([^{}]+)\}/u', fn ($m) => (string) ($values[trim($m[1])] ?? ''), $this->content);
    }

    public static function statusLabel(int $status): string
    {
        return $status === 1 ? '启用' : '停用';
    }

    public static function statusTagType(int $status): string
    {
        return $status === 1 ? 'success' : 'info';
    }
}
