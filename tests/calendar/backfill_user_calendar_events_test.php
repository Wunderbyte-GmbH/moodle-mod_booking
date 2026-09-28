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
 * Tests for the backfill of personal calendar events (adhoc task, setting callback).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use core\task\manager;
use mod_booking\task\backfill_user_calendar_events_adhoc;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the backfill of personal calendar events.
 *
 * Covers Wunderbyte-GmbH/moodle-mod_booking#1613: when the setting "Dont add personal calendar events"
 * is switched off again, the missing personal events of all booked users for all future sessions are
 * created by an adhoc task - exactly like a live booking would create them, without any booking event,
 * rule, mail or other side effect.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\backfill_user_calendar_events_adhoc
 * @covers \mod_booking\calendar::create_user_event_for_optiondate
 * @covers \mod_booking\local\calendar\calendar_helper::get_missing_user_events_for_option
 * @covers \mod_booking\local\calendar\calendar_helper::get_optionids_with_future_sessions
 */
final class backfill_user_calendar_events_test extends booking_advanced_testcase {
    /** @var array<string, int> Columns of {event} that are compared between a live and a backfilled event. */
    private const COMPARED_EVENT_COLUMNS = [
        'name', 'description', 'format', 'courseid', 'groupid', 'categoryid', 'repeatid', 'component', 'modulename',
        'instance', 'type', 'eventtype', 'timestart', 'timeduration', 'timesort', 'visible', 'uuid', 'sequence',
        'priority', 'location',
    ];

    /**
     * Data provider with the common booking instance settings.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Backfill Calendar Test',
            'eventtype' => 'Test',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }

    /**
     * Create a booking instance in a fresh course.
     *
     * @param array $bdata
     * @return stdClass the booking instance (with cmid)
     */
    private function create_booking(array $bdata): stdClass {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata['course'] = $course->id;
        return $this->getDataGenerator()->create_module('booking', $bdata);
    }

    /**
     * Create a booking option with the given session times.
     *
     * @param stdClass $booking
     * @param array $sessions list of [starttime, endtime]
     * @param int $maxanswers 0 = unlimited
     * @param string $text
     * @return int optionid
     */
    private function create_option(stdClass $booking, array $sessions, int $maxanswers = 0, string $text = 'Option'): int {
        $this->setAdminUser();
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = 0;
        $record->description = 'description of ' . $text;
        $record->maxanswers = $maxanswers;
        $record->maxoverbooking = $maxanswers > 0 ? 5 : 0;
        $record->addtocalendar = 0;
        foreach ($sessions as $i => [$start, $end]) {
            $record->{"optiondateid_$i"} = "0";
            $record->{"daystonotify_$i"} = "0";
            $record->{"coursestarttime_$i"} = $start;
            $record->{"courseendtime_$i"} = $end;
        }
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        return (int)$option->id;
    }

    /**
     * Book a user into an option through the regular booking path.
     *
     * @param stdClass $booking
     * @param int $optionid
     * @param stdClass $user
     */
    private function book(stdClass $booking, int $optionid, stdClass $user): void {
        $bookingoption = singleton_service::get_instance_of_booking_option($booking->cmid, $optionid);
        $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
    }

    /**
     * Personal calendar events of a user, keyed by timestart.
     *
     * @param int $userid
     * @return array
     */
    private function user_events(int $userid): array {
        global $DB;
        $events = $DB->get_records('event', ['component' => 'mod_booking', 'eventtype' => 'user', 'userid' => $userid]);
        $bystart = [];
        foreach ($events as $event) {
            $bystart[(int)$event->timestart] = $event;
        }
        ksort($bystart);
        return $bystart;
    }

