<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Archive\Support\HtmlSanitizer;

/**
 * 帮助文档。
 *
 * content 落库前一定过 HtmlSanitizer::clean，取出来直接给前端 v-html 才是安全的；
 * 任何绕过 Service 直接 HelpDoc::create(['content' => 原始输入']) 的写法都会破坏这个前提，
 * 所以 mutator 里再兜一道（见 setContentAttribute）——写入路径只有一条这件事，
 * 靠代码结构而不是靠自觉。
 */
class HelpDoc extends Model
{
    use SoftDeletes;

    protected $table = 'archive_help_docs';

    protected $fillable = [
        'title', 'slug', 'category', 'summary', 'content', 'content_text',
        'version', 'sort', 'status', 'is_top', 'views', 'published_at',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'is_top' => 'boolean',
        'views' => 'integer',
        'sort' => 'integer',
        'published_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** 只取已发布文档（帮助中心正文只允许读这一批） */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * 净化后入库。
     *
     * 放在 mutator 而不是只放在 Service 里：这样即使以后有人新增写入口
     * （批量导入、种子数据）也不会把未过滤的 HTML 带进库。
     * 幂等（见 HtmlSanitizer 的定点迭代），重复赋值不会二次转义。
     */
    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = HtmlSanitizer::clean($value);
    }
}
