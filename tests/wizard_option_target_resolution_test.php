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
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Option-to-activity target resolution must never manufacture uniqueness across instances.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support
 */
final class wizard_option_target_resolution_test extends booking_advanced_testcase {
    /** @var int cmid of instance B (holds the exact-titled options). */
    private int $cmidb = 0;

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
        $this->cmidb = (int)$bookingb->cmid;

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        // Ambient room A: a substring-only match. Foreign room B: two exact-titled matches.
        $gen->create_option(['bookingid' => (int)$bookinga->id, 'text' => 'Pilates am Abend (Baseline)',
            'maxanswers' => 5, 'type' => 0]);
        $gen->create_option(['bookingid' => (int)$bookingb->id, 'text' => 'PILATES', 'maxanswers' => 5, 'type' => 0]);
        $gen->create_option(['bookingid' => (int)$bookingb->id, 'text' => 'PILATES', 'maxanswers' => 5, 'type' => 0]);
        $gen->create_option(['bookingid' => (int)$bookingb->id, 'text' => 'Nur B Kurs', 'maxanswers' => 5, 'type' => 0]);
    }

    /**
     * "pilates" matches exactly in B and partially in A: two owning activities exist, so the
     * resolver must report ambiguity — never a manufactured unique hit on the foreign room.
     */
    public function test_cross_instance_title_matches_are_ambiguous(): void {
        $result = booking_skill_support::activity_for_option_query('pilates');

        $this->assertSame('ambiguous', (string)($result['status'] ?? ''), json_encode($result));
    }

    /**
     * Same constellation through the skill's target selector: no selector may be pinned to
     * the foreign activity (null keeps the ambient context in charge).
     */
    public function test_target_selector_does_not_pin_the_foreign_activity(): void {
        $selector = (new diagnose_cancellation_issue_skill())->get_target_selector(['optionquery' => 'pilates']);

        $this->assertNull($selector, 'an ambiguous cross-instance match must not produce a pinned selector');
    }

    /**
     * Feature guard: a name existing in exactly one activity still resolves to it site-wide.
     */
    public function test_unique_name_still_resolves_cross_context(): void {
        $result = booking_skill_support::activity_for_option_query('Nur B Kurs');

        $this->assertSame('ok', (string)($result['status'] ?? ''));
        $this->assertSame($this->cmidb, (int)($result['cmid'] ?? 0));
    }
}
