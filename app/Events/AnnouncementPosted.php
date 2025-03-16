<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnnouncementPosted
{
    use Dispatchable, SerializesModels;

    public $announcement;  // Announcement model instance
    public $notifiables;   // Collection or array of Student model instances

    /**
     * Create a new event instance.
     *
     * @param  mixed  $announcement
     * @param  mixed  $notifiables
     */
    public function __construct($announcement, $notifiables)
    {
        $this->announcement = $announcement;
        $this->notifiables = $notifiables; // e.g., a list of students
    }
}