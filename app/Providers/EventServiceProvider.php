<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

// Events
use App\Events\ActivityStarted;
use App\Events\DeadlineReminder;
use App\Events\DeadlineChanged;
use App\Events\AnnouncementPosted;
use App\Events\ConcernPosted;

// Listeners
use App\Listeners\SendActivityStartedNotification;
use App\Listeners\SendDeadlineReminderNotification;
use App\Listeners\SendDeadlineChangedNotification;
use App\Listeners\SendAnnouncementNotification;
use App\Listeners\SendConcernNotification;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ActivityStarted::class => [
            SendActivityStartedNotification::class,
        ],
        DeadlineReminder::class => [
            SendDeadlineReminderNotification::class,
        ],
        DeadlineChanged::class => [
            SendDeadlineChangedNotification::class,
        ],
        AnnouncementPosted::class => [
            SendAnnouncementNotification::class,
        ],
        ConcernPosted::class => [
            SendConcernNotification::class, 
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}