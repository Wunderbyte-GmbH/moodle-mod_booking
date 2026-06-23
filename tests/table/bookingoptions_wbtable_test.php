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
 * Tests for bookingoptions_wbtable col_text link behaviour.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Ala-Flucher
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\table\bookingoptions_wbtable;

/**
 * Tests for the title-column link rendering based on openbookingdetailinsametab config.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookingoptions_wbtable_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $PAGE->set_url('/mod/booking/view.php');
        $this->setAdminUser();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Creates a minimal booking option and returns it.
     *
     * @return \stdClass the created option record
     */
    private function create_option(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Test Booking',
            'eventtype' => 'Test',
            'bookingmanager' => $teacher->username,
        ]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        return $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test Booking Option',
            'courseid' => $course->id,
        ]);
    }

    /**
     * Verifies that col_text renders the correct link style for each openbookingdetailinsametab value.
     *
     * @dataProvider col_text_config_provider
     * @covers \mod_booking\table\bookingoptions_wbtable::col_text
     * @param int $configvalue The openbookingdetailinsametab config value.
     * @param string[] $contains Strings the output must contain.
     * @param string[] $notcontains Strings the output must not contain.
     */
    public function test_col_text_link_behaviour(int $configvalue, array $contains, array $notcontains): void {
        set_config('openbookingdetailinsametab', $configvalue, 'booking');

        $option = $this->create_option();
        $table = new bookingoptions_wbtable("test_col_text_{$configvalue}");
        $values = (object)['id' => $option->id, 'text' => $option->text];

        $result = $table->col_text($values);

        $this->assertStringContainsString("class='bookingoptions-wbtable-option-title'", $result);
        foreach ($contains as $needle) {
            $this->assertStringContainsString($needle, $result);
        }
        foreach ($notcontains as $needle) {
            $this->assertStringNotContainsString($needle, $result);
        }
    }

    /**
     * Data provider for test_col_text_link_behaviour.
     *
     * @return array
     */
    public static function col_text_config_provider(): array {
        return [
            'new_tab'  => [0, ['<a href=', "target='_blank'"], []],
            'same_tab' => [1, ['<a href='], ['target=']],
            'no_link'  => [2, [], ['<a ']],
        ];
    }
}
