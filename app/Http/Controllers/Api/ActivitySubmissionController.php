<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivitySubmission;
use App\Models\Activity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ActivityProgress;

class ActivitySubmissionController extends Controller
{
    /**
     * Finalize an activity submission for a student on a per-item basis.
     * Expects an array "submissions" where each element includes:
     * - itemID
     * - codeSubmission (optional)
     * - score (optional)
     * - itemTimeSpent (optional)  <-- renamed field
     */
    public function finalizeSubmission(Request $request, $actID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        // 1) Validate incoming data
        $validatedData = $request->validate([
            'submissions' => 'required|array|min:1',
            'submissions.*.itemID'         => 'required|exists:items,itemID',
            'submissions.*.codeSubmission'   => 'nullable|string',
            'submissions.*.score'            => 'nullable|integer',
            'submissions.*.itemTimeSpent'    => 'nullable|integer',
        ]);
    
        // 2) Insert or update the pivot record for attempts tracking
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
    
        // 3) Create per-item submissions for the current attempt
        $createdSubmissions = [];
        foreach ($validatedData['submissions'] as $subData) {
            $submission = ActivitySubmission::create([
                'actID'          => $actID,
                'studentID'      => $student->studentID,
                'itemID'         => $subData['itemID'],
                'attemptNo'      => $attemptsTaken,
                'codeSubmission' => $subData['codeSubmission'] ?? null,
                'score'          => $subData['score'] ?? 0,
                'itemTimeSpent'  => $subData['itemTimeSpent'] ?? 0,
                'submitted_at'   => now(),
            ]);
            $createdSubmissions[] = $submission;
        }
    
        // 4) Do NOT update each submission's score to reflect the best attempt,
        // so that each submission remains as submitted.
        // (This block has been removed.)
    
        // 5) Summarize all attempts for this student in this activity.
        $summaries = ActivitySubmission::select(
                'studentID',
                'attemptNo',
                DB::raw('SUM(score) as totalScore'),
                DB::raw('SUM(itemTimeSpent) as totalTimeSpent')
            )
            ->where('actID', $actID)
            ->where('studentID', $student->studentID)
            ->groupBy('studentID', 'attemptNo')
            ->get();
    
        $activity = Activity::find($actID);
        if ($activity->finalScorePolicy === 'highest_score') {
            $sorted = $summaries->sort(function ($a, $b) {
                if ($a->totalScore == $b->totalScore) {
                    return $a->totalTimeSpent <=> $b->totalTimeSpent;
                }
                return $b->totalScore <=> $a->totalScore;
            })->values();
            $finalSummary = $sorted->first();
        } else {
            // For last_attempt: choose the summary with the highest attemptNo.
            $finalSummary = $summaries->first(function ($sum) use ($summaries) {
                return $sum->attemptNo == $summaries->max('attemptNo');
            });
        }
    
        // Compute rank for THIS student's result by comparing with all students’ pivot records.
        $allPivots = DB::table('activity_student')
            ->where('actID', $actID)
            ->orderByDesc('finalScore')
            ->orderBy('finalTimeSpent')
            ->get();
        $rank = 1;
        foreach ($allPivots as $record) {
            if ($record->studentID == $student->studentID) {
                break;
            }
            $rank++;
        }
    
        // 6) Determine finalScore and finalTimeSpent based on the chosen attempt summary.
        $finalScore = $finalSummary ? $finalSummary->totalScore : 0;
        $finalTimeSpent = $finalSummary ? $finalSummary->totalTimeSpent : 0;
    
        // Only override the finalTimeSpent with the new attempt's overall time if the policy is last_attempt.
        if ($activity->finalScorePolicy === 'last_attempt' && $request->has('overallTimeSpent')) {
            $finalTimeSpent = $request->input('overallTimeSpent');
        }
    
        // 7) Update the pivot record with the new finalScore, finalTimeSpent, and rank.
        DB::table('activity_student')
            ->where('actID', $actID)
            ->where('studentID', $student->studentID)
            ->update([
                'finalScore'     => $finalScore,
                'finalTimeSpent' => $finalTimeSpent,
                'rank'           => $rank,
                'updated_at'     => now()
            ]);
    
        // 8) Clear the progress record for this activity.
        ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $student->studentID)
            ->where('progressable_type', get_class($student))
            ->delete();
    
        return response()->json([
            'message'       => 'Submissions finalized successfully (per item).',
            'submissions'   => $createdSubmissions,
            'attemptsTaken' => $attemptsTaken,
            'rank'          => $rank,
            'finalScore'    => $finalScore,
            'finalTimeSpent'=> $finalTimeSpent
        ], 200);
    }    

    /**
     * Retrieve all submissions for a given activity.
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
            'codeSubmission'  => 'nullable|string',
            'score'           => 'nullable|integer',
            'itemTimeSpent'   => 'nullable|integer',
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

        \Log::info("Incoming request data: " . json_encode($request->all()));

        $validatedData = $request->validate([
            'draftFiles'           => 'nullable|string',
            'draftTestCaseResults' => 'nullable|json',
            'timeRemaining'        => 'nullable|integer',
            'selectedLanguage'     => 'nullable|string',
            'draftScore'           => 'required|integer',
        ]);

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

        $progress = ActivityProgress::updateOrCreate(
            [
                'actID'             => $actID,
                'progressable_id'   => $progressableId,
                'progressable_type' => get_class($user),
            ],
            [
                'draftFiles'           => $validatedData['draftFiles'] ?? null,
                'draftTestCaseResults' => $validatedData['draftTestCaseResults'] ?? null,
                'timeRemaining'        => $validatedData['timeRemaining'] ?? null,
                'selected_language'    => $validatedData['selectedLanguage'] ?? null,
                'draftScore'           => $validatedData['draftScore'],
            ]
        );

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
        $submissions = ActivitySubmission::where('actID', $actID)->with('student')->get();

        $results = $submissions->map(function ($submission) {
            $student = $submission->student;
            return [
                'submissionID'   => $submission->submissionID,
                'studentName'    => $student ? $student->firstname . ' ' . $student->lastname : null,
                'studentNo'      => $student ? $student->student_num : null,
                'program'        => $student ? $student->program : null,
                'score'          => $submission->score,
                'itemTimeSpent'  => $submission->itemTimeSpent,
                'submitted_at'   => $submission->submitted_at,
                'attemptNo'      => $submission->attemptNo,
                'codeSubmission' => json_decode($submission->codeSubmission, true),
                'rank'           => $submission->rank,
            ];
        });

        return response()->json($results);
    }
}