<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivitySubmission;
use App\Models\Activity; // Added to fetch activity details
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ActivityProgress;

class ActivitySubmissionController extends Controller
{
    /**
     * Finalize an activity submission for a student (activity-level).
     */
    public function finalizeSubmission(Request $request, $actID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        // Validate incoming data.
        $validatedData = $request->validate([
            'codeSubmission' => 'nullable|string', // JSON string that can contain all files/items.
            'score'          => 'nullable|integer',
            'timeSpent'      => 'nullable|integer',  // Stored as seconds (or minutes) – be consistent.
        ]);
    
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
    
        // Create a new submission record for the entire activity.
        $submission = ActivitySubmission::create([
            'actID'         => $actID,
            'studentID'     => $student->studentID,
            'attemptNo'     => $attemptsTaken,
            'codeSubmission'=> $validatedData['codeSubmission'] ?? null,
            'score'         => $validatedData['score'] ?? 0,
            'timeSpent'     => $validatedData['timeSpent'] ?? 0,
            'submitted_at'  => now(),
        ]);
    
        // If a progress record exists and includes a draftScore, update the submission’s score.
        $progress = ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $student->studentID)
            ->where('progressable_type', get_class($student))
            ->first();
        if ($progress && isset($progress->draftScore)) {
            $submission->score = $progress->draftScore;
            $submission->save();
        }
    
        // Retrieve the activity and adjust the final score based on finalScorePolicy.
        $activity = Activity::find($actID);
        if ($activity) {
            if ($activity->finalScorePolicy === 'highest_score') {
                // For highest score policy, retrieve the highest submission score for this student.
                $highestSubmission = ActivitySubmission::where('actID', $actID)
                    ->where('studentID', $student->studentID)
                    ->orderByDesc('score')
                    ->first();
                if ($highestSubmission) {
                    $submission->score = $highestSubmission->score;
                    $submission->save();
                }
            }
            // For 'last_attempt', we keep the current submission score.
        }
    
        // Calculate the student's rank using tie-breakers:
        // Order by descending score, then ascending timeSpent, then by student's name alphabetically.
        $submissions = ActivitySubmission::where('actID', $actID)
            ->with('student')
            ->get();
    
        $sortedSubmissions = $submissions->sort(function ($a, $b) {
            // Compare by score descending.
            if ($a->score == $b->score) {
                // Compare by timeSpent ascending (less time is better).
                if ($a->timeSpent == $b->timeSpent) {
                    // Compare by student name alphabetically (last name then first name).
                    $nameA = strtolower(trim($a->student ? ($a->student->lastname . ' ' . $a->student->firstname) : ''));
                    $nameB = strtolower(trim($b->student ? ($b->student->lastname . ' ' . $b->student->firstname) : ''));
                    return strcmp($nameA, $nameB);
                }
                return $a->timeSpent - $b->timeSpent;
            }
            return $b->score - $a->score;
        });
    
        $rank = 1;
        foreach ($sortedSubmissions as $sub) {
            // Ensure we identify the correct submission by matching studentID and attemptNo.
            if ($sub->studentID == $student->studentID && $sub->attemptNo == $attemptsTaken) {
                break;
            }
            $rank++;
        }
        $submission->rank = $rank;
        $submission->save();
    
        // Clear the progress record for the entire activity.
        ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $student->studentID)
            ->where('progressable_type', get_class($student))
            ->delete();
    
        return response()->json([
            'message'       => 'Submission finalized successfully (activity-level).',
            'submission'    => $submission,
            'attemptsTaken' => $attemptsTaken,
        ], 200);
    }
    
    /**
     * Retrieve all submissions for a given activity.
     * This endpoint can be used by teachers for review.
     */
    public function index($actID)
    {
        $user = Auth::user();
        $submissions = ActivitySubmission::where('actID', $actID)->get();
        return response()->json($submissions);
    }
    
    /**
     * Update an existing activity submission.
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
        \Log::info("saveProgress called for activity $actID by user: " . (Auth::id() ?? 'guest'));
    
        // Log the raw request data
        \Log::info("Incoming request data: " . json_encode($request->all()));
    
        // Validate the data, requiring draftScore to be an integer
        $validatedData = $request->validate([
            'draftFiles'           => 'nullable|string',
            'draftTestCaseResults' => 'nullable|json',
            'timeRemaining'        => 'nullable|integer',
            'selectedLanguage'     => 'nullable|string',
            'draftScore'           => 'required|integer',  // force an integer
        ]);
    
        // Log after validation
        \Log::info("Validated data: " . json_encode($validatedData));
    
        $user = Auth::user();
        if (!$user) {
            \Log::warning("No authenticated user found! Exiting saveProgress...");
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $progressableId = $user->studentID ?? $user->teacherID ?? null;
    
        if (is_null($progressableId)) {
            \Log::error("No progressable ID found for user " . Auth::id());
            return response()->json(['message' => 'User identifier not found.'], 500);
        }
    
        // Update or create the record
        $progress = \App\Models\ActivityProgress::updateOrCreate(
            [
                'actID'             => $actID,
                'progressable_id'   => $progressableId,
                'progressable_type' => get_class($user),
            ],
            [
                'draftFiles'          => $validatedData['draftFiles'] ?? null,
                'draftTestCaseResults'=> $validatedData['draftTestCaseResults'] ?? null,
                'timeRemaining'       => $validatedData['timeRemaining'] ?? null,
                'selected_language'   => $validatedData['selectedLanguage'] ?? null,
                'draftScore'          => $validatedData['draftScore'], // store the integer
            ]
        );
    
        // Finally, log what was saved to the DB
        \Log::info("Saved/updated progress record: " . $progress->toJson());
    
        return response()->json([
            'message'  => 'Progress saved successfully.',
            'progress' => $progress,
        ], 200);
    }
    
    
    /**
     * Retrieve and review submissions for a given activity.
     */
    public function reviewSubmissions($actID)
    {
        // Assume teacher is authenticated.
        $submissions = ActivitySubmission::where('actID', $actID)->with('student')->get();
        
        $results = $submissions->map(function ($submission) {
            $student = $submission->student;
            return [
                'submissionID' => $submission->submissionID,
                'studentName'  => $student ? $student->firstname . ' ' . $student->lastname : null,
                'studentNo'    => $student ? $student->student_num : null,
                'program'      => $student ? $student->program : null,
                'score'        => $submission->score,
                'timeSpent'    => $submission->timeSpent, // Convert on front end to HH:MM:SS
                'submitted_at' => $submission->submitted_at,
                'attemptNo'    => $submission->attemptNo,
                'codeSubmission' => json_decode($submission->codeSubmission, true),
                'rank'         => $submission->rank,
            ];
        });
        
        return response()->json($results);
    }
}