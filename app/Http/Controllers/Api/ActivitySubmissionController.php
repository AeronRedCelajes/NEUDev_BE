<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivitySubmission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ActivitySubmissionController extends Controller
{
    /**
     * Finalize an activity submission for a student.
     */
    public function finalizeSubmission(Request $request, $actID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Validate incoming data.
        // Note: codeSubmission will be a JSON string containing an array of file objects.
        $validatedData = $request->validate([
            'itemID'         => 'required|exists:items,itemID',
            'codeSubmission' => 'nullable|string', // JSON encoded multi-file submission
            'score'          => 'nullable|integer',
            'timeSpent'      => 'nullable|integer',  // timeSpent is in seconds (or minutes) for calculations
        ]);

        // Create or update the submission record for this item.
        $submission = ActivitySubmission::updateOrCreate(
            [
                'actID'     => $actID,
                'studentID' => $student->studentID,
                'itemID'    => $validatedData['itemID'],
            ],
            [
                'codeSubmission' => $validatedData['codeSubmission'] ?? null,
                'score'          => $validatedData['score'] ?? 0,
                'timeSpent'      => $validatedData['timeSpent'] ?? 0,
                'submitted_at'   => now(),
            ]
        );

        // Update the overall attempt count in the pivot table "activity_student".
        $pivot = DB::table('activity_student')
            ->where('actID', $actID)
            ->where('studentID', $student->studentID)
            ->first();

        if (!$pivot) {
            DB::table('activity_student')->insert([
                'actID'         => $actID,
                'studentID'     => $student->studentID,
                'attemptsTaken' => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            $attemptsTaken = 1;
        } else {
            $attemptsTaken = $pivot->attemptsTaken + 1;
            DB::table('activity_student')
                ->where('actID', $actID)
                ->where('studentID', $student->studentID)
                ->update([
                    'attemptsTaken' => $attemptsTaken,
                    'updated_at'    => now(),
                ]);
        }

        // Optionally, clear the progress record so a new attempt starts fresh.
        \App\Models\ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $student->studentID)
            ->where('progressable_type', get_class($student))
            ->delete();

        return response()->json([
            'message'       => 'Submission finalized successfully.',
            'submission'    => $submission,
            'attemptsTaken' => $attemptsTaken,
        ], 200);
    }

    /**
     * Update an existing activity submission.
     * This allows a student to edit a previously submitted attempt.
     */
    public function updateSubmission(Request $request, $actID, $submissionID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validatedData = $request->validate([
            'codeSubmission' => 'nullable|string',
            'score'          => 'nullable|integer',
            'timeSpent'      => 'nullable|integer',
        ]);

        $submission = ActivitySubmission::find($submissionID);
        if (!$submission || $submission->studentID !== $student->studentID) {
            return response()->json(['message' => 'Submission not found or unauthorized'], 404);
        }

        $submission->update($validatedData);

        return response()->json([
            'message'    => 'Submission updated successfully.',
            'submission' => $submission,
        ], 200);
    }

    /**
     * Delete an existing activity submission.
     */
    public function deleteSubmission($actID, $submissionID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $submission = ActivitySubmission::find($submissionID);
        if (!$submission || $submission->studentID !== $student->studentID) {
            return response()->json(['message' => 'Submission not found or unauthorized'], 404);
        }

        $submission->delete();

        return response()->json(['message' => 'Submission deleted successfully.'], 200);
    }

    /**
     * Save progress (draft submission) for an activity.
     */
    public function saveProgress(Request $request, $actID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Validate draft data; here we expect a JSON array in draftFiles.
        $validatedData = $request->validate([
            'itemID'              => 'nullable|exists:items,itemID',  // if per-item progress
            'draftFiles'          => 'nullable|string', // JSON encoded array of file objects
            'draftTestCaseResults'=> 'nullable|json',
            'timeRemaining'       => 'nullable|integer', // seconds remaining
        ]);

        $progress = \App\Models\ActivityProgress::updateOrCreate(
            [
                'actID'     => $actID,
                'studentID' => $student->studentID,
                'itemID'    => $validatedData['itemID'] ?? null,
            ],
            [
                'draftFiles'           => $validatedData['draftFiles'] ?? null,
                'draftTestCaseResults' => $validatedData['draftTestCaseResults'] ?? null,
                'timeRemaining'        => $validatedData['timeRemaining'] ?? null,
            ]
        );

        return response()->json([
            'message'  => 'Progress saved successfully.',
            'progress' => $progress,
        ]);
    }
}