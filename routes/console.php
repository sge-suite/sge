<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('holidays:prepare')->dailyAt('03:00')->timezone(config('app.timezone'))->onOneServer()->withoutOverlapping(180);
