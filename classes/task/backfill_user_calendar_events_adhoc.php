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

namespace mod_booking\task;

use core\task\adhoc_task;
use core\task\manager;
use mod_booking\booking_utils;
use mod_booking\calendar;
use mod_booking\local\calendar\calendar_helper;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Adhoc task that creates the missing personal calendar events of booked users.
 *
 * While the setting "Dont add personal calendar events" (booking/dontaddpersonalevents) is on, no personal
 * calendar events are created for bookings. When it is switched off again, this task walks over all booking
 * options with future sessions and creates the events that a live booking would have created, for every
 * booked user and every future session - via the very same code path (calendar::create_user_event_for_optiondate()),
 * without triggering any mod_booking event, rule, mail or other side effect.
 *
 * Built for sites with tens of thousands of affected events:
 * - options are visited in pages (keyset pagination on the option id, no OFFSET),
 * - the missing (user, session) pairs of an option are read as a recordset,
 * - each run stops after a page, a time budget or an event budget and requeues itself with a cursor,
 * - the per-option and per-user caches of the singleton service are released after every option,
 * - the task is idempotent: a pair with an existing event is never touched, so a retry costs nothing,
 * - it stops without writing when the setting has been switched on again in the meantime.
 *
 * Custom data: lastoptionid (cursor), created, repaired, failed, started, and the optional overrides
 * optionsperrun, maxeventsperrun, timebudget (seconds).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_user_calendar_events_adhoc extends adhoc_task {
    /** @var int Options visited per run at most. */
    public const OPTIONS_PER_RUN = 50;

    /** @var int Events created per run at most; the run requeues itself afterwards. */
    public const MAX_EVENTS_PER_RUN = 2000;

    /** @var int Seconds a run may work before it requeues itself. */
    public const TIME_BUDGET = 60;

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskbackfillusercalendareventsadhoc', 'mod_booking');
    }

    /**
     * Is a backfill task pending (queued or running)?
     *
     * @return bool
     */
    public static function is_queued(): bool {
        return !empty(manager::get_adhoc_tasks(self::class));
    }

    /**
     * Queue a new backfill chain starting at the first option, unless one is pending already.
     *
     * @param array $overrides optional keys optionsperrun, maxeventsperrun, timebudget (tests, CLI)
     * @return bool true if a task was queued, false if one was pending already
     */
    public static function queue(array $overrides = []): bool {
        if (self::is_queued()) {
            return false;
        }
        $data = array_merge(
            $overrides,
            [
                'lastoptionid' => 0,
                'created' => 0,
                'repaired' => 0,
                'failed' => 0,
                'started' => time(),
            ]
        );
        $task = new self();
        $task->set_custom_data($data);
        manager::queue_adhoc_task($task, true);
        return true;
    }

    /**
     * Execution function.
     *
     * {@inheritdoc}
     * @see \core\task\task_base::execute()
     */
    public function execute() {
        $data = (array)($this->get_custom_data() ?? []);
        $data += ['lastoptionid' => 0, 'created' => 0, 'repaired' => 0, 'failed' => 0, 'started' => time()];
        $optionsperrun = max(1, (int)($data['optionsperrun'] ?? self::OPTIONS_PER_RUN));
        $maxevents = max(1, (int)($data['maxeventsperrun'] ?? self::MAX_EVENTS_PER_RUN));
        $timebudget = max(1, (int)($data['timebudget'] ?? self::TIME_BUDGET));

        if (!empty(get_config('booking', 'dontaddpersonalevents'))) {
            mtrace(self::class . ': personal calendar events are switched off again, stopping the backfill.');
            return;
        }

        $now = time();
        $starttime = microtime(true);
        $eventsthisrun = 0;
        $lastoptionid = (int)$data['lastoptionid'];
        $budgetreached = false;

        $optionids = calendar_helper::get_optionids_with_future_sessions($lastoptionid, $now, $optionsperrun);
        if (empty($optionids)) {
            mtrace(sprintf(
                '%s: finished. %d events created, %d repaired, %d failed, started %s.',
                self::class,
                $data['created'],
                $data['repaired'],
                $data['failed'],
                userdate((int)$data['started'])
            ));
            return;
        }

        foreach ($optionids as $optionid) {
            if ($eventsthisrun >= $maxevents || (microtime(true) - $starttime) >= $timebudget) {
                // Stop before the next option; the cursor stays on the last completed option.
                $budgetreached = true;
                break;
            }
            $result = $this->process_option($optionid, $now);
            $data['created'] += $result['created'];
            $data['repaired'] += $result['repaired'];
            $data['failed'] += $result['failed'];
            $eventsthisrun += $result['created'] + $result['repaired'];
            $lastoptionid = $optionid;
        }

        $data['lastoptionid'] = $lastoptionid;
        mtrace(sprintf(
            '%s: run done, cursor at option %d, %d events in this run (%d created, %d repaired, %d failed in total).',
            self::class,
            $lastoptionid,
            $eventsthisrun,
            $data['created'],
            $data['repaired'],
            $data['failed']
        ));

        // Requeue with the cursor. When the page was full or a budget stopped us, there may be more to do;
        // the next run finds out (an empty page ends the chain).
        if ($budgetreached || count($optionids) >= $optionsperrun) {
            $next = new self();
            $next->set_custom_data($data);
            manager::queue_adhoc_task($next, true);
        } else {
            mtrace(sprintf(
                '%s: finished. %d events created, %d repaired, %d failed, started %s.',
                self::class,
                $data['created'],
                $data['repaired'],
                $data['failed'],
                userdate((int)$data['started'])
            ));
        }
    }

    /**
     * Create the missing personal events of one option.
     *
     * @param int $optionid
     * @param int $now
     * @return array counts: created, repaired, failed
     */
    private function process_option(int $optionid, int $now): array {
        $result = ['created' => 0, 'repaired' => 0, 'failed' => 0];
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id) || empty($settings->cmid)) {
            mtrace("... option $optionid has no course module any more, skipped.");
            return $result;
        }
        $cmid = (int)$settings->cmid;

        $optiondates = calendar_helper::get_future_optiondates($optionid, $now);
        $touchedusers = [];
        $inserted = 0;
        $recordset = calendar_helper::get_missing_user_events_for_option($optionid, $now);
        foreach ($recordset as $pair) {
            $userid = (int)$pair->userid;
            $optiondateid = (int)$pair->optiondateid;
            if (!isset($optiondates[$optiondateid])) {
                continue;
            }
            $touchedusers[$userid] = true;
            $hadrow = !empty($pair->stalerows);
            try {
                $eventid = calendar::create_user_event_for_optiondate(
                    $cmid,
                    $optionid,
                    $optiondates[$optiondateid],
                    $userid,
                    false
                );
                if ($eventid) {
                    if ($hadrow) {
                        $result['repaired']++;
                    } else {
                        $result['created']++;
                        $inserted++;
                    }
                }
            } catch (\Throwable $e) {
                // One broken pair must not stop the chain; the next full run picks it up again.
                $result['failed']++;
                mtrace("... ERROR creating event for user $userid, option $optionid, session $optiondateid: "
                    . $e->getMessage());
            }
        }
        $recordset->close();

        if ($inserted > 0) {
            // Same legacy step as the live path, once per option instead of once per event (idempotent).
            $bu = new booking_utils();
            $bu->booking_hide_option_userevents($optionid);
        }

        // Release the per-option and per-user caches, otherwise a run over many options grows without bound.
        singleton_service::destroy_booking_option_singleton($optionid);
        singleton_service::destroy_booking_answers($optionid);
        foreach (array_keys($touchedusers) as $userid) {
            singleton_service::destroy_user($userid);
        }

        mtrace(sprintf(
            '... option %d: %d created, %d repaired, %d failed.',
            $optionid,
            $result['created'],
            $result['repaired'],
            $result['failed']
        ));
        return $result;
    }
}
