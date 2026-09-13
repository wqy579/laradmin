<?php

namespace App\Models\System;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dictionary extends Model
{
    use ModelTrait, SoftDeletes;

    protected $table = 'system_dictionary';

    protected $fillable = [
        'name',
        'code',
        'description',
        'value_type',
        'status',
        'sort',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class, 'dictionary_id')->orderBy('sort');
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(DictionaryItem::class, 'dictionary_id')
            ->where('status', true)
            ->orderBy('sort');
    }
}
