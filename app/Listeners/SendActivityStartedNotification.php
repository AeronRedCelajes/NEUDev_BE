<?php

namespace App\Listeners;

use App\Events\ActivityStarted;

class SendActivityStartedNotification
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\ActivityStarted  $event
     * @return void
     */
    public function handle(ActivityStarted $event)
    {
        $activity = $event->activity;
        $student = $event->notifiable; // Student model

        $student->notifications()->create([
            'type' => 'Activity Started',
            'data' => json_encode([
                'activity_id' => $activity->actID,    // adapt to your table's PK
                'message'     => 'Your activity "' . $activity->actTitle . '" has started!',
            ]),
        ]);
    }
}