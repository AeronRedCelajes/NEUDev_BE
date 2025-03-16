<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivityStarted
{
    use Dispatchable, SerializesModels;

    public $activity;    // Activity model instance
    public $notifiable;  // Student model instance

    /**
     * Create a new event instance.
     *
     * @param  mixed  $activity
     * @param  mixed  $notifiable
     */
    public function __construct($activity, $notifiable)
    {
        $this->activity = $activity;
        $this->notifiable = $notifiable;
    }

    /**
     * Example broadcast channel (optional if you're not using broadcasting)
     */
    public function broadcastOn(): array
    {
        if ($this->notifiable instanceof \App\Models\Student) {
            return [new PrivateChannel('user.' . $this->notifiable->studentID)];
        } elseif ($this->notifiable instanceof \App\Models\Teacher) {
            return [new PrivateChannel('user.' . $this->notifiable->teacherID)];
        }
        return [];
    }
}