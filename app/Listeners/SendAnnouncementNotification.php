<?php

namespace App\Listeners;

use App\Events\AnnouncementPosted;
use App\Models\Teacher;

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

        // Get teacher's name.
        // Try to get it from the teacher relationship if it's loaded.
        $teacherName = 'Unknown Teacher';
        if (isset($announcement->teacher) && $announcement->teacher) {
            $teacherName = trim($announcement->teacher->firstname . ' ' . $announcement->teacher->lastname);
        } elseif (isset($announcement->teacherID)) {
            // If the teacher relationship isn't loaded, attempt to query the teacher.
            $teacher = Teacher::find($announcement->teacherID);
            if ($teacher) {
                $teacherName = trim($teacher->firstname . ' ' . $teacher->lastname);
            }
        }

        $students = $event->notifiables; // Collection of Student models

        foreach ($students as $student) {
            $student->notifications()->create([
                'type' => 'Announcement Posted',
                'data' => json_encode([
                    'announcement_id' => $announcement->id, // or use another unique identifier if needed
                    'message'         => 'Teacher ' . $teacherName . ' has posted an announcement in ' . $className . '.',
                ]),
            ]);
        }
    }
}