<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivityProgress;
use Illuminate\Support\Facades\Auth;

class ActivityProgressController extends Controller
{
    /**
     * Save or update progress for the authenticated user (student or teacher).
     */
    public function saveProgress(Request $request, $actID)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validatedData = $request->validate([
            'itemID'              => 'nullable|exists:items,itemID',
            'draftFiles'          => 'nullable|string', // JSON string of file objects
            'draftTestCaseResults'=> 'nullable|json',
            'timeRemaining'       => 'nullable|integer',
        ]);

        $progress = ActivityProgress::updateOrCreate(
            [
                'actID' => $actID,
                'progressable_id' => $user->id,
                'progressable_type' => get_class($user),
                'itemID' => $validatedData['itemID'] ?? null,
            ],
            [
                'draftFiles' => $validatedData['draftFiles'] ?? null,
                'draftTestCaseResults' => $validatedData['draftTestCaseResults'] ?? null,
                'timeRemaining' => $validatedData['timeRemaining'] ?? null,
            ]
        );

        return response()->json([
            'message'  => 'Progress saved successfully.',
            'progress' => $progress,
        ], 200);
    }

    /**
     * Retrieve progress for the authenticated user for a given activity.
     */
    public function getProgress(Request $request, $actID)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $progress = ActivityProgress::where('actID', $actID)
                    ->where('progressable_id', $user->id)
                    ->where('progressable_type', get_class($user))
                    ->get();

        return response()->json([
            'progress' => $progress,
        ], 200);
    }
}