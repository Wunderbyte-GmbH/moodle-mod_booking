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
use mod_booking\local\wizard\options\skills\get_option_details_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The instance boundary stays, but the refusal names the activity that owns the option (wave 32, GOD-4).
 *
 * Threads 12109 and 12856: "For option 88, what dates are scheduled" in activity A, #88 lives in activity B. The skill
 * refused correctly but named no activity, so the answer asked "which booking activity contains option 88?" - a fact
 * the database holds.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\get_option_details_skill
 */
final class wizard_option_details_names_owning_activity_test extends booking_advanced_testcase {
    /**
     * An option of another activity: refused (recoverable), with the owner's name and link.
     */
    public function test_refusal_names_the_owning_activity(): void {
        global $USER, $PAGE;
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $here = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Here Activity', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $there = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'There Activity', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $option = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option([
            'bookingid' => (int)$there->id, 'text' => 'Elsewhere', 'maxanswers' => 5, 'type' => 0,
        ]);
        $PAGE->set_url('/mod/booking/view.php', ['id' => (int)$here->cmid]);

        $result = (new get_option_details_skill())->execute(
            ['optionid' => (int)$option->id],
            (int)context_module::instance((int)$here->cmid)->id,
            (int)$USER->id
        );

        $this->assertSame('error', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
        $this->assertEmpty($result['optiondetails'] ?? [], 'the boundary stays: no details of the other activity');
        $this->assertStringContainsString('There Activity', (string)$result['detail']);
        $this->assertStringContainsString('view.php?id=' . (int)$there->cmid, (string)$result['detail']);
    }

    /**
     * Review w32s-b1: a user outside the owning course learns neither its activity name nor its link; the refusal stays
     * recoverable.
     */
    public function test_refusal_hides_an_owner_the_user_cannot_reach(): void {
        global $PAGE;
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $here = $this->getDataGenerator()->create_module('booking', [
            'course' => $mine->id, 'name' => 'Here Activity', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $there = $this->getDataGenerator()->create_module('booking', [
            'course' => $other->id, 'name' => 'Closed Activity', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $option = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option([
            'bookingid' => (int)$there->id, 'text' => 'Elsewhere', 'maxanswers' => 5, 'type' => 0,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$mine->id, 'student');
        $this->setUser($student);
        $PAGE->set_url('/mod/booking/view.php', ['id' => (int)$here->cmid]);

        $result = (new get_option_details_skill())->execute(
            ['optionid' => (int)$option->id],
            (int)context_module::instance((int)$here->cmid)->id,
            (int)$student->id
        );

        $this->assertSame('error', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
        $this->assertStringNotContainsString('Closed Activity', (string)$result['detail']);
        $this->assertStringNotContainsString('view.php?id=' . (int)$there->cmid, (string)$result['detail']);
    }
}
