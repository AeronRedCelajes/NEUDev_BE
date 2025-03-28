<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityProgress extends Model
{
    use HasFactory;

    protected $table = 'activity_progress';
    protected $primaryKey = 'progressID';
    public $timestamps = true;

    protected $fillable = [
        'actID',
        'progressable_id',
        'progressable_type',
        'draftFiles',
        'draftTestCaseResults',
        'draftCheckCodeRuns',
        'draftDeductedScore',
        'draftTimeRemaining',
        'draftSelectedLanguage',
        'draftScore',
        'draftItemTimes'
    ];

    protected $casts = [
        'draftScore' => 'float',
        'draftFiles' => 'array',
        'draftTestCaseResults' => 'array',
        'draftCheckCodeRuns' => 'array',
        'draftDeductedScore' => 'array',
    ];

    /**
     * Define the polymorphic relationship.
     */
    public function progressable()
    {
        return $this->morphTo();
    }

    /**
     * Get the associated activity.
     */
    public function activity()
    {
        return $this->belongsTo(Activity::class, 'actID', 'actID');
    }
}
