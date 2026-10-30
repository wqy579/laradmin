<?php

namespace Modules\Archive\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 下载中心的文件条目。
 *
 * 与 system_attachment 分开建表的原因见建表迁移注释（避免跨模块架构边 + 语义不同）。
 */
class DownloadFile extends Model
{
    use SoftDeletes;

    protected $table = 'archive_download_files';

    protected $fillable = [
        'name', 'category', 'file_name', 'stored_name', 'extension', 'mime_type',
        'size', 'version', 'description', 'download_count', 'status', 'sort',
        'created_by', 'created_by_name', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'size' => 'integer',
        'download_count' => 'integer',
        'sort' => 'integer',
    ];

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DISABLED = 'disabled';

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }
}
