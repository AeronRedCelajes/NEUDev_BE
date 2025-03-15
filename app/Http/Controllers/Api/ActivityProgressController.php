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
     * This version uses a single activity-level record (no itemID) and preserves the selected language,
     * and now also saves per-item times.
     */
    public function saveProgress(Request $request, $actID)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $progressableId = isset($user->studentID)
            ? $user->studentID
            : (isset($user->teacherID) ? $user->teacherID : null);
            
        if (is_null($progressableId)) {
            return response()->json(['message' => 'User identifier not found.'], 500);
        }
        
        // Validate progress data using the new field names.
        $validatedData = $request->validate([
            'draftFiles'           => 'nullable|string', // JSON string of file objects
            'draftTestCaseResults' => 'nullable|json',
            'draftTimeRemaining'   => 'nullable|integer',
            'draftSelectedLanguage'=> 'nullable|string', // to preserve user's language choice
            'draftScore'           => 'nullable|numeric',  // new field for storing the partial/aggregated score
            'draftItemTimes'       => 'nullable|string',   // new field for per-item times (as JSON)
        ]);
        
        // Update or create the activity-level progress record.
        $progress = ActivityProgress::updateOrCreate(
            [
                'actID'             => $actID,
                'progressable_id'   => $progressableId,
                'progressable_type' => get_class($user),
            ],
            [
                'draftFiles'            => $validatedData['draftFiles'] ?? null,
                'draftTestCaseResults'  => $validatedData['draftTestCaseResults'] ?? null,
                'draftTimeRemaining'    => $validatedData['draftTimeRemaining'] ?? null,
                'draftSelectedLanguage' => $validatedData['draftSelectedLanguage'] ?? null,
                'draftScore'            => $validatedData['draftScore'] ?? 0,
                'draftItemTimes'        => $validatedData['draftItemTimes'] ?? null,
            ]
        );
        
        return response()->json([
            'message'  => 'Progress saved successfully.',
            'progress' => $progress,
        ], 200);
    }
    
    /**
     * Retrieve progress for the authenticated user for a given activity.
     * Calculates an "endTime" based on the stored draftTimeRemaining and updated_at timestamp.
     */
    public function getProgress(Request $request, $actID)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $progressableId = isset($user->studentID)
            ? $user->studentID
            : (isset($user->teacherID) ? $user->teacherID : null);

        if (is_null($progressableId)) {
            return response()->json(['message' => 'User identifier not found.'], 500);
        }

        $progress = ActivityProgress::where('actID', $actID)
                    ->where('progressable_id', $progressableId)
                    ->where('progressable_type', get_class($user))
                    ->first();

        if ($progress) {
            // Calculate the current endTime based on the updated_at timestamp and draftTimeRemaining.
            $updatedMs = strtotime($progress->updated_at) * 1000;
            $progress->endTime = $progress->draftTimeRemaining !== null
                ? $updatedMs + ($progress->draftTimeRemaining * 1000)
                : null;

            // Decode the stored JSON for files and test case results.
            $decodedFiles = $progress->draftFiles 
                ? json_decode($progress->draftFiles, true) 
                : null;
            $decodedResults = $progress->draftTestCaseResults 
                ? json_decode($progress->draftTestCaseResults, true) 
                : null;
            // Decode draftItemTimes if available.
            $decodedItemTimes = $progress->draftItemTimes
                ? json_decode($progress->draftItemTimes, true)
                : null;

            // Rename fields for client consumption.
            $progress->files = $decodedFiles;
            $progress->testCaseResults = $decodedResults;
            $progress->selectedLanguage = $progress->draftSelectedLanguage;
            $progress->itemTimes = $decodedItemTimes;
            
            // Optionally remove the raw fields.
            unset($progress->draftFiles, $progress->draftTestCaseResults, $progress->draftSelectedLanguage);
        }

        return response()->json([
            'progress' => $progress ? [$progress] : [],
        ]);
    }

    /**
     * Clear progress for the authenticated user for a given activity.
     * This should be called after a submission is finalized.
     */
    public function clearProgress(Request $request, $actID)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $progressableId = isset($user->studentID)
            ? $user->studentID
            : (isset($user->teacherID) ? $user->teacherID : null);
            
        if (is_null($progressableId)) {
            return response()->json(['message' => 'User identifier not found.'], 500);
        }
        
        ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $progressableId)
            ->where('progressable_type', get_class($user))
            ->delete();
        
        return response()->json(['message' => 'Progress cleared successfully.'], 200);
    }
}