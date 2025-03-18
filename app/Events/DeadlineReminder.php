<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeadlineReminder
{
    use Dispatchable, SerializesModels;

    public $activity;    // Activity model instance
    public $notifiable;  // Student model instance
    public $timeLeft;    // e.g. '1_day' or '1_hour'

    /**
     * Create a new event instance.
     *
     * @param  mixed  $activity
     * @param  mixed  $notifiable
     * @param  string $timeLeft
     */
    public function __construct($activity, $notifiable, string $timeLeft)
    {
        $this->activity = $activity;
        $this->notifiable = $notifiable;
        $this->timeLeft = $timeLeft;
    }
}