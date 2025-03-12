<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivitySubmission extends Model
{
    use HasFactory;

    protected $table = 'activity_submissions';
    protected $primaryKey = 'submissionID';
    public $timestamps = true;

    protected $fillable = [
        'actID',
        'studentID',
        'itemID',         // Added for per-item submission
        'attemptNo',      // New field for tracking attempt number per item
        'codeSubmission', // Will store JSON string for multiple files
        'score',
        'itemTimeSpent',
        'submitted_at',
    ];

    // Cast the codeSubmission field to array automatically.
    protected $casts = [
        'codeSubmission' => 'array',
    ];

    /**
     * Get the associated activity.
     */
    public function activity()
    {
        return $this->belongsTo(Activity::class, 'actID', 'actID');
    }

    /**
     * Get the student who made the submission.
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'studentID', 'studentID');
    }

    /**
     * Get the item linked to this submission.
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'itemID', 'itemID');
    }
}