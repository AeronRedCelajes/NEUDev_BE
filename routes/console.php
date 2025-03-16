<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Scheduling\Schedule;
use App\Models\Activity;
use App\Models\Student;
use App\Models\ActivityNotification;
use App\Events\DeadlineReminder;
use App\Events\ActivityStarted;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This is where you may define all of your Closure-based console commands.
| In Laravel 11, scheduling also goes here. The system cron or Task Scheduler
| calls "php artisan schedule:run" once per minute, and Laravel runs any
| commands that are "due" at that time.
|
*/

/**
 * Basic example command.
 */
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Deadline Reminders command
 * 
 * This command checks for activities that are exactly 1 hour or 1 day away from closing
 * and sends students a "DeadlineReminder" event.
 */
Artisan::command('reminders:deadline', function () {
    $now = now();
    \Log::info("reminders:deadline called at {$now}");

    // ===================
    // 1. Send 1-day reminders
    // ===================
    $oneDayActivities = Activity::whereBetween('closeDate', [
        $now->copy()->addDay()->startOfMinute(),
        $now->copy()->addDay()->endOfMinute()
    ])->get();

    foreach ($oneDayActivities as $activity) {
        $studentIDs = DB::table('class_student')
            ->where('classID', $activity->classID)
            ->pluck('studentID');

        foreach ($studentIDs as $studentID) {
            $student = Student::find($studentID);
            if ($student) {
                event(new DeadlineReminder($activity, $student, ' (1 Day Left)'));
            }
        }
    }

    // ===================
    // 2. Send 1-hour reminders
    // ===================
    $oneHourActivities = Activity::whereBetween('closeDate', [
        $now->copy()->addHour()->startOfMinute(),
        $now->copy()->addHour()->endOfMinute()
    ])->get();

    foreach ($oneHourActivities as $activity) {
        $studentIDs = DB::table('class_student')
            ->where('classID', $activity->classID)
            ->pluck('studentID');

        foreach ($studentIDs as $studentID) {
            $student = Student::find($studentID);
            if ($student) {
                event(new DeadlineReminder($activity, $student, ' (1 Hour Left)'));
            }
        }
    }

    $this->info('Deadline reminders dispatched successfully.');
})->purpose('Send deadline reminders for activities');

/**
 * Activity Started command
 * 
 * This command checks for activities that just became "ongoing" (openDate <= now and closeDate >= now),
 * and dispatches ActivityStarted for each student if there's no existing "Activity Started" notification 
 * for that specific activity. This ensures a one-time "Activity Started" notice is sent automatically.
 */
Artisan::command('activities:started', function () {
    $now = now();
    \Log::info("activities:started called at {$now}");

    // Get activities that just started
    $ongoingActivities = Activity::where('openDate', '<=', $now)
        ->where('closeDate', '>=', $now)
        ->get();

    foreach ($ongoingActivities as $activity) {
        // Get all students enrolled in the class
        $studentIDs = DB::table('class_student')
            ->where('classID', $activity->classID)
            ->pluck('studentID');

        foreach ($studentIDs as $studentID) {
            // Check if notification was already sent
            $alreadyNotified = ActivityNotification::where('studentID', $studentID)
                ->where('actID', $activity->actID)
                ->exists();

            if (!$alreadyNotified) {
                $student = Student::find($studentID);
                if ($student) {
                    event(new ActivityStarted($activity, $student));

                    // Store the notification to prevent duplicates
                    ActivityNotification::create([
                        'studentID' => $studentID,
                        'actID' => $activity->actID,
                    ]);
                }
            }
        }
    }

    $this->info('ActivityStarted events dispatched for newly started activities.');
})->purpose('Notify students automatically when an activity starts');

/**
 * SCHEDULING
 * 
 * By default, "php artisan schedule:run" is invoked by your system cron or Task Scheduler 
 * every minute. Then Laravel checks which tasks are "due" and runs them accordingly.
 */
$schedule = app(Schedule::class);

// 1) Deadline reminders - e.g. run once an hour, or every minute if you like:
$schedule->command('reminders:deadline')->everyMinute(); // or ->everyMinute(), etc.

// 2) Activity started detection - e.g. run every minute for near real-time
$schedule->command('activities:started')->everyMinute();