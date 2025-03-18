<?php

namespace App\Listeners;

use App\Events\DeadlineChanged;

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

        $student->notifications()->create([
            'type' => 'Deadline Changed',
            'data' => json_encode([
                'activity_id' => $activity->actID,
                'message'     => "The deadline for \"{$activity->actTitle}\" has been changed.",
            ]),
        ]);
    }
}