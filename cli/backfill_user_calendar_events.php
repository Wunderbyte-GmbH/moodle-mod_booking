<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Create the personal calendar events missing for future sessions of booked users.
 *
 * Companion of the setting "Dont add personal calendar events": the admin settings page queues the
 * backfill automatically when the setting is switched off again, but set_config() from the CLI or a
 * forced setting in config.php does not. This script queues (default) or runs the same adhoc task chain,
 * and --dry-run only reports how many events are missing per option.
 *
 * Usage:
 *   php mod/booking/cli/backfill_user_calendar_events.php            queue the task chain (cron does the work)
 *   php mod/booking/cli/backfill_user_calendar_events.php --run      run the chain here until it is done
 *   php mod/booking/cli/backfill_user_calendar_events.php --dry-run  count the missing events, write nothing
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

use core\task\manager;
use mod_booking\local\calendar\calendar_helper;
use mod_booking\task\backfill_user_calendar_events_adhoc;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'run' => false, 'dry-run' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln(get_string('cli:backfillusercalendarevents:help', 'mod_booking'));
    exit(0);
}

if ($options['dry-run']) {
    $now = time();
    $lastoptionid = 0;
    $totaloptions = 0;
    $totalmissing = 0;
    while ($optionids = calendar_helper::get_optionids_with_future_sessions($lastoptionid, $now, 500)) {
        foreach ($optionids as $optionid) {
            $missing = calendar_helper::count_missing_user_events_for_option($optionid, $now);
            if ($missing > 0) {
                cli_writeln("option $optionid: $missing missing");
                $totaloptions++;
                $totalmissing += $missing;
            }
            $lastoptionid = $optionid;
        }
    }
    cli_writeln("$totalmissing missing personal calendar events in $totaloptions option(s).");
    exit(0);
}

if (!empty(get_config('booking', 'dontaddpersonalevents'))) {
    cli_error(get_string('cli:backfillusercalendarevents:settingison', 'mod_booking'), 1);
}

if (!backfill_user_calendar_events_adhoc::queue()) {
    cli_writeln(get_string('cli:backfillusercalendarevents:alreadyqueued', 'mod_booking'));
} else {
    cli_writeln(get_string('cli:backfillusercalendarevents:queued', 'mod_booking'));
}

if ($options['run']) {
    // Run the chain in this process; each task requeues the next one until an empty page ends the chain.
    while ($tasks = manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class)) {
        $task = reset($tasks);
        \core\cron::run_adhoc_task((int)$task->get_id());
    }
}
exit(0);
