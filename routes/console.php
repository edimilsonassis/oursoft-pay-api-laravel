<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pay:sync-payments')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
