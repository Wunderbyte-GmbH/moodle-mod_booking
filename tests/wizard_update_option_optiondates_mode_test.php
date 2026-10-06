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
use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\skill_catalog_discovery;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking_generator;
use stdClass;

/**
 * Moving the single date of an option replaces it; appending stays explicit; date writes are reported.
 *
 * Write-path baseline W1 (2026-09-15, finding W1, Wunderbyte-GmbH#2415, thread 1634 UO-1 "one week later"):
 * the option got a second session next to the old one because optiondatesmode defaulted to append, and
 * the summary said "Saved: none" although the dates were written.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_mutation_execute_service
 */
final class wizard_update_option_optiondates_mode_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var int Course-module id. */
    private int $cmid = 0;

    /** @var int Booking instance id. */
    private int $bookingid = 0;

    /**
     * Setup.
     */
    public function setUp(): void {
        $this->skip_without_agent_extension();
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Optiondates mode booking',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->bookingid = (int)$booking->id;
    }

    /**
     * Create an option with the given sessions (start timestamps, two hours each).
     *
     * @param int[] $starts Session start timestamps.
     * @return int Option id.
     */
    private function create_option_with_sessions(array $starts): int {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $this->bookingid;
        $record->text = 'Nähcafé';
        $record->importing = 1;
        foreach (array_values($starts) as $index => $start) {
            $record->{'optiondateid_' . $index} = 0;
            $record->{'coursestarttime_' . $index} = $start;
            $record->{'courseendtime_' . $index} = $start + 2 * HOURSECS;
        }
        $optionid = (int)$plugingenerator->create_option($record)->id;
        singleton_service::destroy_instance();
        return $optionid;
    }

    /**
     * Run update_option through the execute service.
     *
     * @param array $input Skill input.
     * @return array Result.
     */
    private function update(array $input): array {
        global $USER;
        $service = new booking_skill_mutation_execute_service($this->attachment_token_service());
        $result = $service->execute(update_option_skill::TASK_NAME, $input, $this->cmid, (int)$USER->id, $this->skill_support());
        singleton_service::destroy_booking_option_singleton((int)$input['optionid']);
        return $result;
    }

    /**
     * Session start timestamps of an option, sorted.
     *
     * @param int $optionid Option id.
     * @return int[]
     */
    private function session_starts(int $optionid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $starts = array_map(static fn($session) => (int)$session->coursestarttime, array_values((array)$settings->sessions));
        sort($starts);
        return $starts;
    }

    /**
     * (a) one existing session + one new date without a mode: the date is moved, not appended.
     */
    public function test_single_date_without_mode_replaces_the_session(): void {
        $old = strtotime('2046-09-30 18:00');
        $optionid = $this->create_option_with_sessions([$old]);
        $new = ['coursestarttime' => '2046-10-07 18:00', 'courseendtime' => '2046-10-07 20:00'];
        $expected = (int)booking_skill_support::extract_optiondates(['optiondates' => [$new]])[0]['coursestarttime'];

        $result = $this->update(['optionid' => $optionid, 'optiondates' => [$new]]);

        $this->assertSame([$expected], $this->session_starts($optionid), json_encode($result));
        // Finding (d): the summary names the date field as written.
        $this->assertContains('optiondates', (array)($result['persisted_fields'] ?? []), json_encode($result));
    }

    /**
     * (b) an explicit append keeps both sessions.
     */
    public function test_explicit_append_keeps_both_sessions(): void {
        $old = strtotime('2046-09-30 18:00');
        $optionid = $this->create_option_with_sessions([$old]);
        $new = ['coursestarttime' => '2046-10-07 18:00', 'courseendtime' => '2046-10-07 20:00'];
        $expected = (int)booking_skill_support::extract_optiondates(['optiondates' => [$new]])[0]['coursestarttime'];

        $this->update(['optionid' => $optionid, 'optiondates' => [$new], 'optiondatesmode' => 'append']);

        $this->assertSame([$old, $expected], $this->session_starts($optionid));
    }

    /**
     * (c) a multi-session option keeps append as the default for one more date.
     */
    public function test_multi_session_option_appends_without_mode(): void {
        $first = strtotime('2046-09-30 18:00');
        $second = strtotime('2046-10-07 18:00');
        $optionid = $this->create_option_with_sessions([$first, $second]);
        $new = ['coursestarttime' => '2046-10-14 18:00', 'courseendtime' => '2046-10-14 20:00'];
        $expected = (int)booking_skill_support::extract_optiondates(['optiondates' => [$new]])[0]['coursestarttime'];

        $this->update(['optionid' => $optionid, 'optiondates' => [$new]]);

        $this->assertSame([$first, $second, $expected], $this->session_starts($optionid));
    }

    /**
     * Booking skill support wired with the active engine's services.
     *
     * @return booking_skill_support
     */
    private function skill_support(): booking_skill_support {
        return new booking_skill_support(
            $this->attachment_token_service(),
            $this->thread_memory(),
            new skill_catalog_discovery()
        );
    }

    /**
     * Attachment token service of the active engine.
     *
     * @return object
     */
    private function attachment_token_service(): object {
        $class = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\attachment\\attachment_token_service');
        return new $class();
    }

    /**
     * Conversation thread memory of the active engine.
     *
     * @return object
     */
    private function thread_memory(): object {
        $class = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\conversation_thread_memory');
        return new $class();
    }
}
