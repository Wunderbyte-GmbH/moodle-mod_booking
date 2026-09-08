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

namespace mod_booking;

use advanced_testcase;
use cache_helper;
use context_system;
use mod_booking\event\message_sent;
use mod_booking\table\event_log_table;
use stdClass;

/**
 * The cached event log tables (e.g. "Show messages") must reflect a sent message immediately.
 *
 * logstore_standard buffers the rows it writes and commits them only when the buffer is full or at
 * process shutdown. A cache purge issued directly after the event trigger therefore runs before the
 * row exists; a table load in between re-fills the cache without the new row and nothing purges it
 * again. booking::purge_eventlog_cache() flushes the log stores first and purges afterwards.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking::purge_eventlog_cache
 */
final class eventlog_cache_invalidation_test extends advanced_testcase {
    /**
     * Enable a buffering standard log store, like on a production site.
     */
    public function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(context_system::instance());

        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 50, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * Commit whatever is still buffered so the reset does not write to the DB behind phpunit's back.
     */
    public function tearDown(): void {
        $this->flush_log_stores();
        parent::tearDown();
    }

    /**
     * Documents the race: a bare purge_by_event() right after the trigger runs before the buffered
     * log row is committed, so a table load in between caches the table without the new message
     * and the later commit never becomes visible.
     *
     * @return void
     */
    public function test_bare_purge_runs_before_the_buffered_log_write(): void {
        $optionid = $this->create_option();
        $table = $this->build_table($optionid);

        // One committed message so that the table has something to cache (empty results are not cached).
        $this->trigger_message($optionid);
        booking::purge_eventlog_cache();
        $this->assertSame(1, $this->count_rows_in_db($optionid));
        $this->assertSame(1, $this->load_table_rows($table));

        // Second message the old way: trigger, then purge immediately.
        $this->trigger_message($optionid);
        cache_helper::purge_by_event('setbackeventlogtable');

        // The row is still sitting in the log store buffer ...
        $this->assertSame(1, $this->count_rows_in_db($optionid));
        // ... so a table load in this window re-caches the table without it.
        $this->assertSame(1, $this->load_table_rows($table));

        // The buffer is committed later (process shutdown on a real site).
        $this->flush_log_stores();
        $this->assertSame(2, $this->count_rows_in_db($optionid));

        // The cache was filled after the purge and nothing purges it again: the table stays stale.
        $this->assertSame(1, $this->load_table_rows($table));
    }

    /**
     * The helper flushes the log stores before purging, so the table is up to date right away
     * and no later load can re-cache a stale result.
     *
     * @return void
     */
    public function test_purge_eventlog_cache_flushes_before_purging(): void {
        $optionid = $this->create_option();
        $table = $this->build_table($optionid);

        $this->assertSame(0, $this->load_table_rows($table));

        $this->trigger_message($optionid);
        booking::purge_eventlog_cache();

        // The row is committed immediately ...
        $this->assertSame(1, $this->count_rows_in_db($optionid));
        // ... and the table shows it without any further purge.
        $this->assertSame(1, $this->load_table_rows($table));

        $this->trigger_message($optionid);
        booking::purge_eventlog_cache();

        $this->assertSame(2, $this->count_rows_in_db($optionid));
        $this->assertSame(2, $this->load_table_rows($table));
    }

    /**
     * Create a booking instance with one option and return the option id.
     *
     * @return int
     */
    private function create_option(): int {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option for the event log cache test';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        return (int)$option->id;
    }

    /**
     * Trigger a message_sent event for the option, like message_controller does after sending.
     *
     * @param int $optionid
     * @return void
     */
    private function trigger_message(int $optionid): void {
        global $USER;

        $event = message_sent::create([
            'context' => context_system::instance(),
            'userid' => $USER->id,
            'relateduserid' => $USER->id,
            'objectid' => $optionid,
            'other' => [
                'messageparam' => 0,
                'subject' => 'Event log cache test',
                'objectid' => $optionid,
                'message' => 'Event log cache test',
                'messagehtml' => '',
                'bookingruleid' => null,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Commit the buffered log rows, as the log manager does at process shutdown.
     *
     * @return void
     */
    private function flush_log_stores(): void {
        foreach (get_log_manager()->get_readers() as $store) {
            if (method_exists($store, 'flush')) {
                $store->flush();
            }
        }
    }

    /**
     * Count the message_sent rows of the option in the standard log.
     *
     * @param int $optionid
     * @return int
     */
    private function count_rows_in_db(int $optionid): int {
        global $DB;

        return $DB->count_records('logstore_standard_log', [
            'eventname' => '\\mod_booking\\event\\message_sent',
            'objectid' => $optionid,
        ]);
    }

    /**
     * Build the "Show messages" table of the option exactly like the eventslist output class does.
     * The SQL (and with it the cache key) is built once per page, the ajax loads reuse it.
     *
     * @param int $optionid
     * @return event_log_table
     */
    private function build_table(int $optionid): event_log_table {
        $eventnames = ['\mod_booking\event\message_sent'];

        [$select, $from, $where, $filter, $params] =
            booking::return_sql_for_event_logs('mod_booking', $eventnames, $optionid, 0);

        $tablename = md5("eventlogtable" . $optionid . implode('-', $eventnames) . '0');
        $table = new event_log_table($tablename);
        $table->set_filter_sql($select, $from, $where, $filter, $params);

        $columns = [
            'relateduserid' => get_string('messagerecipient', 'mod_booking'),
            'eventname' => get_string('eventname', 'core'),
            'description' => get_string('description', 'core'),
            'timecreated' => get_string('timecreated', 'core'),
        ];
        $table->define_columns(array_keys($columns));
        $table->define_headers(array_values($columns));
        $table->define_sortablecolumns(array_keys($columns));
        $table->sort_default_column = 'timecreated';
        $table->sort_default_order = SORT_DESC;
        $table->define_cache('mod_booking', 'eventlogtable');
        $table->pageable(true);
        $table->setup();

        return $table;
    }

    /**
     * Load the rows through the cached table query on a copy of the table, like every ajax load of the
     * lazy table does on the table restored from the encoded-table cache.
     *
     * @param event_log_table $table
     * @return int
     */
    private function load_table_rows(event_log_table $table): int {
        // Shallow copy plus a copy of the sql object: the cached query appends the filter to the where
        // clause, and a table restored from the encoded-table cache starts from the untouched sql as well.
        $table = clone $table;
        $table->sql = clone $table->sql;
        $table->query_db_cached(20);

        return count($table->rawdata);
    }
}
