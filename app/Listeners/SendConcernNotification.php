<?php

namespace App\Listeners;

use App\Events\ConcernPosted;
use App\Models\Classroom;

class SendConcernNotification
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\ConcernPosted  $event
     * @return void
     */
    public function handle(ConcernPosted $event)
    {
        $concern = $event->concern;
        $teacher = $event->notifiable; // Teacher model

        // Retrieve the classroom by classID
        $classroom = Classroom::find($concern->classID);
        $className = $classroom ? $classroom->className : 'Unknown Class';

        $teacher->notifications()->create([
            'type' => 'Concern Posted',
            'data' => json_encode([
                'concern_id' => $concern->id,
                'message'    => 'A student has posted a concern in ' . $className . '.',
            ]),
        ]);
    }
}