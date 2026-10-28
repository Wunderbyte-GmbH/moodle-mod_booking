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
use mod_booking\local\wizard\options\skills\search_options_skill;

/**
 * The option search carries the seat figures of every option as facts, so a question about fill level or
 * utilization is answered from the list (Wunderbyte-GmbH#2617).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\search_options_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_search_options_seat_figures_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Two options of a family: one with 2 of 5 seats taken, one without a seat limit; the observation says so.
     */
    public function test_observation_carries_booked_and_free_seats(): void {
        global $USER;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Seats booking']);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $limited = $gen->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Kochkurs Basis',
            'chooseorcreatecourse' => 0,
            'limitanswers' => 1,
            'maxanswers' => 5,
            'maxoverbooking' => 3,
        ]);
        $open = $gen->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Kochkurs Vegan',
            'chooseorcreatecourse' => 0,
            'limitanswers' => 0,
            'maxanswers' => 0,
        ]);
        foreach ([1, 2] as $i) {
            $user = $this->getDataGenerator()->create_user();
            $gen->create_answer(['optionid' => (int)$limited->id, 'userid' => (int)$user->id]);
        }
        singleton_service::destroy_booking_answers((int)$limited->id);

        $result = (new search_options_skill())->execute(
            ['query' => 'Kochkurs'],
            (int)context_module::instance($booking->cmid)->id,
            (int)$USER->id
        );

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $byname = [];
        foreach ($result['options'] as $option) {
            $byname[$option['name']] = $option;
        }
        $this->assertSame(2, $byname['Kochkurs Basis']['booked']);
        $this->assertSame(5, $byname['Kochkurs Basis']['maxanswers']);
        $this->assertSame(3, $byname['Kochkurs Basis']['free']);
        $this->assertSame(0, $byname['Kochkurs Basis']['waiting']);
        $this->assertSame(0, $byname['Kochkurs Vegan']['booked']);
        $this->assertSame(0, $byname['Kochkurs Vegan']['maxanswers'], 'no seat limit is 0');
        $this->assertNull($byname['Kochkurs Vegan']['free']);

        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('"booked": 2', $observation);
        $this->assertStringContainsString('"maxanswers": 5', $observation);
        $this->assertStringContainsString('"free": 3', $observation);
    }

    /**
     * The card speaks of seats and the report card fences the look-up, both within the caps.
     */
    public function test_cards_name_seats_and_fence_the_report(): void {
        $schema = (new search_options_skill())->get_schema();
        $this->assertStringContainsString('seats', (string)$schema['when']);
        $this->assertStringContainsString('seats', (string)$schema['is']);
        $this->assertLessThanOrEqual(180, \core_text::strlen((string)$schema['when']));
        $this->assertLessThanOrEqual(160, \core_text::strlen((string)$schema['is']));
    }
}
