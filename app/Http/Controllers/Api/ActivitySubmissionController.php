<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivitySubmission;
use App\Models\Activity; // To fetch activity details
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
     * - timeSpent (optional)
     */
    public function finalizeSubmission(Request $request, $actID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // 1) Validate the incoming data
        $validatedData = $request->validate([
            'submissions' => 'required|array|min:1',
            'submissions.*.itemID'         => 'required|exists:items,itemID',
            'submissions.*.codeSubmission' => 'nullable|string',
            'submissions.*.score'          => 'nullable|integer',
            'submissions.*.timeSpent'      => 'nullable|integer',
        ]);

        // 2) Update or insert pivot record in "activity_student" to track attemptNo.
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

        // 3) Create per-item submissions.
        $createdSubmissions = [];
        foreach ($validatedData['submissions'] as $subData) {
            $submission = ActivitySubmission::create([
                'actID'         => $actID,
                'studentID'     => $student->studentID,
                'itemID'        => $subData['itemID'],
                'attemptNo'     => $attemptsTaken,
                'codeSubmission'=> $subData['codeSubmission'] ?? null,
                'score'         => $subData['score'] ?? 0, // partial, per-item score
                'timeSpent'     => $subData['timeSpent'] ?? 0,
                'submitted_at'  => now(),
            ]);
            $createdSubmissions[] = $submission;
        }

        // 4) If finalScorePolicy is 'highest_score', update each item's score with the highest so far.
        $activity = Activity::find($actID);
        if ($activity && $activity->finalScorePolicy === 'highest_score') {
            foreach ($createdSubmissions as $submission) {
                $highestSubmission = ActivitySubmission::where('actID', $actID)
                    ->where('studentID', $student->studentID)
                    ->where('itemID', $submission->itemID)
                    ->orderByDesc('score')
                    ->first();
                if ($highestSubmission && $highestSubmission->score > $submission->score) {
                    $submission->score = $highestSubmission->score;
                    $submission->save();
                }
            }
        }

        // 5) Group submissions per attempt to compute overall totals.
        $summaries = ActivitySubmission::select(
                'studentID',
                'attemptNo',
                DB::raw('SUM(score) as totalScore'),
                DB::raw('SUM(timeSpent) as totalTimeSpent')
            )
            ->where('actID', $actID)
            ->groupBy('studentID', 'attemptNo')
            ->get();

        // Sort summaries: totalScore descending, then totalTimeSpent ascending.
        $sorted = $summaries->sort(function ($a, $b) {
            if ($a->totalScore == $b->totalScore) {
                return $a->totalTimeSpent <=> $b->totalTimeSpent;
            }
            return $b->totalScore <=> $a->totalScore;
        })->values();

        // Compute rank for the current attempt.
        $rank = 1;
        foreach ($sorted as $summary) {
            if ($summary->studentID == $student->studentID && $summary->attemptNo == $attemptsTaken) {
                break;
            }
            $rank++;
        }

        // Update rank on all newly created submissions for the current attempt.
        ActivitySubmission::where('actID', $actID)
            ->where('studentID', $student->studentID)
            ->where('attemptNo', $attemptsTaken)
            ->update(['rank' => $rank]);

        // 6) Update pivot table with overall finalScore and finalTimeSpent.
        $currentAttemptSummary = $summaries->first(function ($summary) use ($student, $attemptsTaken) {
            return $summary->studentID == $student->studentID && $summary->attemptNo == $attemptsTaken;
        });
        $finalScore = $currentAttemptSummary ? $currentAttemptSummary->totalScore : 0;
        $finalTimeSpent = $currentAttemptSummary ? $currentAttemptSummary->totalTimeSpent : 0;
        DB::table('activity_student')
            ->where('actID', $actID)
            ->where('studentID', $student->studentID)
            ->update([
                'finalScore'      => $finalScore,
                'finalTimeSpent'  => $finalTimeSpent
            ]);

        // 7) Clear progress.
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
                'submissionID' => $submission->submissionID,
                'studentName'  => $student ? $student->firstname . ' ' . $student->lastname : null,
                'studentNo'    => $student ? $student->student_num : null,
                'program'      => $student ? $student->program : null,
                'score'        => $submission->score,
                'timeSpent'    => $submission->timeSpent,
                'submitted_at' => $submission->submitted_at,
                'attemptNo'    => $submission->attemptNo,
                'codeSubmission' => json_decode($submission->codeSubmission, true),
                'rank'         => $submission->rank,
            ];
        });

        return response()->json($results);
    }
}