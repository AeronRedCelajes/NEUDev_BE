<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeadlineChanged
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
}