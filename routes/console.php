<?php

Schedule::command('outbox:publish-pending')
    ->everyMinute()
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('data-publish-pending');