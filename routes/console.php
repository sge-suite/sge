<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('documents:prune-temporary')->hourly()->withoutOverlapping();

Schedule::command('internships:sync-execution-status')->dailyAt('00:05')->timezone(config('app.timezone'))->onOneServer()->withoutOverlapping();
Schedule::command('holidays:prepare')->dailyAt('03:00')->timezone(config('app.timezone'))->onOneServer()->withoutOverlapping(180);
Schedule::command('internship-requests:notify-office')->dailyAt('08:00')->timezone(config('app.timezone'))->onOneServer()->withoutOverlapping();
