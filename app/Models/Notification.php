<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'notifiable_id', 
        'notifiable_type', 
        'type', 
        'data',
        'isRead'
    ];

    /**
     * Get the parent notifiable model (student or teacher).
     */
    public function notifiable()
    {
        return $this->morphTo();
    }
}