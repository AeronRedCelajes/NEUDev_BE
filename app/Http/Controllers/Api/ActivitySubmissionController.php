<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ActivitySubmission;
use App\Models\ActivityItem;
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
     * - itemTimeSpent (optional)
     * - timeSpent (optional) <-- overall time for the attempt
     * - testCaseResults (optional)
     * - timeRemaining (optional)
     * - selectedLanguage (optional)
     */
    public function finalizeSubmission(Request $request, $actID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // 1) Validate incoming data.
        $validatedData = $request->validate([
            'submissions' => 'required|array|min:1',
            'submissions.*.itemID'         => 'required|exists:items,itemID',
            'submissions.*.codeSubmission'   => 'nullable|string',
            'submissions.*.score'            => 'nullable|numeric',
            'submissions.*.itemTimeSpent'    => 'nullable|integer',
            'submissions.*.timeSpent'        => 'nullable|integer',
            'submissions.*.testCaseResults'  => 'nullable|string',
            'submissions.*.timeRemaining'    => 'nullable|integer',
            'submissions.*.selectedLanguage' => 'nullable|string',
        ]);

        // 2) Insert or update the pivot record for attempts tracking.
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

        // 3) Create per-item submissions for the current attempt.
        $createdSubmissions = [];
        foreach ($validatedData['submissions'] as $subData) {
            $submission = ActivitySubmission::create([
                'actID'            => $actID,
                'studentID'        => $student->studentID,
                'itemID'           => $subData['itemID'],
                'attemptNo'        => $attemptsTaken,
                'codeSubmission'   => $subData['codeSubmission'] ?? null,
                'score'            => $subData['score'] ?? 0,
                'itemTimeSpent'    => $subData['itemTimeSpent'] ?? 0,
                'timeSpent'        => $subData['timeSpent'] ?? 0,
                'testCaseResults'  => $subData['testCaseResults'] ?? null,
                'timeRemaining'    => $subData['timeRemaining'] ?? null,
                'selectedLanguage' => $subData['selectedLanguage'] ?? null,
                'submitted_at'     => now(),
            ]);
            $createdSubmissions[] = $submission;
        }

        // 4) Retrieve the progress record which holds the draft check code runs and deducted scores.
        $progressRecord = ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $student->studentID)
            ->where('progressable_type', get_class($student))
            ->first();

        // 4a) Update submissions with the run counts from the progress record.
        if ($progressRecord && $progressRecord->draftCheckCodeRuns) {
            // Decode the JSON field that tracks check code runs per item.
            $checkCodeData = json_decode($progressRecord->draftCheckCodeRuns, true);
            foreach ($createdSubmissions as $submission) {
                // For each submission, assign the run count; default to 0 if not set.
                $runs = isset($checkCodeData[$submission->itemID]) ? $checkCodeData[$submission->itemID] : 0;
                $submission->checkCodeRuns = $runs;
                $submission->save();
            }
        }

        // 4b) Also, update submissions with the deducted score from the progress record.
        // This ensures that if the check code deduction was applied in real time,
        // the final submission reflects that deducted score.
        $progressRecord = ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $student->studentID)
            ->where('progressable_type', get_class($student))
            ->first();

        if ($progressRecord) {
            // Decode both JSON fields.
            $checkCodeData = $progressRecord->draftCheckCodeRuns ? json_decode($progressRecord->draftCheckCodeRuns, true) : [];
            $deductedScores = $progressRecord->draftDeductedScore ? json_decode($progressRecord->draftDeductedScore, true) : [];
            foreach ($createdSubmissions as $submission) {
                $runs = isset($checkCodeData[$submission->itemID]) ? $checkCodeData[$submission->itemID] : 0;
                $submission->checkCodeRuns = $runs;
                // If a deducted score is recorded for this item, update the submission score.
                if (isset($deductedScores[$submission->itemID])) {
                    $submission->score = $deductedScores[$submission->itemID];
                }
                $submission->save();
            }
        }

        // 5) Summarize all attempts for this student in this activity.
        // We use SUM(score) and MAX(timeSpent) to capture overall performance.
        $summaries = ActivitySubmission::select(
                'studentID',
                'attemptNo',
                DB::raw('SUM(score) as totalScore'),
                DB::raw('MAX(timeSpent) as totalTimeSpent')
            )
            ->where('actID', $actID)
            ->where('studentID', $student->studentID)
            ->groupBy('studentID', 'attemptNo')
            ->get();

        // 6) Determine which attempt summary to use based on finalScorePolicy.
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
            // For 'last_attempt', choose the summary with the highest attempt number.
            $finalSummary = $summaries->first(function ($sum) use ($summaries) {
                return $sum->attemptNo == $summaries->max('attemptNo');
            });
        }

        // 7) Compute rank for this student's result by comparing pivot records.
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

        // 8) Determine finalScore and finalTimeSpent based on the chosen attempt summary.
        $finalScore = $finalSummary ? $finalSummary->totalScore : 0;
        $finalTimeSpent = $finalSummary ? $finalSummary->totalTimeSpent : 0;

        // Optionally, use the progress record's overall timeSpent if available.
        $progressRecord = ActivityProgress::where('actID', $actID)
            ->where('progressable_id', $student->studentID)
            ->where('progressable_type', get_class($student))
            ->first();
        if ($progressRecord && isset($progressRecord->timeSpent)) {
            $finalTimeSpent = $progressRecord->timeSpent;
        }

        // 9) Retrieve the activity to get global deduction settings.
        // This is a safeguard: it re-applies deduction logic for each submission.
        $activity = Activity::find($actID);
        $deductionPercentage = $activity->checkCodeDeduction; // e.g., 10 means 10%
        $maxRuns = $activity->maxCheckCodeRuns ?? PHP_INT_MAX;

        foreach ($createdSubmissions as $submission) {
            // For each submission, fetch the original full points from the ActivityItem.
            $activityItem = ActivityItem::where('actID', $actID)
                ->where('itemID', $submission->itemID)
                ->first();
            $originalPoints = $activityItem ? $activityItem->actItemPoints : $submission->score; // fallback

            $runs = $submission->checkCodeRuns;
            if ($runs > $maxRuns) {
                $runs = $maxRuns;
                $submission->checkCodeRuns = $runs;
                $submission->save();
            }
            if ($runs > 1 && $deductionPercentage) {
                $extraRuns = $runs - 1;
                // Calculate deduction from the original full points.
                $deduction = $originalPoints * ($deductionPercentage / 100.0) * $extraRuns;
                $newScore = max($originalPoints - $deduction, 0);
                // Round the new score to avoid floating point precision issues.
                $newScore = round($newScore, 2);
                $submission->score = $newScore;
                $submission->save();
            }
        }

        // 10) Update the pivot record with the new finalScore, finalTimeSpent, and rank.
        DB::table('activity_student')
            ->where('actID', $actID)
            ->where('studentID', $student->studentID)
            ->update([
                'finalScore'     => $finalScore,
                'finalTimeSpent' => $finalTimeSpent,
                'rank'           => $rank,
                'updated_at'     => now()
            ]);

        // 11) Clear the progress record for this activity.
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
            'score'           => 'nullable|numeric',
            'itemTimeSpent'   => 'nullable|integer',
            'timeSpent'       => 'nullable|integer',
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
            'draftScore'           => 'required|numeric',
            // Validate draftTimeSpent which will be stored in the "timeSpent" column.
            'draftTimeSpent'       => 'nullable|integer',
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
                'draftScore'           => $validatedData['draftScore'] ?? 0,
                // Save the draftTimeSpent into the "timeSpent" column.
                'timeSpent'            => $validatedData['draftTimeSpent'] ?? null,
            ]
        );

        \Log::info("Saved/updated progress record: " . $progress->toJson());

        return response()->json([
            'message'  => 'Progress saved successfully.',
            'progress' => $progress,
        ], 200);
    }

    /**
     * Retrieve progress for the authenticated user for a given activity.
     * Calculates an "endTime" based on the stored timeRemaining and updated_at timestamp.
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
            $updatedMs = strtotime($progress->updated_at) * 1000;
            $progress->endTime = $progress->timeRemaining !== null
                ? $updatedMs + ($progress->timeRemaining * 1000)
                : null;

            $decodedFiles = $progress->draftFiles 
                ? json_decode($progress->draftFiles, true) 
                : null;
            $decodedResults = $progress->draftTestCaseResults 
                ? json_decode($progress->draftTestCaseResults, true) 
                : null;
            $decodedItemTimes = $progress->itemTimes
                ? json_decode($progress->itemTimes, true)
                : null;

            $progress->files = $decodedFiles;
            $progress->testCaseResults = $decodedResults;
            $progress->selectedLanguage = $progress->selected_language;
            $progress->itemTimes = $decodedItemTimes;

            unset($progress->draftFiles, $progress->draftTestCaseResults, $progress->selected_language);
        }

        return response()->json([
            'progress' => $progress ? [$progress] : [],
        ]);
    }

    /**
     * Clear progress for the authenticated user for a given activity.
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

    /**
     * Retrieve and review submissions for a given activity (for teachers).
     * Returns for each student:
     * - studentID, studentName, program, profileImage,
     * - overallScore, overallTimeSpent (from the pivot record),
     * - a list of attempts (each with attemptNo, totalScore, totalTimeSpent).
     */
    public function getActivitySubmissionByTeacher($actID)
    {
        // Verify teacher.
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Fetch the activity to know its classID.
        $activity = Activity::with('classroom')->find($actID);
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        // Get pivot records for this activity, but join with class_student to filter unenrolled students.
        $pivots = DB::table('activity_student as astu')
            ->join('class_student as cs', function($join) use ($activity) {
                $join->on('astu.studentID', '=', 'cs.studentID')
                    ->where('cs.classID', '=', $activity->classID);
            })
            ->where('astu.actID', $actID)
            ->get();

        if ($pivots->isEmpty()) {
            return response()->json([
                'message' => 'No student submissions found for this activity.'
            ], 200);
        }

        $results = [];
        foreach ($pivots as $pivot) {
            $student = DB::table('students')
                ->where('studentID', $pivot->studentID)
                ->first();
            if (!$student) {
                continue;
            }

            // Group attempts for this student.
            $attempts = DB::table('activity_submissions')
                ->select(
                    'attemptNo',
                    DB::raw('SUM(score) as totalScore'),
                    DB::raw('MAX(timeSpent) as totalTimeSpent')
                )
                ->where('actID', $actID)
                ->where('studentID', $student->studentID)
                ->groupBy('attemptNo')
                ->orderBy('attemptNo', 'asc')
                ->get();

            $results[] = [
                'studentID'        => $student->studentID,
                'studentName'      => $student->firstname . ' ' . $student->lastname,
                'program'          => $student->program,
                'profileImage'     => $student->profileImage ? asset('storage/' . $student->profileImage) : null,
                'overallScore'     => $pivot->finalScore,
                'overallTimeSpent' => $pivot->finalTimeSpent,
                'attempts'         => $attempts  // Each attempt: { attemptNo, totalScore, totalTimeSpent }
            ];
        }

        return response()->json([
            'activityID'  => $actID,
            'submissions' => $results
        ]);
    }


    /**
     * Retrieve detailed submission data for a given activity, student, and attempt.
     * Now includes timeRemaining, selectedLanguage, etc. in the response.
     */
    public function getSubmissionDetail(Request $request, $actID)
    {
        // 1) Verify teacher authorization
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // 2) Read query params
        $studentID = $request->query('studentID');
        $attemptNo = $request->query('attemptNo');

        // 3) Fetch all submissions for the attempt
        $submissions = ActivitySubmission::where('actID', $actID)
            ->where('studentID', $studentID)
            ->where('attemptNo', $attemptNo)
            ->get();

        if ($submissions->isEmpty()) {
            return response()->json(['message' => 'No submission details found for this attempt.'], 404);
        }

        // 4) Compute overall score & time
        $overallScore = $submissions->sum('score');
        // Use MAX(timeSpent) for overallTimeSpent
        $overallTimeSpent = $submissions->max('timeSpent');

        // 5) Fetch the student
        $student = \DB::table('students')
            ->where('studentID', $studentID)
            ->first();
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        // 6) Build an array of item-level submission data
        //    This includes timeRemaining, selectedLanguage, etc.
        $detailedItems = $submissions->map(function ($submission) {
            // Decode JSON fields if needed
            $decodedCode = $submission->codeSubmission
                ? json_decode($submission->codeSubmission, true)
                : null;
            $decodedResults = $submission->testCaseResults
                ? json_decode($submission->testCaseResults, true)
                : null;

            return [
                'submissionID'      => $submission->submissionID,
                'itemID'            => $submission->itemID,
                'codeSubmission'    => $decodedCode,
                'testCaseResults'   => $decodedResults,
                'timeRemaining'     => $submission->timeRemaining,
                'selectedLanguage'  => $submission->selectedLanguage,
                'score'             => $submission->score,
                'checkCodeRuns'     => $submission->checkCodeRuns,
                'itemTimeSpent'     => $submission->itemTimeSpent,
                'timeSpent'         => $submission->timeSpent,
                'submitted_at'      => $submission->submitted_at,
            ];
        });

        // 7) Build the final response
        $responseData = [
            'studentID'        => $studentID,
            'studentName'      => $student->firstname.' '.$student->lastname,
            'program'          => $student->program,
            'overallScore'     => $overallScore,
            'overallTimeSpent' => $overallTimeSpent,
            'attemptNo'        => $attemptNo,
            'items'            => $detailedItems,
        ];

        return response()->json($responseData, 200);
    }
}