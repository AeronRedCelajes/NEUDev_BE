<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\ActivityItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    ///////////////////////////////////////////////////
    // FUNCTIONS FOR CLASS MANAGEMENT PAGE VIA ACTIVITY
    ///////////////////////////////////////////////////

    /**
     * Get all activities for the authenticated student, categorized into Ongoing and Completed.
     */
    public function showStudentActivities()
    {
        $student = Auth::user();

        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $enrolledClassIDs = $student->classes()->pluck('class_student.classID');

        if ($enrolledClassIDs->isEmpty()) {
            return response()->json(['message' => 'You are not enrolled in any class.'], 404);
        }

        $now = now();

        // 1. Fetch upcoming activities (openDate > now)
        $upcomingActivities = Activity::with(['teacher', 'programmingLanguages'])
            ->whereIn('classID', $enrolledClassIDs)
            ->where('openDate', '>', $now)
            ->orderBy('openDate', 'asc')
            ->get();

        // 2. Fetch ongoing activities (openDate <= now && closeDate >= now)
        $ongoingActivities = Activity::with(['teacher', 'programmingLanguages'])
            ->whereIn('classID', $enrolledClassIDs)
            ->where('openDate', '<=', $now)
            ->where('closeDate', '>=', $now)
            ->orderBy('openDate', 'asc')
            ->get();

        // 3. Fetch completed activities (closeDate < now)
        $completedActivities = Activity::with(['teacher', 'programmingLanguages'])
            ->whereIn('classID', $enrolledClassIDs)
            ->where('closeDate', '<', $now)
            ->orderBy('closeDate', 'desc')
            ->get();

        // Attach student-specific details (Rank, Score, Duration, etc.)
        $upcomingActivities  = $this->attachStudentDetails($upcomingActivities, $student);
        $ongoingActivities   = $this->attachStudentDetails($ongoingActivities, $student);
        $completedActivities = $this->attachStudentDetails($completedActivities, $student);

        if (
            $upcomingActivities->isEmpty() &&
            $ongoingActivities->isEmpty() &&
            $completedActivities->isEmpty()
        ) {
            return response()->json([
                'message'  => 'No activities found.',
                'upcoming' => [],
                'ongoing'  => [],
                'completed'=> []
            ], 200);
        }

        return response()->json([
            'upcoming'  => $upcomingActivities,
            'ongoing'   => $ongoingActivities,
            'completed' => $completedActivities
        ]);
    }

    /**
     * Attach Rank, Overall Score, and other details for the student in the activity list.
     * Now includes "scorePercentage" and a formatted timeSpent.
     */
    private function attachStudentDetails($activities, $student)
    {
        return $activities->map(function ($activity) use ($student) {
            // Retrieve the pivot record for this student and activity.
            $attemptData = DB::table('activity_student')
                ->where('actID', $activity->actID)
                ->where('studentID', $student->studentID)
                ->first();

            // Use the pivot's finalScore and finalTimeSpent if available.
            $overallScore = $attemptData ? $attemptData->finalScore : 0;
            $overallTime = $attemptData ? $attemptData->finalTimeSpent : 0;
            $maxPoints = $activity->maxPoints ?: 1;
            $scorePercentage = ($maxPoints > 0)
                ? round(($overallScore / $maxPoints) * 100, 2)
                : null;

            // Format overallTime using the helper.
            $formattedTime = $overallTime ? $this->formatSecondsToHMS($overallTime) : '-';

            // Also retrieve the number of attempts from the pivot.
            $attemptsTaken = $attemptData ? $attemptData->attemptsTaken : 0;

            // Calculate rank by comparing with all pivot records for the same activity.
            $allAttempts = DB::table('activity_student')
                ->where('actID', $activity->actID)
                ->orderByDesc('finalScore')
                ->orderBy('finalTimeSpent')
                ->get();
            $rank = null;
            foreach ($allAttempts as $index => $record) {
                if ($record->studentID == $student->studentID) {
                    $rank = $index + 1;
                    break;
                }
            }

            return [
                'actID'               => $activity->actID,
                'actTitle'            => $activity->actTitle,
                'actDesc'             => $activity->actDesc,
                'classID'             => $activity->classID,
                'teacherName'         => optional($activity->teacher)->firstname . ' ' . optional($activity->teacher)->lastname,
                'actDifficulty'       => $activity->actDifficulty,
                'actDuration'         => $activity->actDuration,
                'actAttempts'         => $activity->actAttempts,
                'openDate'            => $activity->openDate,
                'closeDate'           => $activity->closeDate,
                'programmingLanguages'=> $activity->programmingLanguages->isNotEmpty()
                    ? $activity->programmingLanguages->pluck('progLangName')->toArray()
                    : 'N/A',
                // Overall student-specific fields.
                'rank'               => $rank,
                'overallScore'       => $overallScore,
                'maxPoints'          => $activity->maxPoints,
                'finalScorePolicy'   => $activity->finalScorePolicy,
                'scorePercentage'    => $scorePercentage,
                'attemptsTaken'      => $attemptsTaken,
                'studentTimeSpent'   => $formattedTime,
            ];
        });
    }

    /**
     * Private helper: Format seconds into HH:MM:SS.
     */
    private function formatSecondsToHMS($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
    }

    /**
     * Recalculate final results (finalScore and finalTimeSpent) for each student's pivot record.
     * This should be called when the final score policy changes.
     * (It is implemented as a private helper method.)
     */
    private function recalcFinalResults($actID)
    {
        // Get the activity to read its current finalScorePolicy.
        $activity = Activity::find($actID);
        if (!$activity) {
            return;
        }
    
        // Get all pivot records for this activity.
        $pivots = DB::table('activity_student')->where('actID', $actID)->get();
    
        foreach ($pivots as $pivot) {
            $studentID = $pivot->studentID;
            // Get all attempt summaries for this student.
            $summaries = ActivitySubmission::select(
                    'studentID',
                    'attemptNo',
                    DB::raw('SUM(score) as totalScore'),
                    DB::raw('SUM(itemTimeSpent) as totalTimeSpent')
                )
                ->where('actID', $actID)
                ->where('studentID', $studentID)
                ->groupBy('studentID', 'attemptNo')
                ->get();
    
            if ($activity->finalScorePolicy === 'highest_score') {
                // Pick the attempt with the highest totalScore (and if tied, lowest totalTimeSpent).
                $sorted = $summaries->sort(function ($a, $b) {
                    if ($a->totalScore == $b->totalScore) {
                        return $a->totalTimeSpent <=> $b->totalTimeSpent;
                    }
                    return $b->totalScore <=> $a->totalScore;
                })->values();
                $final = $sorted->first();
            } else {
                // For last_attempt, pick the attempt with the highest attemptNo.
                $maxAttempt = $summaries->max('attemptNo');
                $final = $summaries->firstWhere('attemptNo', $maxAttempt);
            }
    
            // Update the pivot record for this student.
            if ($final) {
                DB::table('activity_student')
                    ->where('actID', $actID)
                    ->where('studentID', $studentID)
                    ->update([
                        'finalScore'     => $final->totalScore,
                        'finalTimeSpent' => $final->totalTimeSpent,
                        'updated_at'     => now()
                    ]);
            }
        }
    }

    /**
     * Create an activity (Only for Teachers)
     */
    public function store(Request $request)
    {
        try {
            $teacher = Auth::user();

            if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Validate input.
            $validator = \Validator::make($request->all(), [
                'progLangIDs'           => 'required|array',
                'progLangIDs.*'         => 'exists:programming_languages,progLangID',
                'actTitle'              => 'required|string|max:255',
                'actDesc'               => 'required|string',
                'actDifficulty'         => 'required|in:Beginner,Intermediate,Advanced',
                'actDuration'           => [
                    'required',
                    'regex:/^(0\d|1\d|2[0-3]):([0-5]\d):([0-5]\d)$/'
                ],
                'actAttempts'           => 'required|integer|min:0',
                'openDate'              => 'required|date',
                'closeDate'             => 'required|date|after:openDate',
                'maxPoints'             => 'required|numeric|min:1',
                'items'                 => 'required|array|min:1',
                'items.*.itemID'        => 'required|exists:items,itemID',
                'items.*.itemTypeID'    => 'required|exists:item_types,itemTypeID',
                'items.*.actItemPoints' => 'required|numeric|min:1',
                'finalScorePolicy'      => 'required|in:last_attempt,highest_score',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // Create the activity.
            $activity = Activity::create([
                'classID'          => $request->classID,
                'teacherID'        => $teacher->teacherID,
                'actTitle'         => $request->actTitle,
                'actDesc'          => $request->actDesc,
                'actDifficulty'    => $request->actDifficulty,
                'actDuration'      => $request->actDuration,
                'openDate'         => $request->openDate,
                'closeDate'        => $request->closeDate,
                'maxPoints'        => $request->maxPoints,
                'actAttempts'      => $request->actAttempts,
                'classAvgScore'    => null,
                'finalScorePolicy' => $request->finalScorePolicy,
            ]);

            // Attach programming languages.
            $activity->programmingLanguages()->attach($request->progLangIDs);

            // Attach selected items with points.
            foreach ($request->items as $item) {
                ActivityItem::create([
                    'actID'         => $activity->actID,
                    'itemID'        => $item['itemID'],
                    'itemTypeID'    => $item['itemTypeID'],
                    'actItemPoints' => $item['actItemPoints'],
                ]);
            }

            // Automatically calculate the total points from the provided items.
            $totalPoints = array_sum(array_column($request->items, 'actItemPoints'));
            $activity->update(['maxPoints' => $totalPoints]);

            return response()->json([
                'message'  => 'Activity created successfully',
                'activity' => $activity->load([
                    'items.item',
                    'items.item.programmingLanguages',
                    'items.itemType',
                    'programmingLanguages'
                ]),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error while creating the activity.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific activity by ID.
     */
    public function show($actID)
    {
        $activity = Activity::with([
            'classroom',
            'teacher',
            'programmingLanguages',
            'items.item.testCases',
        ])->find($actID);

        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        return response()->json($activity);
    }

    /**
     * Get all activities for a specific class, categorized into Upcoming, Ongoing, and Completed.
     */
    public function showClassActivities($classID)
    {
        $now = now();

        // Mark activities as completed if closeDate has passed.
        $updatedCount = Activity::where('classID', $classID)
            ->where('closeDate', '<', $now)
            ->whereNull('completed_at')
            ->update([
                'completed_at' => $now,
                'updated_at'   => $now,
            ]);

        \Log::info("Activities marked as completed: $updatedCount");

        // Upcoming Activities
        $upcomingActivities = Activity::with([
                'teacher',
                'programmingLanguages',
                'items.item',
                'items.item.programmingLanguages',
                'items.itemType'
            ])
            ->where('classID', $classID)
            ->where('openDate', '>', $now)
            ->orderBy('openDate', 'asc')
            ->get();

        // Ongoing Activities
        $ongoingActivities = Activity::with([
                'teacher',
                'programmingLanguages',
                'items.item',
                'items.item.programmingLanguages',
                'items.itemType'
            ])
            ->where('classID', $classID)
            ->where('openDate', '<=', $now)
            ->where('closeDate', '>=', $now)
            ->whereNull('completed_at')
            ->orderBy('openDate', 'asc')
            ->get();

        // Completed Activities
        $completedActivities = Activity::with([
                'teacher',
                'programmingLanguages',
                'items.item',
                'items.item.programmingLanguages',
                'items.itemType'
            ])
            ->where('classID', $classID)
            ->whereNotNull('completed_at')
            ->where('openDate', '<=', $now)
            ->where('closeDate', '<', $now)
            ->orderBy('closeDate', 'desc')
            ->get();

        \Log::info("Fetched Activities", [
            'classID'        => $classID,
            'upcomingCount'  => $upcomingActivities->count(),
            'ongoingCount'   => $ongoingActivities->count(),
            'completedCount' => $completedActivities->count(),
            'current_time'   => $now->toDateTimeString(),
        ]);

        // Now call attachScores on each set of activities before returning them
        $upcomingActivities  = $this->attachScores($upcomingActivities);
        $ongoingActivities   = $this->attachScores($ongoingActivities);
        $completedActivities = $this->attachScores($completedActivities);

        if ($upcomingActivities->isEmpty() && $ongoingActivities->isEmpty() && $completedActivities->isEmpty()) {
            return response()->json([
                'message' => 'No activities found.',
                'upcoming' => [],
                'ongoing' => [],
                'completed' => []
            ], 200);
        }

        return response()->json([
            'upcoming'  => $upcomingActivities,
            'ongoing'   => $ongoingActivities,
            'completed' => $completedActivities
        ]);
    }

        /**
     * Helper to attach classAvgScore and highestScore to each activity
     */
    private function attachScores($activities)
    {
        return $activities->map(function ($act) {
            // Fetch numeric averages
            $rawAvg = DB::table('activity_student')
                ->where('actID', $act->actID)
                ->avg('finalScore');
    
            $rawMax = DB::table('activity_student')
                ->where('actID', $act->actID)
                ->max('finalScore');
    
            // Format them using our helper
            $act->classAvgScore = $this->formatScore($rawAvg);
            $act->highestScore  = $this->formatScore($rawMax);
    
            return $act;
        });
    }

        /**
     * Format a numeric value with up to 2 decimal places, 
     * but if it's a whole number (e.g., 145.00), show just '145'.
     */
    private function formatScore($value)
    {
        // If it's null or invalid, return '-'
        if ($value === null) {
            return '-';
        }

        // Round to 2 decimals
        $rounded = round($value, 2);

        // If there's no decimal part, display as integer (e.g., '145')
        if (fmod($rounded, 1.0) === 0.0) {
            return (int) $rounded;
        }

        // Otherwise, format with 2 decimal places (e.g., '145.12')
        return number_format($rounded, 2);
    }

    /**
     * Update an existing activity (Only for Teachers)
     * Also, when updating the finalScorePolicy, recalc final results.
     */
    public function update(Request $request, $actID)
    {
        try {
            $teacher = Auth::user();

            if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $activity = Activity::where('actID', $actID)
                ->where('teacherID', $teacher->teacherID)
                ->first();

            if (!$activity) {
                return response()->json(['message' => 'Activity not found or unauthorized'], 404);
            }

            // Validate input
            $validator = \Validator::make($request->all(), [
                'progLangIDs'           => 'sometimes|required|array',
                'progLangIDs.*'         => 'exists:programming_languages,progLangID',
                'actTitle'              => 'sometimes|required|string|max:255',
                'actDesc'               => 'sometimes|required|string',
                'actDifficulty'         => 'sometimes|required|in:Beginner,Intermediate,Advanced',
                'actDuration'           => [
                    'sometimes',
                    'required',
                    'regex:/^(0\d|1\d|2[0-3]):([0-5]\d):([0-5]\d)$/'
                ],
                'actAttempts'           => 'sometimes|required|integer|min:0',
                'openDate'              => 'sometimes|required|date',
                'closeDate'             => 'sometimes|required|date|after:openDate',
                'maxPoints'             => 'sometimes|required|numeric|min:1',
                'items'                 => 'sometimes|required|array|min:1',
                'items.*.itemID'        => 'required_with:items|exists:items,itemID',
                'items.*.itemTypeID'    => 'required_with:items|exists:item_types,itemTypeID',
                'items.*.actItemPoints' => 'required_with:items|numeric|min:1',
                'finalScorePolicy'      => 'sometimes|required|in:last_attempt,highest_score',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // If a new closeDate is provided and is in the future, clear completed_at.
            if ($request->has('closeDate') && \Carbon\Carbon::parse($request->closeDate)->gt(now())) {
                $activity->completed_at = null;
                $activity->updated_at   = now();
                $activity->save();
            }

            $activity->update($request->only([
                'actTitle', 'actDesc', 'actDifficulty', 'actDuration',
                'actAttempts', 'openDate', 'closeDate', 'maxPoints'
            ]));

            if ($request->has('finalScorePolicy')) {
                $activity->finalScorePolicy = $request->finalScorePolicy;
                $activity->save();
            }

            if ($request->has('actDuration')) {
                list($hours, $minutes, $seconds) = explode(":", $request->actDuration);
                $newDurationInSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;

                $progresses = \App\Models\ActivityProgress::where('actID', $activity->actID)->get();
                foreach ($progresses as $progress) {
                    $elapsed = time() - strtotime($progress->updated_at);
                    $newTimeRemaining = $newDurationInSeconds - $elapsed;
                    if ($newTimeRemaining < 0) {
                        $newTimeRemaining = 0;
                    }
                    $progress->timeRemaining = $newTimeRemaining;
                    $progress->save();
                }
            }

            if ($request->has('progLangIDs')) {
                $activity->programmingLanguages()->sync($request->progLangIDs);
            }

            if ($request->has('items')) {
                ActivityItem::where('actID', $activity->actID)->delete();

                foreach ($request->items as $item) {
                    ActivityItem::create([
                        'actID'         => $activity->actID,
                        'itemID'        => $item['itemID'],
                        'itemTypeID'    => $item['itemTypeID'],
                        'actItemPoints' => $item['actItemPoints'],
                    ]);
                }

                $totalPoints = array_sum(array_column($request->items, 'actItemPoints'));
                $activity->update(['maxPoints' => $totalPoints]);

                \App\Models\ActivityProgress::where('actID', $activity->actID)
                    ->update(['draftTestCaseResults' => null, 'draftScore' => null]);
            }

            // Recalculate final results based on the current finalScorePolicy.
            $this->recalcFinalResults($activity->actID);

            return response()->json([
                'message'  => 'Activity updated successfully',
                'activity' => $activity->load([
                    'items.item',
                    'items.item.programmingLanguages',
                    'items.itemType',
                    'programmingLanguages'
                ]),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error while updating the activity.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an activity (Only for Teachers).
     */
    public function destroy($actID)
    {
        $teacher = Auth::user();
        \Log::info("Destroy activity request received", ['teacher_id' => $teacher ? $teacher->teacherID : null, 'actID' => $actID]);

        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            \Log::warning("Unauthorized delete attempt", ['actID' => $actID]);
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activity = Activity::where('actID', $actID)
            ->where('teacherID', $teacher->teacherID)
            ->first();

        if (!$activity) {
            \Log::warning("Activity not found or unauthorized", ['teacher_id' => $teacher->teacherID, 'actID' => $actID]);
            return response()->json(['message' => 'Activity not found or unauthorized'], 404);
        }

        try {
            $activity->delete();
            \Log::info("Activity deleted successfully", ['actID' => $actID]);
            return response()->json(['message' => 'Activity deleted successfully']);
        } catch (\Exception $e) {
            \Log::error("Error deleting activity", ['actID' => $actID, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error while deleting the activity.', 'error' => $e->getMessage()], 500);
        }
    }

    ///////////////////////////////////////////////////
    // FUNCTIONS FOR ACTIVITY MANAGEMENT PAGE FOR STUDENTS
    ///////////////////////////////////////////////////
    
    public function showActivityItemsByStudent(Request $request, $actID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activity = Activity::with([
            'items.item.testCases',
            'items.itemType',
            'classroom',
            'programmingLanguages',
        ])->find($actID);

        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        $submission = ActivitySubmission::where('actID', $activity->actID)
            ->where('studentID', $student->studentID)
            ->first();

        $items = $activity->items->map(function ($ai) use ($submission) {
            if (!$ai->item) {
                \Log::error("Item not found for activity item ID: {$ai->id}");
                return null;
            }

            $testCases = $ai->item->testCases->map(function ($tc) {
                return [
                    'testCaseID'     => $tc->testCaseID,
                    'inputData'      => $tc->inputData,
                    'expectedOutput' => $tc->expectedOutput,
                    'testCasePoints' => $tc->testCasePoints,
                    'isHidden'       => $tc->isHidden,
                ];
            });

            return [
                'itemID'         => $ai->item->itemID,
                'itemName'       => $ai->item->itemName ?? 'Unknown',
                'itemDesc'       => $ai->item->itemDesc ?? '',
                'itemDifficulty' => $ai->item->itemDifficulty ?? 'N/A',
                'itemType'       => $ai->itemType->itemTypeName ?? 'N/A',
                'actItemPoints'  => $ai->actItemPoints,
                'testCaseTotalPoints' => $ai->item->testCases->sum('testCasePoints'),
                'testCases'      => $testCases,
                'studentScore'   => $submission ? $submission->score : null,
                'studentTimeSpent' => $submission && $submission->itemTimeSpent !== null
                    ? $this->formatSecondsToHMS($submission->itemTimeSpent)
                    : '-',
                'submissionStatus' => $submission ? 'Submitted' : 'Not Attempted',
            ];
        })->filter();

        return response()->json([
            'activityName'     => $activity->actTitle,
            'actDesc'          => $activity->actDesc,
            'maxPoints'        => $activity->maxPoints,
            'actDuration'      => $activity->actDuration,
            'closeDate'        => $activity->closeDate,
            'actAttempts'      => $activity->actAttempts,
            'attemptsTaken'    => ActivitySubmission::where('actID', $activity->actID)
                                   ->where('studentID', $student->studentID)
                                   ->count(),
            'allowedLanguages' => $activity->programmingLanguages->map(function ($lang) {
                return [
                    'progLangID'        => $lang->progLangID,
                    'progLangName'      => $lang->progLangName,
                    'progLangExtension' => $lang->progLangExtension,
                ];
            })->values(),
            'items'            => $items,
        ]);
    }

    public function showActivityLeaderboardByStudent($actID)
    {
        $activity = Activity::with('classroom')->find($actID);

        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        $submissions = DB::table('activity_student as astu')
        ->join('students as s', 'astu.studentID', '=', 's.studentID')
        ->select(
            's.studentID',
            's.firstname',
            's.lastname',
            's.program',
            's.student_num',
            's.profileImage',
            'astu.finalScore',
            'astu.finalTimeSpent'
        )
        ->where('astu.actID', $actID)
        // Sort by finalScore desc, finalTimeSpent asc, etc.
        ->orderByDesc('astu.finalScore')
        ->orderBy('astu.finalTimeSpent')
        ->orderBy('s.lastname')
        ->orderBy('s.firstname')
        ->get();

        if ($submissions->isEmpty()) {
            return response()->json([
                'activityName' => $activity->actTitle,
                'message'      => 'No students have submitted this activity yet.',
                'leaderboard'  => []
            ]);
        }

        $rankedSubmissions = $submissions->map(function ($submission, $index) {
            $profileImage = $submission->profileImage 
                ? asset('storage/' . $submission->profileImage) 
                : null;

            return [
                'studentName'  => strtoupper($submission->lastname) . ", " . $submission->firstname,
                'studentNum'   => $submission->student_num,
                'program'      => $submission->program ?? 'N/A',
                'score'        => $submission->finalScore,
                'itemTimeSpent'    => $submission->finalTimeSpent,
                'rank'         => ($index + 1),
                'profileImage' => $profileImage
            ];
        });

        return response()->json([
            'activityName' => $activity->actTitle,
            'leaderboard'  => $rankedSubmissions
        ]);
    }

    public function showActivityItemsByTeacher($actID)
    {
        $activity = Activity::with([
            'items.item.testCases',
            'items.item.programmingLanguages',
            'items.itemType',
            'classroom',
            'programmingLanguages',
        ])->find($actID);
    
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
    
        $items = $activity->items->map(function ($ai) {
            $item = $ai->item;
            if (!$item) {
                \Log::error("Item not found for activity item ID: {$ai->id}");
                return null;
            }
    
            // Return testCaseID so the front-end can match results
            $testCases = $item->testCases->map(function ($tc) {
                return [
                    'testCaseID'     => $tc->testCaseID, // <-- add this line
                    'inputData'      => $tc->inputData,
                    'expectedOutput' => $tc->expectedOutput,
                    'testCasePoints' => $tc->testCasePoints,
                    'isHidden'       => $tc->isHidden,
                ];
            });
    
            return [
                'itemID'       => $item->itemID,
                'itemName'     => $item->itemName ?? 'Unknown',
                'itemDesc'     => $item->itemDesc ?? '',
                'itemType'     => $ai->itemType->itemTypeName ?? 'N/A',
                'testCases'    => $testCases,
                'testCaseTotalPoints' => $item->testCases->sum('testCasePoints'),
                'actItemPoints'=> $ai->actItemPoints,
                // etc. any other fields
            ];
        })->filter();
    
        return response()->json([
            'activityName'     => $activity->actTitle,
            'actDesc'          => $activity->actDesc,
            'maxPoints'        => $activity->maxPoints,
            'actDuration'      => $activity->actDuration,
            'finalScorePolicy' => $activity->finalScorePolicy,
            'items'            => $items->values(), // ensure it's an array
        ]);
    }
    

    /**
     * Calculate the average student score for an activity item.
     * Only include the submission that corresponds to each student’s final attempt.
     */
    private function calculateAverageScore($itemID, $actID)
    {
        $activity = Activity::find($actID);
        if (!$activity) {
            return '-';
        }
    
        if ($activity->finalScorePolicy === 'highest_score') {
            // For each student, get the highest score for the given item.
            $bestScores = \DB::table('activity_submissions as s')
                ->select('s.studentID', \DB::raw('MAX(s.score) as bestScore'))
                ->where('s.actID', $actID)
                ->where('s.itemID', $itemID)
                ->groupBy('s.studentID')
                ->get();
    
            $avg = $bestScores->avg('bestScore');
        } else {
            // For last_attempt, use the submission where attemptNo equals the pivot's attemptsTaken.
            $avg = \DB::table('activity_submissions as s')
                ->join('activity_student as a', function ($join) use ($actID) {
                    $join->on('s.studentID', '=', 'a.studentID')
                         ->where('a.actID', '=', $actID);
                })
                ->where('s.actID', $actID)
                ->where('s.itemID', $itemID)
                ->whereColumn('s.attemptNo', 'a.attemptsTaken')
                ->avg('s.score');
        }
    
        return $avg !== null ? number_format(round($avg, 2), 2) : '-';
    }

    /**
     * Calculate the average time spent by students on an activity item.
     * Only include the submission corresponding to the final attempt.
     */
    private function calculateAverageTimeSpent($itemID, $actID)
    {
        $activity = Activity::find($actID);
        if (!$activity) {
            return '-';
        }
        
        if ($activity->finalScorePolicy === 'highest_score') {
            // Get each student's best score for the item.
            $bestScores = \DB::table('activity_submissions as s')
                ->select('s.studentID', \DB::raw('MAX(s.score) as bestScore'))
                ->where('s.actID', $actID)
                ->where('s.itemID', $itemID)
                ->groupBy('s.studentID')
                ->get();
        
            // For each student, get the corresponding itemTimeSpent from one row that has that best score.
            $timeSpentValues = [];
            foreach ($bestScores as $bs) {
                $row = \DB::table('activity_submissions as s')
                    ->select('s.itemTimeSpent')
                    ->where('s.actID', $actID)
                    ->where('s.itemID', $itemID)
                    ->where('s.studentID', $bs->studentID)
                    ->where('s.score', $bs->bestScore)
                    ->first();
                if ($row && isset($row->itemTimeSpent)) {
                    $timeSpentValues[] = $row->itemTimeSpent;
                }
            }
        
            $avgSeconds = !empty($timeSpentValues) ? array_sum($timeSpentValues) / count($timeSpentValues) : null;
        } else {
            // For last_attempt policy.
            $avgSeconds = \DB::table('activity_submissions as s')
                ->join('activity_student as a', function ($join) use ($actID) {
                    $join->on('s.studentID', '=', 'a.studentID')
                         ->where('a.actID', '=', $actID);
                })
                ->where('s.actID', $actID)
                ->where('s.itemID', $itemID)
                ->whereColumn('s.attemptNo', 'a.attemptsTaken')
                ->avg('s.itemTimeSpent');
        }
        
        return $avgSeconds !== null ? $this->formatSecondsToHMS(round($avgSeconds)) : '-';
    }

    public function showActivityLeaderboardByTeacher($actID)
    {
        // Fetch the activity.
        $activity = Activity::with('classroom')->find($actID);
    
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
    
        // Read from the pivot table "activity_student" and join with the "students" table.
        $submissions = \DB::table('activity_student as astu')
            ->join('students as s', 'astu.studentID', '=', 's.studentID')
            ->select(
                's.studentID',
                's.firstname',
                's.lastname',
                's.program',
                's.student_num',
                's.profileImage',
                'astu.finalScore',
                'astu.finalTimeSpent'
            )
            ->where('astu.actID', $actID)
            // Sort by finalScore DESC, then finalTimeSpent ASC, then by lastname and firstname
            ->orderByDesc('astu.finalScore')
            ->orderBy('astu.finalTimeSpent')
            ->orderBy('s.lastname')
            ->orderBy('s.firstname')
            ->get();
    
        // If no pivot records exist, return an empty leaderboard.
        if ($submissions->isEmpty()) {
            return response()->json([
                'activityName' => $activity->actTitle,
                'message'      => 'No students have submitted this activity yet.',
                'leaderboard'  => []
            ]);
        }
    
        // Build the leaderboard array.
        $rankedSubmissions = $submissions->map(function ($submission, $index) {
            // Convert relative profile image path to full URL
            $profileImage = $submission->profileImage 
                ? asset('storage/' . $submission->profileImage) 
                : null;
    
            return [
                'studentName'  => strtoupper($submission->lastname) . ", " . $submission->firstname,
                'studentNum'   => $submission->student_num,
                'program'      => $submission->program ?? 'N/A',
                'score'        => $submission->finalScore,
                'timeSpent'    => $submission->finalTimeSpent,
                'rank'         => ($index + 1),
                'profileImage' => $profileImage
            ];
        });
    
        return response()->json([
            'activityName' => $activity->actTitle,
            'leaderboard'  => $rankedSubmissions
        ]);
    }

    /**
     * Show the activity settings.
     */
    public function showActivitySettingsByTeacher($actID)
    {
        $activity = Activity::with('classroom')->find($actID);

        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        return response()->json([
            'activityName' => $activity->actTitle,
            'maxPoints'    => $activity->maxPoints,
            'className'    => optional($activity->classroom)->className ?? 'N/A',
            'openDate'     => $activity->openDate,
            'closeDate'    => $activity->closeDate,
            'settings'     => [
                'examMode'         => (bool)$activity->examMode,
                'randomizedItems'  => (bool)$activity->randomizedItems,
                'disableReviewing' => (bool)$activity->disableReviewing,
                'hideLeaderboard'  => (bool)$activity->hideLeaderboard,
                'delayGrading'     => (bool)$activity->delayGrading,
            ]
        ]);
    }

    /**
     * Update activity settings.
     */
    public function updateActivitySettingsByTeacher(Request $request, $actID)
    {
        $teacher = Auth::user();

        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activity = Activity::where('actID', $actID)
            ->where('teacherID', $teacher->teacherID)
            ->first();

        if (!$activity) {
            return response()->json(['message' => 'Activity not found or unauthorized'], 404);
        }

        $validatedData = $request->validate([
            'examMode'         => 'boolean',
            'randomizedItems'  => 'boolean',
            'disableReviewing' => 'boolean',
            'hideLeaderboard'  => 'boolean',
            'delayGrading'     => 'boolean',
        ]);

        $activity->update($validatedData);

        return response()->json(['message' => 'Activity settings updated successfully.']);
    }

}