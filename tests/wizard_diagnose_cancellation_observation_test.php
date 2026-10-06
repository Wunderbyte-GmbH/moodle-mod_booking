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
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The cancellation diagnosis hands the model its facts and all findings, uncut.
 *
 * Without observation_full the generic summary renders the findings only and cuts them at 220
 * characters; the deadline, cooling-off and cancancelbook facts never reach the model.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill
 */
final class wizard_diagnose_cancellation_observation_test extends booking_advanced_testcase {
    /** @var int Module context id. */
    private int $contextid = 0;

    /** @var int The option. */
    private int $optionid = 0;

    /** @var \stdClass The booked student. */
    private \stdClass $student;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('coolingoffperiod', 0, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Cancel diag', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
            'cancancelbook' => 1,
        ]);
        $this->contextid = (int)context_module::instance((int)$booking->cmid)->id;

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $this->optionid = (int)$gen->create_option([
            'bookingid' => (int)$booking->id, 'text' => 'Pilates am Abend', 'maxanswers' => 10, 'type' => 0,
        ])->id;

        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $gen->create_answer(['optionid' => $this->optionid, 'userid' => (int)$this->student->id]);
        singleton_service::destroy_instance();
    }

    /**
     * Run the skill as the student for the seeded option.
     *
     * @return array
     */
    private function run_skill(): array {
        $this->setUser($this->student);
        return (new diagnose_cancellation_issue_skill())->execute(
            ['optionid' => $this->optionid],
            $this->contextid,
            (int)$this->student->id
        );
    }

    /**
     * Set the option's own cancellation deadline.
     *
     * @param int $timestamp
     */
    private function set_option_deadline(int $timestamp): void {
        global $DB;
        $json = json_decode((string)$DB->get_field('booking_options', 'json', ['id' => $this->optionid]) ?: '{}');
        $json->canceluntil = $timestamp;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $this->optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($this->optionid);
        singleton_service::destroy_booking_option_singleton($this->optionid);
    }

    /**
     * A passed deadline: the observation carries the facts first, then every finding, uncut.
     */
    public function test_observation_carries_facts_and_all_findings(): void {
        $deadline = time() - DAYSECS;
        $this->set_option_deadline($deadline);

        $result = $this->run_skill();

        $this->assertSame('executed', $result['status']);
        $reasons = (array)$result['diagnosis']['reasons'];
        $this->assertGreaterThanOrEqual(2, count($reasons), 'a passed deadline yields the finding and its concrete line');

        $observation = (string)($result['observation_full'] ?? '');
        $this->assertStringStartsWith('Diagnosis for option "Pilates am Abend" (issue: cannot_cancel).', $observation);
        $this->assertStringContainsString('User booking status: booked.', $observation);
        $this->assertStringContainsString('cancancelbook = 1', $observation);
        $this->assertStringContainsString('option deadline: ' . userdate($deadline) . ' (passed)', $observation);
        $this->assertStringContainsString('cooling-off: not active', $observation);
        foreach ($reasons as $reason) {
            $this->assertStringContainsString('- ' . $reason, $observation, 'every finding reaches the model, uncut');
        }
        $this->assertGreaterThan(220, strlen($observation));

        // The result for card and preview is as before.
        $this->assertSame([$this->optionid], $result['previewoptionids']);
        $this->assertArrayHasKey('stats', $result['diagnosis']);
        $this->assertArrayNotHasKey('reply_requirements', $result['diagnosis']['stats']);
    }

    /**
     * No blocker: the facts still say what applies, so the model can state until when one may cancel.
     */
    public function test_observation_without_blocker_states_the_facts(): void {
        $deadline = time() + (3 * DAYSECS);
        $this->set_option_deadline($deadline);

        $result = $this->run_skill();

        $observation = (string)($result['observation_full'] ?? '');
        $this->assertStringContainsString('option deadline: ' . userdate($deadline) . ' (open)', $observation);
        $this->assertStringContainsString('cancancelbook = 1', $observation);
        $this->assertStringContainsString('disablecancel: option no, instance no', $observation);
        $this->assertStringContainsString('Findings:', $observation);
    }
}
