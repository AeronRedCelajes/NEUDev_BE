<?php

namespace App\Listeners;

use App\Events\DeadlineReminder;

class SendDeadlineReminderNotification
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\DeadlineReminder  $event
     * @return void
     */
    public function handle(DeadlineReminder $event)
    {
        $activity = $event->activity;
        $student = $event->notifiable; 
        $timeLeft = $event->timeLeft;  // '1_day' or '1_hour'

        $student->notifications()->create([
            'type' => 'Deadline Reminder' . $timeLeft,
            'data' => json_encode([
                'activity_id' => $activity->actID,
                'message'     => "You have {$timeLeft} left for activity \"{$activity->actTitle}\".",
            ]),
        ]);
    }
}