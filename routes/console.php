<?php

Schedule::command('outbox:publish-pending')
    ->everyMinute()
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('data-publish-pending');

// TICKET UPDATE NOTIFICATIONS
// Every three hours each changed ticket's Store Managers get one notification
// of what changed since they were last told: grouped, never one per action.
Schedule::command('tickets:send-update-notifications')
    ->everyThreeHours()
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('ticket-update-notifications');
