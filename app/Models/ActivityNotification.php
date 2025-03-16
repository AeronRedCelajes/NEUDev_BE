<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityNotification extends Model
{
    use HasFactory;

    protected $table = 'activity_notifications';

    protected $fillable = ['studentID', 'actID'];
    
    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class, 'actID', 'actID');
    }
}