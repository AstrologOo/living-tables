<?php

namespace App\Modules\Graph\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLimit extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
