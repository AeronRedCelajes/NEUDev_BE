<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityStudent extends Model
{
    use HasFactory;

    protected $table = 'activity_student';

    // Add 'finalTimeSpent' to the fillable properties.
    protected $fillable = [
        'actID',
        'studentID',
        'attemptsTaken',
        'finalScore',
        'finalTimeSpent',  // NEW: Overall time spent (in seconds) for the activity.
        'rank'
    ];

    public $timestamps = true;

    public function activity()
    {
        return $this->belongsTo(Activity::class, 'actID', 'actID');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }
}