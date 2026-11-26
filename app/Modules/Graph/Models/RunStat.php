<?php

namespace App\Modules\Graph\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunStat extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(NodeVersion::class, 'version_id');
    }
}
