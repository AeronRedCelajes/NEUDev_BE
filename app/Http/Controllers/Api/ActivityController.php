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
            // Student's submission for this activity (if any).
            $submission = ActivitySubmission::where('actID', $activity->actID)
                ->where('studentID', $student->studentID)
                ->first();
    
            // Build a ranking array for all submissions in this activity using the updated criteria.
            $orderedSubmissions = ActivitySubmission::where('actID', $activity->actID)
                ->orderByDesc('score')
                ->orderBy('timeSpent')
                ->orderBy(DB::raw("CONCAT(LOWER(students.lastname), ' ', LOWER(students.firstname))"))
                ->join('students', 'activity_submissions.studentID', '=', 'students.studentID')
                ->select('activity_submissions.studentID')
                ->get()
                ->pluck('studentID')
                ->toArray();
    
            $rankIndex = array_search($student->studentID, $orderedSubmissions);
            $rank = $rankIndex !== false ? $rankIndex + 1 : null;
    
            // Calculate the student's overall score and percentage.
            $score = $submission ? $submission->score : 0;
            $maxPoints = $activity->maxPoints ?: 1; // avoid division by zero
    
            $scorePercentage = ($submission && $activity->maxPoints > 0)
                ? round(($score / $activity->maxPoints) * 100, 2)
                : null;
    
            // Format timeSpent (assumed stored in seconds) into HH:MM:SS.
            $formattedTimeSpent = ($submission && $submission->timeSpent !== null)
                ? $this->formatSecondsToHMS($submission->timeSpent)
                : '-';
    
            // Retrieve attemptsTaken from the pivot table activity_student.
            $attemptsTaken = DB::table('activity_student')
                ->where('actID', $activity->actID)
                ->where('studentID', $student->studentID)
                ->value('attemptsTaken');
    
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
                // Student-specific fields.
                'rank'               => $rank,
                'overallScore'       => $score,
                'maxPoints'          => $activity->maxPoints,
                'finalScorePolicy' => $activity->finalScorePolicy,
                'scorePercentage'    => $scorePercentage,
                'attemptsTaken'      => $attemptsTaken ? $attemptsTaken : 0,
                'studentTimeSpent'   => $formattedTimeSpent,
            ];
        });
    }
    

    /**
     * Helper: Format seconds into HH:MM:SS.
     */
    private function formatSecondsToHMS($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
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
                'maxPoints'             => 'required|integer|min:1',
                'items'                 => 'required|array|min:1',
                'items.*.itemID'        => 'required|exists:items,itemID',
                'items.*.itemTypeID'    => 'required|exists:item_types,itemTypeID',
                'items.*.actItemPoints' => 'required|integer|min:1',
                // New field: must be one of the allowed values.
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
                'maxPoints'        => $request->maxPoints, // temporary; will recalc
                'actAttempts'      => $request->actAttempts,
                'classAvgScore'    => null,
                'highestScore'     => null,
                // Save the final score policy as provided.
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
     * Update an existing activity (Only for Teachers)
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
                'maxPoints'             => 'sometimes|required|integer|min:1',
                'items'                 => 'sometimes|required|array|min:1',
                'items.*.itemID'        => 'required_with:items|exists:items,itemID',
                'items.*.itemTypeID'    => 'required_with:items|exists:item_types,itemTypeID',
                'items.*.actItemPoints' => 'required_with:items|integer|min:1',
                // Optional finalScorePolicy field (if provided, must be valid)
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
    
            // Capture the current actDuration before updating it.
            $oldDuration = $activity->actDuration;
    
            // Update activity details.
            $activity->update($request->only([
                'actTitle', 'actDesc', 'actDifficulty', 'actDuration',
                'actAttempts', 'openDate', 'closeDate', 'maxPoints'
            ]));
    
            // Update finalScorePolicy if provided.
            if ($request->has('finalScorePolicy')) {
                $activity->finalScorePolicy = $request->finalScorePolicy;
                $activity->save();
            }
    
            // If actDuration is updated, recalculate the remaining time for all progress records.
            if ($request->has('actDuration')) {
                // Convert the new actDuration (HH:MM:SS) to seconds.
                list($hours, $minutes, $seconds) = explode(":", $request->actDuration);
                $newDurationInSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;
    
                // Update all progress records for this activity.
                $progresses = \App\Models\ActivityProgress::where('actID', $activity->actID)->get();
                foreach ($progresses as $progress) {
                    // Calculate elapsed seconds since the last progress update.
                    $elapsed = time() - strtotime($progress->updated_at);
                    $newTimeRemaining = $newDurationInSeconds - $elapsed;
                    if ($newTimeRemaining < 0) {
                        $newTimeRemaining = 0;
                    }
                    $progress->timeRemaining = $newTimeRemaining;
                    $progress->save();
                }
            }
    
            // Sync programming languages if provided.
            if ($request->has('progLangIDs')) {
                $activity->programmingLanguages()->sync($request->progLangIDs);
            }
    
            // Update items if provided.
            if ($request->has('items')) {
                // Delete existing ActivityItem records.
                ActivityItem::where('actID', $activity->actID)->delete();
    
                // Create new ActivityItem records.
                foreach ($request->items as $item) {
                    ActivityItem::create([
                        'actID'         => $activity->actID,
                        'itemID'        => $item['itemID'],
                        'itemTypeID'    => $item['itemTypeID'],
                        'actItemPoints' => $item['actItemPoints'],
                    ]);
                }
    
                // Recalculate total points.
                $totalPoints = array_sum(array_column($request->items, 'actItemPoints'));
                $activity->update(['maxPoints' => $totalPoints]);
    
                // Clear the test case results and draft score in the progress records
                // so that any previous scores are removed if items/test cases have changed.
                \App\Models\ActivityProgress::where('actID', $activity->actID)
                ->update(['draftTestCaseResults' => null, 'draftScore' => null]);
            }
    
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

        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activity = Activity::where('actID', $actID)
            ->where('teacherID', $teacher->teacherID)
            ->first();

        if (!$activity) {
            return response()->json(['message' => 'Activity not found or unauthorized'], 404);
        }

        $activity->delete();

        return response()->json(['message' => 'Activity deleted successfully']);
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

        // Load the activity with related items, test cases, item types, classroom, and allowed programming languages.
        $activity = Activity::with([
            'items.item.testCases',
            'items.itemType',
            'classroom',
            'programmingLanguages',
        ])->find($actID);

        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        // Retrieve the unified submission record for this student and activity.
        $submission = ActivitySubmission::where('actID', $activity->actID)
            ->where('studentID', $student->studentID)
            ->first();

        // Map each activity item. (Test case details are still available from the items.)
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
                'actItemPoints' => $ai->item->testCases->sum('testCasePoints'),
                'testCaseTotalPoints' => $ai->item->testCases->sum('testCasePoints'),
                'testCases'      => $testCases,
                // Since we have a unified submission record:
                'studentScore'   => $submission ? $submission->score : null,
                'studentTimeSpent' => $submission && $submission->timeSpent !== null
                    ? $this->formatSecondsToHMS($submission->timeSpent)
                    : '-',
                'submissionStatus' => $submission ? 'Submitted' : 'Not Attempted',
            ];
        })->filter(); // Remove any null values

        return response()->json([
            'activityName'      => $activity->actTitle,
            'actDesc'           => $activity->actDesc,
            'maxPoints'         => $activity->maxPoints,
            'actDuration'       => $activity->actDuration,
            'closeDate'         => $activity->closeDate,
            'actAttempts'       => $activity->actAttempts,
            'attemptsTaken'     => ActivitySubmission::where('actID', $activity->actID)
                                   ->where('studentID', $student->studentID)
                                   ->count(),
            'allowedLanguages'  => $activity->programmingLanguages->map(function ($lang) {
                return [
                    'progLangID'        => $lang->progLangID,
                    'progLangName'      => $lang->progLangName,
                    'progLangExtension' => $lang->progLangExtension,
                ];
            })->values(),
            'items'             => $items,
        ]);
    }

    public function showActivityLeaderboardByStudent($actID)
    {
        // Fetch the activity.
        $activity = Activity::with('classroom')->find($actID);
    
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
    
        // Get submissions joined with students. Include timeSpent for tie-breaker.
        $submissions = ActivitySubmission::where('actID', $actID)
            ->join('students', 'activity_submissions.studentID', '=', 'students.studentID')
            ->select(
                'students.studentID',
                'students.firstname',
                'students.lastname',
                'students.program',
                'activity_submissions.score',
                'activity_submissions.timeSpent'
            )
            ->orderByDesc('activity_submissions.score')
            ->orderBy('activity_submissions.timeSpent')
            ->orderBy('students.lastname')
            ->orderBy('students.firstname')
            ->get();
    
        if ($submissions->isEmpty()) {
            return response()->json([
                'activityName' => $activity->actTitle,
                'message'      => 'No students have submitted this activity yet.',
                'leaderboard'  => []
            ]);
        }
    
        // Calculate rank based on the ordering.
        $rankedSubmissions = $submissions->map(function ($submission, $index) {
            return [
                'studentName'  => strtoupper($submission->lastname) . ", " . $submission->firstname,
                'program'      => $submission->program ?? 'N/A',
                'averageScore' => $submission->score . '%',
                'timeSpent'    => $submission->timeSpent,
                'rank'         => ($index + 1)
            ];
        });
    
        return response()->json([
            'activityName' => $activity->actTitle,
            'leaderboard'  => $rankedSubmissions
        ]);
    }
    

    ///////////////////////////////////////////////////
    // FUNCTIONS FOR ACTIVITY MANAGEMENT PAGE FOR TEACHERS
    ///////////////////////////////////////////////////

    public function showActivityItemsByTeacher($actID)
    {
        $activity = Activity::with([
            'items.item.programmingLanguages',
            'items.itemType',
            'classroom',
            'programmingLanguages',
        ])->find($actID);

        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        $items = $activity->items->map(function ($ai) use ($activity) {
            $item = $ai->item;

            $programmingLanguages = $item->programmingLanguages->map(function ($lang) {
                return [
                    'progLangID'   => $lang->progLangID,
                    'progLangName' => $lang->progLangName,
                ];
            })->values()->all();

            return [
                'itemID'         => $item->itemID,
                'itemName'       => $item->itemName ?? 'Unknown',
                'itemDesc'       => $item->itemDesc ?? '',
                'itemDifficulty' => $item->itemDifficulty ?? 'N/A',
                'programming_languages' => $programmingLanguages,
                'itemType'       => $ai->itemType->itemTypeName ?? 'N/A',
                'testCases'      => $item->testCases->map(function ($tc) {
                    return [
                        'inputData'      => $tc->inputData,
                        'expectedOutput' => $tc->expectedOutput,
                        'testCasePoints' => $tc->testCasePoints,
                        'isHidden'       => $tc->isHidden,
                    ];
                }),
                'avgStudentScore'     => $this->calculateAverageScore($item->itemID, $activity->actID),
                'avgStudentTimeSpent' => $this->calculateAverageTimeSpent($item->itemID, $activity->actID),
                'actItemPoints'       => $ai->actItemPoints,
            ];
        });

        return response()->json([
            'activityName' => $activity->actTitle,
            'actDesc'      => $activity->actDesc,
            'maxPoints'    => $activity->maxPoints,
            'actDuration'  => $activity->actDuration,
            'allowedLanguages' => $activity->programmingLanguages->map(function ($lang) {
                return [
                    'progLangID'        => $lang->progLangID,
                    'progLangName'      => $lang->progLangName,
                    'progLangExtension' => $lang->progLangExtension,
                ];
            })->values(),
            'items'        => $items
        ]);
    }

    /**
     * Calculate the average student score for an activity item.
     */
    private function calculateAverageScore($itemID, $actID)
    {
        return ActivitySubmission::where('actID', $actID)
            ->where('itemID', $itemID)
            ->avg('score') ?? '-';
    }

    /**
     * Calculate the average time spent by students on an activity item.
     * Assumes timeSpent is stored as an integer (seconds), so we format the average to HH:MM:SS.
     */
    private function calculateAverageTimeSpent($itemID, $actID)
    {
        $avgSeconds = ActivitySubmission::where('actID', $actID)
            ->where('itemID', $itemID)
            ->avg('timeSpent');

        return $avgSeconds !== null ? $this->formatSecondsToHMS(round($avgSeconds)) : '-';
    }

    public function showActivityLeaderboardByTeacher($actID)
    {
        // Fetch the activity.
        $activity = Activity::with('classroom')->find($actID);
    
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
    
        // Get submissions joined with students. Note we select timeSpent and student_num.
        $submissions = ActivitySubmission::where('actID', $actID)
            ->join('students', 'activity_submissions.studentID', '=', 'students.studentID')
            ->select(
                'students.studentID',
                'students.firstname',
                'students.lastname',
                'students.student_num',
                'students.program',
                'activity_submissions.score',
                'activity_submissions.timeSpent'
            )
            // Order by score descending, then timeSpent ascending, then by lastname and firstname alphabetically.
            ->orderByDesc('activity_submissions.score')
            ->orderBy('activity_submissions.timeSpent')
            ->orderBy('students.lastname')
            ->orderBy('students.firstname')
            ->get();
    
        if ($submissions->isEmpty()) {
            return response()->json([
                'activityName' => $activity->actTitle,
                'message'      => 'No students have submitted this activity yet.',
                'leaderboard'  => []
            ]);
        }
    
        // Create leaderboard with rank based on the sorted order.
        $rankedSubmissions = $submissions->map(function ($submission, $index) {
            return [
                'studentName'  => strtoupper($submission->lastname) . ", " . $submission->firstname,
                'studentNum'   => $submission->student_num,  // included student number
                'program'      => $submission->program ?? 'N/A',
                'averageScore' => $submission->score . '%',
                'timeSpent'    => $submission->timeSpent,   // include timeSpent for transparency
                'rank'         => ($index + 1)
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

    ///////////////////////////////////////////////////
    // NEW FUNCTION: Overall Student Score Across Activities
    ///////////////////////////////////////////////////
    public function getStudentOverallScore($classID)
    {
        $student = Auth::user();
        if (!$student || !$student instanceof \App\Models\Student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get all activities in the given class.
        $activities = Activity::where('classID', $classID)->get();

        $totalScore = 0;
        $totalMaxPoints = 0;

        foreach ($activities as $activity) {
            // Assume one final submission per activity per student.
            $submission = ActivitySubmission::where('actID', $activity->actID)
                ->where('studentID', $student->studentID)
                ->orderBy('submitted_at', 'desc')
                ->first();
            if ($submission) {
                $totalScore += $submission->score;
            }
            $totalMaxPoints += $activity->maxPoints;
        }

        $averagePercentage = $totalMaxPoints > 0
            ? round(($totalScore / $totalMaxPoints) * 100, 2)
            : 0;

        return response()->json([
            'totalScore'         => $totalScore,
            'totalMaxPoints'     => $totalMaxPoints,
            'averagePercentage'  => $averagePercentage,
        ]);
    }

    ///////////////////////////////////////////////////
    // ACTIVITY SUBMISSION FUNCTIONS
    ///////////////////////////////////////////////////
    public function showActivityItemsForReview(Request $request, $actID, $studentID, $submissionID = null)
    {
        // Ensure the user is an authenticated teacher.
        $teacher = Auth::user();
        if (!$teacher || !$teacher instanceof \App\Models\Teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Load the activity with related items, test cases, item types, classroom, and allowed programming languages.
        $activity = Activity::with([
            'items.item.testCases',
            'items.itemType',
            'classroom',
            'programmingLanguages',
        ])->find($actID);

        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }

        // Retrieve the submission record.
        // If submissionID is provided, use it. Otherwise, get the latest submission.
        if ($submissionID) {
            $submission = ActivitySubmission::where('submissionID', $submissionID)
                ->where('actID', $actID)
                ->where('studentID', $studentID)
                ->first();
        } else {
            $submission = ActivitySubmission::where('actID', $actID)
                ->where('studentID', $studentID)
                ->latest('submitted_at')
                ->first();
        }

        // Use shared logic to build the assessment data.
        $assessmentData = $this->buildAssessmentData($activity, $submission);

        return response()->json([
            'message'      => 'Submission details retrieved successfully.',
            'activityName' => $activity->actTitle,
            'assessment'   => $assessmentData,
        ], 200);
    }

    /**
     * Shared function that builds the assessment data.
     * This is similar to the logic in showActivityItemsByStudent.
     */
    private function buildAssessmentData($activity, $submission)
    {
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
                'itemID'              => $ai->item->itemID,
                'itemName'            => $ai->item->itemName ?? 'Unknown',
                'itemDesc'            => $ai->item->itemDesc ?? '',
                'itemDifficulty'      => $ai->item->itemDifficulty ?? 'N/A',
                'itemType'            => $ai->itemType->itemTypeName ?? 'N/A',
                'actItemPoints'       => $ai->actItemPoints,
                'testCaseTotalPoints' => $ai->item->testCases->sum('testCasePoints'),
                'testCases'           => $testCases,
                // Display the submission details if available.
                'studentScore'        => $submission ? $submission->score : null,
                'studentTimeSpent'    => $submission && $submission->timeSpent !== null
                    ? $this->formatSecondsToHMS($submission->timeSpent)
                    : '-',
                'submissionStatus'    => $submission ? 'Submitted' : 'Not Attempted',
            ];
        })->filter(); // Remove any null values

        return [
            'actDesc'          => $activity->actDesc,
            'maxPoints'        => $activity->maxPoints,
            'actDuration'      => $activity->actDuration,
            'actAttempts'      => $activity->actAttempts,
            'attemptsTaken'    => ActivitySubmission::where('actID', $activity->actID)
                                    ->where('studentID', $submission ? $submission->studentID : null)
                                    ->count(),
            'allowedLanguages' => $activity->programmingLanguages->map(function ($lang) {
                return [
                    'progLangID'        => $lang->progLangID,
                    'progLangName'      => $lang->progLangName,
                    'progLangExtension' => $lang->progLangExtension,
                ];
            })->values(),
            'items'            => $items,
        ];
    }

}