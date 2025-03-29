<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Activity;
use App\Models\ActivityProgress;
use App\Models\ActivityItem;

class ActivityProgressController extends Controller
{
    /**
     * Run check code for a specific item within an activity.
     * This endpoint increments the run count, applies the deduction logic,
     * updates the per-item deducted score, and returns the new values.
     *
     * Route: POST /api/activities/{actID}/check-code/{itemID}
     */
    public function runCheckCode(Request $request, $actID, $itemID)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        // Retrieve the activity to access global deduction settings.
        $activity = Activity::find($actID);
        if (!$activity) {
            return response()->json(['message' => 'Activity not found.'], 404);
        }
        
        // Global settings:
        $checkCodeRestriction = $activity->checkCodeRestriction;
        $deductionPercentage  = $activity->checkCodeDeduction ?? 0; // e.g., 50 means 50%
        $maxRuns              = $activity->maxCheckCodeRuns ?? PHP_INT_MAX;
        
        // Retrieve the ActivityItem to know the item's full points.
        $activityItem = ActivityItem::where('actID', $actID)
            ->where('itemID', $itemID)
            ->first();
        if (!$activityItem) {
            return response()->json(['message' => 'Item not found in this activity.'], 404);
        }
        $itemPoints = $activityItem->actItemPoints;
        
        // Determine the progress record for the user.
        $progressableId = $user->studentID ?? $user->teacherID ?? null;
        if (is_null($progressableId)) {
            return response()->json(['message' => 'User identifier not found.'], 500);
        }
        
        $progress = ActivityProgress::firstOrCreate(
            [
                'actID'             => $actID,
                'progressable_id'   => $progressableId,
                'progressable_type' => get_class($user),
            ],
            [
                'draftFiles'            => null,
                'draftTestCaseResults'  => null,
                'draftCheckCodeRuns'    => json_encode([]),
                'draftDeductedScore'    => json_encode([]), // we won’t use this for frontend display
                'draftTimeRemaining'    => null,
                'draftSelectedLanguage' => null,
                'draftScore'            => 0,
                'draftItemTimes'        => null,
            ]
        );
        
        // Decode existing run count.
        $runsData = $progress->draftCheckCodeRuns ? json_decode($progress->draftCheckCodeRuns, true) : [];

        // Retrieve the existing run count if available.
        $existingRunCount = 0;
        if (isset($runsData[$itemID])) {
            if (is_array($runsData[$itemID]) && isset($runsData[$itemID]['runCount'])) {
                $existingRunCount = $runsData[$itemID]['runCount'];
            } elseif (is_int($runsData[$itemID])) {
                $existingRunCount = $runsData[$itemID];
            }
        }

        // Increment the run count.
        $currentRuns = $existingRunCount + 1;
        if ($currentRuns > $maxRuns) {
            $currentRuns = $maxRuns;
        }

        // Calculate current score (for internal use):
        $currentScore = $itemPoints;
        if ($checkCodeRestriction && $currentRuns > 1 && $deductionPercentage > 0) {
            $extraRuns = $currentRuns - 1;
            $currentScore = round(max($itemPoints - ($itemPoints * ($deductionPercentage / 100.0) * $extraRuns), 0), 2);
        }

        // Save the updated run count as an object.
        $runsData[$itemID] = [
            'runCount'  => $currentRuns,
            'itemScore' => $currentScore,
        ];

        $progress->draftCheckCodeRuns = json_encode($runsData);
        $progress->save();
        
        return response()->json([
            'message'   => 'Check code run completed.',
            'itemID'    => $itemID,
            'runCount'  => $currentRuns,
            'itemScore' => $currentScore,
        ], 200);
    }



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
            'draftCheckCodeRuns'   => 'nullable|json', // per-item check code run counts
            'draftDeductedScore'   => 'nullable|json', // per-item deducted scores
        ]);
        
        // Build an update array with the fields you always want to update.
        $updateData = [
            'draftFiles'            => $validatedData['draftFiles'] ?? null,
            'draftTestCaseResults'  => $validatedData['draftTestCaseResults'] ?? null,
            'draftTimeRemaining'    => $validatedData['draftTimeRemaining'] ?? null,
            'draftSelectedLanguage' => $validatedData['draftSelectedLanguage'] ?? null,
            'draftScore'            => $validatedData['draftScore'] ?? 0,
            'draftItemTimes'        => $validatedData['draftItemTimes'] ?? null,
        ];

        // Only update the check-code runs fields if they exist in the incoming data.
        if (array_key_exists('draftCheckCodeRuns', $validatedData)) {
            $updateData['draftCheckCodeRuns'] = $validatedData['draftCheckCodeRuns'];
        }
        if (array_key_exists('draftDeductedScore', $validatedData)) {
            $updateData['draftDeductedScore'] = $validatedData['draftDeductedScore'];
        }

        $progress = ActivityProgress::updateOrCreate(
            [
                'actID'             => $actID,
                'progressable_id'   => $progressableId,
                'progressable_type' => get_class($user),
            ],
            $updateData
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

            $decodedCheckCodeRuns = $progress->draftCheckCodeRuns 
            ? json_decode($progress->draftCheckCodeRuns, true)
            : null;

            $decodedDeductedScore = $progress->draftDeductedScore 
            ? json_decode($progress->draftDeductedScore, true)
            : null;

            // Rename fields for client consumption.
            $progress->files = $decodedFiles;
            $progress->testCaseResults = $decodedResults;
            $progress->selectedLanguage = $progress->draftSelectedLanguage;
            $progress->itemTimes = $decodedItemTimes;
            $progress->checkCodeRuns = $decodedCheckCodeRuns;
            $progress->deductedScore = $decodedDeductedScore;
            
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