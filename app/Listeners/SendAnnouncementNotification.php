<?php

namespace App\Listeners;

use App\Events\AnnouncementPosted;

class SendAnnouncementNotification
{
    /**
     * Handle the event.
     *
     * @param  \App\Events\AnnouncementPosted  $event
     * @return void
     */
    public function handle(AnnouncementPosted $event)
    {
        $announcement = $event->announcement;
        
        // Ensure the classroom relationship is loaded
        $announcement->loadMissing('classroom');

        // Get the class name safely
        $className = $announcement->classroom ? $announcement->classroom->className : 'Unknown Class';

        $students = $event->notifiables; // Collection of Student models

        foreach ($students as $student) {
            $student->notifications()->create([
                'type' => 'Announcement Posted',
                'data' => json_encode([
                    'announcement_id' => $announcement->classID,
                    'title'           => $announcement->title,
                    // Only include class name and title in the notification message.
                    'message'         => 'Class "' . $className . '" - ' . $announcement->title,
                ]),
            ]);
        }
    }
}