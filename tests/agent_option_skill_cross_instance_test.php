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
use context_module;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking\local\wizard\options\skills\book_users_skill;

/**
 * Cross-instance authorization for the booking option agent skills.
 *
 * A user privileged in booking instance A (course A) must NOT be able to drive an option skill
 * against an option that lives in instance B (course B), where they hold no capability — even
 * though the option id is supplied in the input. The native capability must be enforced at the
 * TARGET option's own module context, not the invocation context.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\update_option_skill
 * @covers \mod_booking\local\wizard\options\skills\book_users_skill
 */
final class agent_option_skill_cross_instance_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * update_option: an option in instance B is editable by a teacher of B (positive control) but
     * NOT by a teacher whose only privilege is in instance A — even though the option id is given.
     */
    public function test_update_option_blocks_cross_instance_option(): void {
        $env = $this->setup_two_instances();
        $input = ['optionid' => (int)$env['optionb']->id, 'name' => 'Renamed'];

        // Positive control: the teacher of instance B (where the option lives) is allowed.
        $this->setUser($env['teacherb']);
        $pass = (new update_option_skill())->preflight($input, (int)$env['contextb']->id, (int)$env['teacherb']->id)
            ->to_array();
        $this->assertSame('pass', $pass['status'], 'A teacher of instance B must be able to edit B\'s option.');

        // Cross-instance: the teacher of instance A, acting from A, must NOT reach B's option.
        $this->setUser($env['teachera']);
        $deny = (new update_option_skill())->preflight($input, (int)$env['contexta']->id, (int)$env['teachera']->id)
            ->to_array();
        $this->assertNotSame(
            'pass',
            $deny['status'],
            'A teacher privileged only in course A must NOT update an option in course B.'
        );
        $this->assertNotEmpty($deny['issue_codes'], 'The cross-instance denial must carry a reason code.');
    }

    /**
     * book_users: booking a user into instance B's option is allowed for a teacher of B (positive
     * control) but denied for a teacher whose only privilege is in instance A.
     */
    public function test_book_users_blocks_cross_instance_option(): void {
        $env = $this->setup_two_instances();
        $target = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($target->id, $env['courseb']->id, 'student');
        $input = ['optionid' => (int)$env['optionb']->id, 'userids' => [(int)$target->id]];

        // Positive control: the teacher of instance B is allowed (Gate 2 passes at B).
        $this->setUser($env['teacherb']);
        $pass = (new book_users_skill())->preflight($input, (int)$env['contextb']->id, (int)$env['teacherb']->id)
            ->to_array();
        $this->assertSame('pass', $pass['status'], 'A teacher of instance B must be able to book into B\'s option.');

        // Cross-instance: the teacher of instance A must NOT book into B's option.
        $this->setUser($env['teachera']);
        $deny = (new book_users_skill())->preflight($input, (int)$env['contexta']->id, (int)$env['teachera']->id)
            ->to_array();
        $this->assertNotSame(
            'pass',
            $deny['status'],
            'A teacher privileged only in course A must NOT book users into an option in course B.'
        );
        $this->assertNotEmpty($deny['issue_codes'], 'The cross-instance denial must carry a reason code.');
    }

    /**
     * Two booking instances (A, B) each with an editing teacher, plus one option living in B.
     *
     * @return array{coursea:\stdClass,courseb:\stdClass,contexta:\context_module,contextb:\context_module,optionb:\stdClass,teachera:\stdClass,teacherb:\stdClass}
     */
    private function setup_two_instances(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $gen = $this->getDataGenerator();
        /** @var \mod_booking_generator $bgen */
        $bgen = $gen->get_plugin_generator('mod_booking');

        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $bookinga = $gen->create_module('booking', ['course' => $coursea->id]);
        $bookingb = $gen->create_module('booking', ['course' => $courseb->id]);

        $optionb = $bgen->create_option([
            'bookingid' => (int)$bookingb->id,
            'text' => 'Option in B',
            'description' => 'Option in B',
            'chooseorcreatecourse' => 1,
            'courseid' => (int)$courseb->id,
            'optiondateid_0' => 0,
            'daystonotify_0' => 0,
            'coursestarttime_0' => strtotime('+2 days 10:00'),
            'courseendtime_0' => strtotime('+2 days 12:00'),
        ]);

        $teachera = $gen->create_user();
        $teacherb = $gen->create_user();
        $gen->enrol_user($teachera->id, $coursea->id, 'editingteacher');
        $gen->enrol_user($teacherb->id, $courseb->id, 'editingteacher');

        return [
            'coursea' => $coursea,
            'courseb' => $courseb,
            'contexta' => context_module::instance($bookinga->cmid),
            'contextb' => context_module::instance($bookingb->cmid),
            'optionb' => $optionb,
            'teachera' => $teachera,
            'teacherb' => $teacherb,
        ];
    }
}
