<?php

namespace App\Modules\Graph\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    // The webhook secret signs requests, so it is shown only once, on creation. The rest is worker bookkeeping.
    protected $hidden = ['webhook_secret', 'claim_token', 'data_revision', 'consecutive_failures', 'retry_at'];

    protected $casts = [
        'dsl_config' => 'array',
        'columns_schema' => 'array',
        'columns_schema_confirmed' => 'boolean',
        'webhook_payload' => 'array',
        'data_revision' => 'integer',
        'consecutive_failures' => 'integer',
        'retry_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Nodes this node depends on.
     */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'edges', 'node_id', 'depends_on_id');
    }

    /**
     * Nodes that depend on this node.
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'edges', 'depends_on_id', 'node_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(NodeVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NodeVersion::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(NodeRow::class);
    }
}
