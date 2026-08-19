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
 * Tests for legacy_chain_reader_send_mail_interval (WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md
 * §3.2, M1). Unit tests against hand-built {task_adhoc} rows cover can_read()'s defensiveness;
 * the final test runs against a REAL repeat task produced by the current (pre-refactor) engine
 * via waitlist_old_chain_fixture_trait, so extract() is verified against the genuine shape, not
 * just an assumption of it.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\local\waitlist\migration\legacy_chain_reader_send_mail_interval;
use mod_booking\local\waitlist\migration\legacy_chain_state;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/tests/booking_rules/waitlist_old_chain_fixture_trait.php');

/**
 * Tests for legacy_chain_reader_send_mail_interval.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\legacy_chain_reader_send_mail_interval
 */
final class legacy_chain_reader_send_mail_interval_test extends \advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a raw {task_adhoc}-shaped stdClass, matching what upgrade_step would actually get
     * from $DB->get_recordset('task_adhoc', ...) - not a hydrated adhoc_task object.
     *
     * @param string $classname
     * @param array $customdata will be json_encode()d, matching adhoc_task::set_custom_data()
     * @param int $nextruntime
     * @return stdClass
     */
    private function make_taskrecord(string $classname, array $customdata, int $nextruntime = 1000): stdClass {
        return (object) [
            'id' => 1,
            'classname' => $classname,
            'customdata' => json_encode($customdata),
            'nextruntime' => $nextruntime,
        ];
    }

    /**
     * A row from an unrelated task class must never be recognised.
     */
    public function test_can_read_false_for_wrong_classname(): void {
        $reader = new legacy_chain_reader_send_mail_interval();
        $taskrecord = $this->make_taskrecord('\mod_booking\task\some_other_task', ['repeat' => 1]);
        $this->assertFalse($reader->can_read($taskrecord));
    }

    /**
     * The DIRECT mail task (repeat unset) is not itself a chain state - only the repeat task
     * carries the full usersalreadytreated snapshot.
     */
    public function test_can_read_false_for_direct_task_without_repeat_flag(): void {
        $reader = new legacy_chain_reader_send_mail_interval();
        $taskrecord = $this->make_taskrecord('\mod_booking\task\send_mail_by_rule_adhoc', [
            'optionid' => 1,
            'ruleid' => 1,
            'userid' => 5,
            'rulejson' => json_encode(['intervaldata' => ['usersalreadytreated' => [5]]]),
        ]);
        $this->assertFalse($reader->can_read($taskrecord));
    }

    /**
     * Malformed customdata (not valid JSON) must be handled defensively, never throw.
     */
    public function test_can_read_false_for_unparseable_customdata(): void {
        $reader = new legacy_chain_reader_send_mail_interval();
        $taskrecord = (object) [
            'classname' => '\mod_booking\task\send_mail_by_rule_adhoc',
            'customdata' => 'not valid json{{{',
            'nextruntime' => 1000,
        ];
        $this->assertFalse($reader->can_read($taskrecord));
    }

    /**
     * A repeat task whose rulejson does not (or no longer) carry intervaldata.usersalreadytreated
     * must not be misread as a valid chain state.
     */
    public function test_can_read_false_when_rulejson_has_no_intervaldata(): void {
        $reader = new legacy_chain_reader_send_mail_interval();
        $taskrecord = $this->make_taskrecord('\mod_booking\task\send_mail_by_rule_adhoc', [
            'optionid' => 1,
            'ruleid' => 1,
            'repeat' => 1,
            'rulejson' => json_encode(['name' => 'somerule']),
        ]);
        $this->assertFalse($reader->can_read($taskrecord));
    }

    /**
     * A well-formed repeat task must be recognised and extracted correctly - hand-built version,
     * covers the exact expected shape in isolation before the engine-driven test below.
     */
    public function test_can_read_true_and_extract_for_a_wellformed_repeat_task(): void {
        $reader = new legacy_chain_reader_send_mail_interval();
        $rulejson = json_encode([
            'intervaldata' => [
                'usersalreadytreated' => [11, 22, 33],
                'nextruntime' => 900,
                'interval' => 60,
            ],
        ]);
        $taskrecord = $this->make_taskrecord('\mod_booking\task\send_mail_by_rule_adhoc', [
            'optionid' => 42,
            'ruleid' => 7,
            'repeat' => 1,
            'rulejson' => $rulejson,
        ], 1234567);

        $this->assertTrue($reader->can_read($taskrecord));

        $state = $reader->extract($taskrecord);
        $this->assertInstanceOf(legacy_chain_state::class, $state);
        $this->assertEquals(42, $state->optionid);
        $this->assertEquals(7, $state->ruleid);
        $this->assertEquals([11, 22, 33], $state->usersalreadytreated);
        $this->assertEquals(1234567, $state->nextruntime);
    }

    /**
     * End-to-end against a REAL repeat task produced by the current (pre-refactor) engine via
     * the fixture trait - verifies extract() against the genuine shape, not an assumption of it.
     */
    public function test_extract_against_a_real_engine_produced_repeat_task(): void {
        global $DB;

        $chain = $this->build_running_mail_interval_chain(3);

        $taskrecord = $DB->get_record('task_adhoc', ['id' => $chain->repeattask->get_id()], '*', MUST_EXIST);

        $reader = new legacy_chain_reader_send_mail_interval();
        $this->assertTrue(
            $reader->can_read($taskrecord),
            'The reader must recognise a real repeat task produced by the current engine.'
        );

        $state = $reader->extract($taskrecord);
        $this->assertEquals((int) $chain->option->id, $state->optionid);
        $this->assertEquals((int) $chain->rule->id, $state->ruleid);
        $this->assertEquals(
            [(int) $chain->treateduser->id],
            $state->usersalreadytreated,
            'Exactly the one already-mailed user must be in usersalreadytreated.'
        );
        $this->assertEquals(
            (int) $taskrecord->nextruntime,
            $state->nextruntime,
            'nextruntime must be read from the task row itself, preserving the running deadline.'
        );
    }
}
