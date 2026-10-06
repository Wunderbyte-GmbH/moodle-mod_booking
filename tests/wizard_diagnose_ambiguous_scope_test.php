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
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Diagnose skills list candidate activities for an ambiguous option (#2334 residual, Lauf 2).
 *
 * An option existing in SEVERAL activities left the trait ambient; at a non-module ambient
 * (MCP = system) the diagnose family then showed the generic "no booking activity in the
 * current context" — a dead end although the way out (name one of the candidate
 * activities) was known. The scope helper now answers option-aware.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\booking_skill_base
 */
final class wizard_diagnose_ambiguous_scope_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Seed one option title into a fresh booking activity.
     *
     * @param string $title
     * @return void
     */
    private function seed_option_in_new_activity(string $title): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Akt ' . $title . ' ' . $course->id,
            'eventtype' => 'W', 'bookingmanager' => 'admin',
        ]);
        $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id, 'text' => $title, 'description' => '', 'descriptionformat' => 1,
            'maxanswers' => 5, 'type' => 0, 'identifier' => uniqid(),
            'coursestarttime' => time() + DAYSECS, 'courseendtime' => time() + DAYSECS + HOURSECS,
            'timemodified' => time(),
        ]);
    }

    /**
     * Ambiguous option at a non-module ambient: the reply lists candidates, never the
     * generic open-an-activity text.
     */
    public function test_ambiguous_option_lists_candidates(): void {
        $this->seed_option_in_new_activity('Pilates L2R');
        $this->seed_option_in_new_activity('Pilates L2R');

        $result = (new diagnose_cancellation_issue_skill())->execute(
            ['optionquery' => 'Pilates L2R', 'question' => 'kein Storno-Knopf'],
            (int)\context_system::instance()->id,
            (int)get_admin()->id
        );

        $message = json_encode($result);
        $this->assertStringNotContainsString(
            'no booking activity in the current context',
            strtolower($message),
            'the generic scope text is a dead end when candidates are known'
        );
        $this->assertStringContainsString(
            'Pilates L2R',
            $message,
            'the reply must show the ambiguous option with its candidate activities'
        );
    }
}
