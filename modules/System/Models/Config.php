<?php

namespace Modules\System\Models;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Config extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'system_setting';

    protected $fillable = [
        'parent_id',
        'item_type',
        'group',
        'key',
        'name',
        'type',
        'options',
        'value',
        'default_value',
        'description',
        'validation',
        'sort',
        'is_system',
        'status',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'options' => 'array',
        'is_system' => 'boolean',
        'status' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isGroup(): bool
    {
        return $this->item_type === 'group';
    }

    public function getOptionsAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?? [];
        }
        return $value;
    }

    public function setOptionsAttribute($value)
    {
        $this->attributes['options'] = is_array($value) ? json_encode($value) : $value;
    }
}
