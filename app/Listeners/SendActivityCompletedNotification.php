<?php

namespace App\Listeners;

use App\Events\ActivityCompleted;

class SendActivityCompletedNotification
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\ActivityCompleted  $event
     * @return void
     */
    public function handle(ActivityCompleted $event)
    {
        $activity = $event->activity;
        $teacher = $event->notifiable; // Teacher model

        $teacher->notifications()->create([
            'type' => 'Activity Completed',
            'data' => json_encode([
                'activity_id' => $activity->actID,
                'message'     => "Activity \"{$activity->actTitle}\" has been completed.",
            ]),
        ]);
    }
}