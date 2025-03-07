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
        'itemID',
        'draftFiles',
        'draftTestCaseResults',
        'timeRemaining',
    ];

    protected $casts = [
        'draftFiles' => 'array',
        'draftTestCaseResults' => 'array',
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

    /**
     * Get the item (question) associated with this progress.
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'itemID', 'itemID');
    }
}