<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Notification;

class NotificationController extends Controller
{
    /**
     * List notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Fetch notifications in descending order of creation.
        $notifications = $user->notifications()->orderBy('created_at', 'desc')->get();

        return response()->json($notifications);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $notification = $user->notifications()->find($id);
        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }
    
        // Update the notification to mark it as read
        $notification->update(['isRead' => true]);
    
        return response()->json(['message' => 'Notification marked as read']);
    }
    

    /**
    * Delete a specific notification.
    */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification = $user->notifications()->find($id);
        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }
}