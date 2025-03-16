<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Scheduling\Schedule;
use App\Models\Activity;
use App\Models\Student;
use App\Events\DeadlineReminder;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Define the deadline reminders command.
Artisan::command('reminders:deadline', function () {
    $now = now();
    \Log::info("reminders:deadline called at {$now}");

    // Send 1-day deadline reminders.
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
                event(new DeadlineReminder($activity, $student, '1_day'));
            }
        }
    }

    // Send 1-hour deadline reminders.
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
                event(new DeadlineReminder($activity, $student, '1_hour'));
            }
        }
    }

    $this->info('Deadline reminders dispatched successfully.');
})->purpose('Send deadline reminders for activities');

// Schedule the command to run hourly.
$schedule = app(Schedule::class);
$schedule->command('reminders:deadline')->hourly();