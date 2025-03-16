<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConcernPosted
{
    use Dispatchable, SerializesModels;

    public $concern;     // Concern model instance
    public $notifiable;  // Teacher model instance

    /**
     * Create a new event instance.
     *
     * @param  mixed  $concern
     * @param  mixed  $notifiable
     */
    public function __construct($concern, $notifiable)
    {
        $this->concern = $concern;
        $this->notifiable = $notifiable;
    }
}