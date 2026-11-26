<?php

namespace App\Modules\Graph\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NodeRow extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    const UPDATED_AT = null;

    protected $casts = [
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(NodeVersion::class, 'node_version_rows', 'row_id', 'version_id');
    }
}
