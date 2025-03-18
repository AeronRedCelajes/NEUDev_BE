<?php

namespace App\Listeners;

use App\Events\DeadlineChanged;
use App\Models\Classroom;

class SendDeadlineChangedNotification
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\DeadlineChanged  $event
     * @return void
     */
    public function handle(DeadlineChanged $event)
    {
        $activity = $event->activity;
        $student = $event->notifiable;

        // Retrieve the classroom by classID
        $classroom = Classroom::find($activity->classID);
        $className = $classroom ? $classroom->className : 'Unknown Class';

        $student->notifications()->create([
            'type' => 'Deadline Changed',
            'data' => json_encode([
                'activity_id' => $activity->actID,
                'message'     => "The deadline for \"{$activity->actTitle}\" in \"{$className}\" has been changed.",
            ]),
        ]);
    }
}