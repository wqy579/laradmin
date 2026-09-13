<?php

namespace Modules\System\Models;

use App\Traits\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DictionaryItem extends Model
{
    use ModelTrait;
    protected $table = 'system_dictionary_item';

    protected $fillable = [
        'dictionary_id',
        'label',
        'value',
        'color',
        'description',
        'is_default',
        'status',
        'sort',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'status' => 'boolean',
    ];

    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(Dictionary::class, 'dictionary_id');
    }
}
