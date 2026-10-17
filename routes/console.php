<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('holidays:prepare')->dailyAt('03:00')->timezone(config('app.timezone'))->onOneServer()->withoutOverlapping(180);
Schedule::command('internship-requests:notify-office')->dailyAt('08:00')->timezone(config('app.timezone'))->onOneServer()->withoutOverlapping();
