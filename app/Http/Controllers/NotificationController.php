<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOwnership;
use App\Modules\Graph\Models\NodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists notifications and marks them read.
 */
class NotificationController extends Controller
{
    use AuthorizesOwnership;

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            NodeNotification::query()
                ->where('user_id', $request->user()->id)
                ->when($request->boolean('unread_only'), fn ($query) => $query->whereNull('read_at'))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function markRead(Request $request, NodeNotification $notification): JsonResponse
    {
        $this->abortUnlessOwner($request, $notification->user_id);

        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }
}
