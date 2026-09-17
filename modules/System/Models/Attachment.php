<?php

namespace Modules\System\Models;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Models\User;

class Attachment extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'system_attachment';

    protected $fillable = [
        'name',
        'file_name',
        'path',
        'url',
        'mime_type',
        'extension',
        'size',
        'type',
        'storage_driver',
        'hash',
        'user_id',
        'directory',
        'metadata',
        'description',
    ];

    protected $casts = [
        'size' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * 文件类型常量
     */
    const TYPE_IMAGE = 'image';

    const TYPE_DOCUMENT = 'document';

    const TYPE_VIDEO = 'video';

    const TYPE_AUDIO = 'audio';

    const TYPE_ARCHIVE = 'archive';

    const TYPE_OTHER = 'other';

    /**
     * 上传用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 根据MIME类型判断文件类型
     */
    public static function detectType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return self::TYPE_IMAGE;
        }
        if (str_starts_with($mimeType, 'video/')) {
            return self::TYPE_VIDEO;
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return self::TYPE_AUDIO;
        }
        if (in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
        ])) {
            return self::TYPE_DOCUMENT;
        }
        if (in_array($mimeType, [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/gzip',
            'application/x-tar',
        ])) {
            return self::TYPE_ARCHIVE;
        }

        return self::TYPE_OTHER;
    }

    /**
     * 格式化文件大小
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    /**
     * 是否为图片
     */
    public function getIsImageAttribute(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    /**
     * 获取文件图标类型（用于前端展示）
     */
    public function getIconTypeAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_IMAGE => 'file-image',
            self::TYPE_DOCUMENT => 'file-text',
            self::TYPE_VIDEO => 'video-camera',
            self::TYPE_AUDIO => 'audio',
            self::TYPE_ARCHIVE => 'file-zip',
            default => 'file',
        };
    }
}
