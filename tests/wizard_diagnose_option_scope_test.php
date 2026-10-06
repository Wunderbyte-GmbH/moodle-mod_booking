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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_user_booking_skill;
use mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The diagnose family must never resolve or report options of another instance.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 */
final class wizard_diagnose_option_scope_test extends booking_advanced_testcase {
    /** @var int Module context id of instance A (the acting context). */
    private int $contexta = 0;

    /** @var int Option id living ONLY in instance B. */
    private int $foreignoptionid = 0;

    /** @var \stdClass Enrolled target user. */
    private \stdClass $student;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $bookinga = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Instance A', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $bookingb = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Instance B', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->contexta = (int)context_module::instance((int)$bookinga->cmid)->id;

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $foreign = $gen->create_option([
            'bookingid' => (int)$bookingb->id,
            'text' => 'Fremde Option',
            'maxanswers' => 5,
            'type' => 0,
        ]);
        $this->foreignoptionid = (int)$foreign->id;

        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
    }

    /**
     * Regression guard: an explicit foreign optionid must not produce an
     * option-scoped report — the skill degrades to the instance-wide view.
     */
    public function test_user_booking_ignores_foreign_optionid(): void {
        $result = (new diagnose_user_booking_skill())->execute([
            'userid' => (int)$this->student->id,
            'optionid' => $this->foreignoptionid,
        ], $this->contexta, (int)get_admin()->id);

        $this->assertStringNotContainsString(
            'Fremde Option',
            json_encode($result, JSON_UNESCAPED_UNICODE),
            'a foreign optionid must never surface another instance\'s option in the report'
        );
    }

    /**
     * The sibling diagnose skills reject a foreign optionid without leaking foreign data.
     */
    public function test_sibling_diagnose_skills_reject_foreign_optionid(): void {
        $skills = [
            new diagnose_cancellation_issue_skill(),
            new diagnose_waitinglist_skill(),
            new diagnose_booking_issue_skill(),
        ];

        foreach ($skills as $skill) {
            $result = $skill->execute(
                ['optionid' => $this->foreignoptionid],
                $this->contexta,
                (int)get_admin()->id
            );

            $this->assertNotSame('executed', (string)($result['status'] ?? ''), get_class($skill));
            $this->assertStringNotContainsString(
                'Fremde Option',
                json_encode($result, JSON_UNESCAPED_UNICODE),
                get_class($skill) . ' must not leak foreign option data'
            );
        }
    }
}
