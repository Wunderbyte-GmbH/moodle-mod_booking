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
 * Tests for the column "teacher" of the tables of booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use core\url;
use mod_booking\singleton_service;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;

/**
 * The teachers of a booking option are shown as one list: one icon, then the names separated by ", ".
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\output\col_teacher
 * @covers \mod_booking\output\renderer::render_col_teacher
 */
final class col_teacher_test extends booking_advanced_testcase {
    /**
     * Renders the column "teacher" of a new booking option with the given teachers.
     *
     * @param array $teachers list of [firstname, lastname]
     * @return array [col_teacher, rendered HTML]
     */
    private function render_column(array $teachers): array {
        global $PAGE;

        $this->setAdminUser();
        $PAGE->set_url(new url('/mod/booking/tests/output/col_teacher_test.php'));

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Teacher column booking',
            'course' => $course->id,
            'bookingmanager' => $this->getDataGenerator()->create_user()->username,
        ]);
        $usernames = [];
        foreach ($teachers as [$firstname, $lastname]) {
            $usernames[] = $this->getDataGenerator()->create_user([
                'firstname' => $firstname,
                'lastname' => $lastname,
            ])->username;
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option with teachers',
            'teachersforoption' => implode(',', $usernames),
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $data = new col_teacher($option->id, $settings);
        /** @var renderer $output */
        $output = $PAGE->get_renderer('mod_booking');
        return [$data, $output->render_col_teacher($data)];
    }

    /**
     * Several teachers: one icon for all of them and ", " (comma and space) between the teachers.
     */
    public function test_one_icon_and_commas_for_several_teachers(): void {
        [$data, $html] = $this->render_column([['Carl', 'Adler'], ['Bea', 'Maier'], ['Anna', 'Zimmer']]);

        $this->assertSame([false, false, true], array_column($data->teachers, 'last'));
        $this->assertSame(1, substr_count($html, 'fa-graduation-cap'), 'There is one icon, not one per teacher.');

        // The text of the column without the markup: whitespace is collapsed to one space, like the browser does.
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertSame('Carl Adler, Bea Maier, Anna Zimmer', $text);
        // The space must not get lost: a flex container would drop the whitespace between the teachers.
        $this->assertStringNotContainsString('flex-wrap-container', $html);
    }

    /**
     * A single teacher has no comma.
     */
    public function test_single_teacher_has_no_comma(): void {
        [$data, $html] = $this->render_column([['Carl', 'Adler']]);

        $this->assertSame([true], array_column($data->teachers, 'last'));
        $this->assertSame(1, substr_count($html, 'fa-graduation-cap'));
        $this->assertSame('Carl Adler', trim(preg_replace('/\s+/', ' ', strip_tags($html))));
    }

    /**
     * A booking option without teachers shows nothing, also no icon.
     */
    public function test_no_teachers_render_nothing(): void {
        [$data, $html] = $this->render_column([]);

        $this->assertSame([], $data->teachers);
        $this->assertSame('', trim($html));
    }
}