    /**
     * Run the whole task chain (execute, pick up the requeued task, repeat) and return the number of runs.
     *
     * @param int $maxruns safety net against endless loops
     * @return int
     */
    private function run_chain(int $maxruns = 20): int {
        $runs = 0;
        while ($runs < $maxruns) {
            $tasks = manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class);
            if (empty($tasks)) {
                return $runs;
            }
            $this->assertCount(1, $tasks, 'Only one backfill task may be pending at a time.');
            $task = reset($tasks);
            $runs++;
            $this->execute_task($task);
        }
        $this->fail("Backfill chain did not finish within $maxruns runs.");
    }

    /**
     * Execute one adhoc task and mark it complete, as cron would.
     *
     * @param \core\task\adhoc_task $task
     */
    private function execute_task(\core\task\adhoc_task $task): void {
        global $DB;
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
        // Cron would call manager::adhoc_task_complete(); without a task lock we drop the record ourselves.
        if ($task->get_id()) {
            $DB->delete_records('task_adhoc', ['id' => $task->get_id()]);
        }
    }

    /**
     * Backfilled events are identical to live events, only future sessions and booked users are covered,
     * a second run creates nothing.
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_backfill_creates_identical_events_for_future_sessions(array $bdata): void {
        global $DB;

        $booking = $this->create_booking($bdata);
        $past = [strtotime('10 January 2020 08:00:00'), strtotime('10 January 2020 10:00:00')];
        $future1 = [strtotime('20 June 2050 08:00:00'), strtotime('20 June 2050 09:30:00')];
        $future2 = [strtotime('21 June 2050 08:00:00'), strtotime('21 June 2050 09:30:00')];
        $optionid = $this->create_option($booking, [$past, $future1, $future2], 2);

        $live = $this->getDataGenerator()->create_user(['lang' => 'en']);
        $missing = $this->getDataGenerator()->create_user(['lang' => 'en']);
        $waiting = $this->getDataGenerator()->create_user();

        // Live booking with personal events switched on: three events, one per session.
        set_config('dontaddpersonalevents', 0, 'booking');
        $this->book($booking, $optionid, $live);
        $liveevents = $this->user_events((int)$live->id);
        $this->assertCount(3, $liveevents);

        // Bookings while the personal events are switched off: no event at all.
        set_config('dontaddpersonalevents', 1, 'booking');
        $this->book($booking, $optionid, $missing);
        $this->book($booking, $optionid, $waiting);
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int)$DB->get_field('booking_answers', 'waitinglist', ['optionid' => $optionid, 'userid' => $missing->id])
        );
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int)$DB->get_field('booking_answers', 'waitinglist', ['optionid' => $optionid, 'userid' => $waiting->id])
        );
        $this->assertCount(0, $this->user_events((int)$missing->id));
        $this->assertCount(0, $this->user_events((int)$waiting->id));

        // Switch the personal events on again and run the backfill.
        set_config('dontaddpersonalevents', 0, 'booking');
        $this->assertTrue(backfill_user_calendar_events_adhoc::queue());
        $this->run_chain();

        // The booked user got the two future sessions, not the past one.
        $backfilled = $this->user_events((int)$missing->id);
        $this->assertSame([$future1[0], $future2[0]], array_keys($backfilled));

        // Byte-identical to the live events of the same sessions.
        foreach ($backfilled as $timestart => $event) {
            foreach (self::COMPARED_EVENT_COLUMNS as $column) {
                $this->assertEquals(
                    $liveevents[$timestart]->$column,
                    $event->$column,
                    "Column $column of the backfilled event differs from the live event."
                );
            }
            $this->assertEquals($missing->id, $event->userid);
            $userevent = $DB->get_record(
                'booking_userevents',
                ['userid' => $missing->id, 'optionid' => $optionid, 'eventid' => $event->id],
                '*',
                MUST_EXIST
            );
            $this->assertEquals(
                $DB->get_field('booking_optiondates', 'id', ['coursestarttime' => $timestart]),
                $userevent->optiondateid
            );
        }

        // Waiting list user and live user are untouched.
        $this->assertCount(0, $this->user_events((int)$waiting->id));
        $this->assertCount(3, $this->user_events((int)$live->id));
        $this->assertEquals(array_keys($liveevents), array_keys($this->user_events((int)$live->id)));

        // A second run is a no-op.
        $before = $DB->count_records('event');
        $this->assertTrue(backfill_user_calendar_events_adhoc::queue());
        $this->run_chain();
        $this->assertSame($before, $DB->count_records('event'));
        $this->assertCount(2, $this->user_events((int)$missing->id));
    }

    /**
     * The task triggers no mod_booking event, sends no message and leaves the booking answers alone.
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_backfill_is_silent(array $bdata): void {
        global $DB;

        $booking = $this->create_booking($bdata);
        $optionid = $this->create_option(
            $booking,
            [[strtotime('20 June 2050 08:00:00'), strtotime('20 June 2050 09:00:00')]]
        );
        $user = $this->getDataGenerator()->create_user();
        set_config('dontaddpersonalevents', 1, 'booking');
        $this->book($booking, $optionid, $user);
        $answersbefore = $DB->get_records('booking_answers', [], 'id');
        $historybefore = $DB->count_records('booking_history');

        set_config('dontaddpersonalevents', 0, 'booking');
        backfill_user_calendar_events_adhoc::queue();

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        $this->run_chain();
        $events = $eventsink->get_events();
        $eventsink->close();
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assertCount(1, $this->user_events((int)$user->id));
        $this->assertCount(0, $messages);
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertNotSame('mod_booking', $event->component, 'The backfill must not trigger ' . get_class($event));
            $this->assertInstanceOf(\core\event\calendar_event_created::class, $event);
        }
        $this->assertEquals($answersbefore, $DB->get_records('booking_answers', [], 'id'));
        $this->assertSame($historybefore, $DB->count_records('booking_history'));
    }

    /**
     * A booking_userevents row that points to a deleted event is repaired, deleted users are skipped.
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_backfill_repairs_stale_rows_and_skips_deleted_users(array $bdata): void {
        global $DB;

        $booking = $this->create_booking($bdata);
        $optionid = $this->create_option(
            $booking,
            [[strtotime('20 June 2050 08:00:00'), strtotime('20 June 2050 09:00:00')]]
        );
        $stale = $this->getDataGenerator()->create_user();
        $deleted = $this->getDataGenerator()->create_user();
        set_config('dontaddpersonalevents', 0, 'booking');
        $this->book($booking, $optionid, $stale);
        set_config('dontaddpersonalevents', 1, 'booking');
        $this->book($booking, $optionid, $deleted);

        // Simulate calendar_helper::option_set_visibility_for_all_calendar_events() followed by an event purge:
        // the booking_userevents row survives, the event is gone.
        $staleevents = $this->user_events((int)$stale->id);
        $staleevent = reset($staleevents);
        $DB->delete_records('event', ['id' => $staleevent->id]);
        $userevent = $DB->get_record('booking_userevents', ['userid' => $stale->id, 'optionid' => $optionid], '*', MUST_EXIST);
        $this->assertEquals($staleevent->id, $userevent->eventid);

        // The other user is deleted without going through booking (answer row stays).
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);

        set_config('dontaddpersonalevents', 0, 'booking');
        backfill_user_calendar_events_adhoc::queue();
        $this->run_chain();

        $repaired = $this->user_events((int)$stale->id);
        $this->assertCount(1, $repaired);
        $newevent = reset($repaired);
        $this->assertNotEquals($staleevent->id, $newevent->id);
        $this->assertSame(1, $DB->count_records('booking_userevents', ['userid' => $stale->id, 'optionid' => $optionid]));
        $this->assertEquals($newevent->id, $DB->get_field('booking_userevents', 'eventid', ['id' => $userevent->id]));
        $this->assertCount(0, $this->user_events((int)$deleted->id));
    }

    /**
     * The task works in pages of options and requeues itself with a cursor until every option is done.
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_backfill_pages_over_options_and_requeues(array $bdata): void {
        global $DB;

        $booking = $this->create_booking($bdata);
        $sessions = [
            [strtotime('20 June 2050 08:00:00'), strtotime('20 June 2050 09:00:00')],
            [strtotime('21 June 2050 08:00:00'), strtotime('21 June 2050 09:00:00')],
        ];
        $optionids = [];
        set_config('dontaddpersonalevents', 1, 'booking');
        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $optionids[$i] = $this->create_option($booking, $sessions, 0, "Option $i");
            $users[$i] = $this->getDataGenerator()->create_user();
            $this->book($booking, $optionids[$i], $users[$i]);
        }
        // An option without a future session must not be visited at all.
        $pastoptionid = $this->create_option(
            $booking,
            [[strtotime('10 January 2020 08:00:00'), strtotime('10 January 2020 09:00:00')]],
            0,
            'Past option'
        );
        $this->book($booking, $pastoptionid, $users[0]);
        $this->assertCount(0, $DB->get_records('event', ['component' => 'mod_booking', 'eventtype' => 'user']));

        set_config('dontaddpersonalevents', 0, 'booking');
        $this->assertTrue(backfill_user_calendar_events_adhoc::queue(['optionsperrun' => 1]));

        // First run: exactly one option, then a requeued task with the cursor on that option.
        $tasks = manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class);
        $this->assertCount(1, $tasks);
        $this->execute_task(reset($tasks));
        $this->assertCount(2, $DB->get_records('event', ['component' => 'mod_booking', 'eventtype' => 'user']));
        $tasks = manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class);
        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();
        $this->assertEquals(min($optionids), $data->lastoptionid);
        $this->assertEquals(2, $data->created);
        $this->assertEquals(1, $data->optionsperrun);

        // The rest of the chain: one run per remaining option plus the final empty page.
        $runs = $this->run_chain();
        $this->assertSame(3, $runs);
        $this->assertCount(6, $DB->get_records('event', ['component' => 'mod_booking', 'eventtype' => 'user']));
        foreach ($users as $i => $user) {
            $this->assertSame([$sessions[0][0], $sessions[1][0]], array_keys($this->user_events((int)$user->id)));
        }
        $this->assertEmpty(manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class));
    }

    /**
     * When the events are switched off again while the chain runs, the task stops without writing.
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_backfill_stops_when_setting_is_switched_on_again(array $bdata): void {
        global $DB;

        $booking = $this->create_booking($bdata);
        $optionid = $this->create_option(
            $booking,
            [[strtotime('20 June 2050 08:00:00'), strtotime('20 June 2050 09:00:00')]]
        );
        $user = $this->getDataGenerator()->create_user();
        set_config('dontaddpersonalevents', 1, 'booking');
        $this->book($booking, $optionid, $user);

        set_config('dontaddpersonalevents', 0, 'booking');
        $this->assertTrue(backfill_user_calendar_events_adhoc::queue());
        // Admin changes their mind before cron picks the task up.
        set_config('dontaddpersonalevents', 1, 'booking');

        $runs = $this->run_chain();
        $this->assertSame(1, $runs);
        $this->assertCount(0, $this->user_events((int)$user->id));
        $this->assertEmpty(manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class));
    }

    /**
     * Queueing is guarded: only one pending task, whatever the caller does.
     */
    public function test_queue_is_guarded_against_duplicates(): void {
        $this->assertEmpty(manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class));
        $this->assertFalse(backfill_user_calendar_events_adhoc::is_queued());
        $this->assertTrue(backfill_user_calendar_events_adhoc::queue());
        $this->assertTrue(backfill_user_calendar_events_adhoc::is_queued());
        $this->assertFalse(backfill_user_calendar_events_adhoc::queue());
        $this->assertFalse(backfill_user_calendar_events_adhoc::queue(['optionsperrun' => 1]));
        $this->assertCount(1, manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class));
    }

    /**
     * Saving the setting through the admin tree queues the task exactly when the events are switched on again.
     */
    public function test_setting_callback_queues_task_when_switched_on_again(): void {
        $this->setAdminUser();
        set_config('dontaddpersonalevents', 0, 'booking');

        // Switching the personal events off (checkbox on) queues nothing.
        $this->write_setting('1');
        $this->assertEmpty(manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class));

        // Switching them on again (checkbox off) queues one task.
        $this->write_setting('0');
        $this->assertCount(1, manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class));

        // Toggling twice while the task is pending keeps one task.
        $this->write_setting('1');
        $this->write_setting('0');
        $this->assertCount(1, manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class));
    }

    /**
     * Write the setting the way the admin settings page does, including the updated callback.
     *
     * @param string $value
     */
    private function write_setting(string $value): void {
        $adminroot = admin_get_root(true, true);
        $page = $adminroot->locate('modsettingbooking');
        $this->assertNotNull($page);
        $setting = $page->settings->bookingdontaddpersonalevents;
        $this->assertInstanceOf(\admin_setting_configcheckbox::class, $setting);
        $data = [$setting->get_full_name() => $value];
        $count = admin_write_settings((object)$data);
        $this->assertSame(1, $count, 'The setting must have changed.');
    }

    /**
     * The number of DB reads per created event stays bounded; the cost of an option does not grow with anything
     * but its number of missing events.
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_backfill_reads_per_event_are_bounded(array $bdata): void {
        global $DB;

        $booking = $this->create_booking($bdata);
        $sessions = [
            [strtotime('20 June 2050 08:00:00'), strtotime('20 June 2050 09:00:00')],
            [strtotime('21 June 2050 08:00:00'), strtotime('21 June 2050 09:00:00')],
        ];
        set_config('dontaddpersonalevents', 1, 'booking');
        $small = $this->create_option($booking, $sessions, 0, 'Small');
        $large = $this->create_option($booking, $sessions, 0, 'Large');
        for ($i = 0; $i < 2; $i++) {
            $this->book($booking, $small, $this->getDataGenerator()->create_user());
        }
        for ($i = 0; $i < 8; $i++) {
            $this->book($booking, $large, $this->getDataGenerator()->create_user());
        }
        set_config('dontaddpersonalevents', 0, 'booking');
        singleton_service::destroy_instance();

        $task = new backfill_user_calendar_events_adhoc();
        $task->set_custom_data(['lastoptionid' => 0, 'optionsperrun' => 1]);
        $reads = $DB->perf_get_reads();
        $this->execute_task($task);
        $readssmall = $DB->perf_get_reads() - $reads;
        $this->assertCount(4, $DB->get_records('event', ['component' => 'mod_booking', 'eventtype' => 'user']));

        $tasks = manager::get_adhoc_tasks(backfill_user_calendar_events_adhoc::class);
        $this->assertCount(1, $tasks);
        singleton_service::destroy_instance();
        $reads = $DB->perf_get_reads();
        $this->execute_task(reset($tasks));
        $readslarge = $DB->perf_get_reads() - $reads;
        $this->assertCount(20, $DB->get_records('event', ['component' => 'mod_booking', 'eventtype' => 'user']));

        // 4 events vs 16 events: the marginal cost per event is what matters (measured: 1.7 reads per event).
        $perevent = ($readslarge - $readssmall) / 12;
        $this->assertLessThanOrEqual(
            6,
            $perevent,
            "Backfill costs $perevent reads per created event (small option: $readssmall, large option: $readslarge)."
        );
    }
}
