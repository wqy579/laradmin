<?php

namespace Modules\Mall\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 短信发送记录。
 */
class SmsRecord extends Model
{
    protected $table = 'sms_records';

    protected $fillable = [
        'send_no', 'template_id', 'template_code', 'template_name',
        'mobile', 'receiver_name', 'content', 'variables',
        'status', 'error_message', 'sent_at', 'created_by', 'creator_name',
    ];

    protected $casts = [
        'variables' => 'array',
        'template_id' => 'integer',
        'created_by' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function template()
    {
        return $this->belongsTo(SmsTemplate::class, 'template_id');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '发送中',
            'success' => '成功',
            'failed' => '失败',
            default => $status,
        };
    }

    public static function statusTagType(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'success' => 'success',
            'failed' => 'danger',
            default => 'info',
        };
    }
}
