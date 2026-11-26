<?php

namespace App\Modules\Graph\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saved filter, sort and limit criteria over a node's current data.
 */
class NodeView extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    const UPDATED_AT = null;

    protected $casts = [
        'filter' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
