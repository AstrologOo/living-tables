<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Ownership check for route-bound models. Answers 404, so others can't learn that a resource exists.
 */
trait AuthorizesOwnership
{
    private function abortUnlessOwner(Request $request, int $ownerId): void
    {
        abort_unless($request->user()->id === $ownerId, 404);
    }
}
