<?php

namespace App\Listeners;

use App\Events\ActivityStarted;
use App\Models\Classroom;

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

        // Retrieve the classroom by classID
        $classroom = Classroom::find($activity->classID);
        $className = $classroom ? $classroom->className : 'Unknown Class';

        $student->notifications()->create([
            'type' => 'Activity Started',
            'data' => json_encode([
                'activity_id' => $activity->actID,    // adapt to your table's PK
                'message'     => "Your activity \"{$activity->actTitle}\" in \"{$className}\" has started!",
            ]),
        ]);
    }
}