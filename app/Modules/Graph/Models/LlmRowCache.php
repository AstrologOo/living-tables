<?php

namespace App\Modules\Graph\Models;

use Illuminate\Database\Eloquent\Model;

class LlmRowCache extends Model
{
    protected $table = 'llm_row_cache';

    protected $primaryKey = 'cache_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'output' => 'array',
    ];

    const UPDATED_AT = null;
}
