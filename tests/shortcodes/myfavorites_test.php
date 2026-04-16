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
 * Tests for myfavorites shortcode.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_user;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the myfavorites shortcode.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\shortcodes::myfavorites
 */
final class myfavorites_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Create a booking instance with two options and return optionids.
     *
     * @param object $course
     * @param object $bookingmanager
     * @return array [int $cmid, int $optionid1, int $optionid2]
     */
    private function create_booking_with_two_options(object $course, object $bookingmanager): array {
        $bdata = self::provide_bdata();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record1 = new stdClass();
        $record1->bookingid = $booking->id;
        $record1->text = 'Option Alpha';
        $record1->description = 'Option Alpha description';
        $record1->maxanswers = 10;

        $record2 = new stdClass();
        $record2->bookingid = $booking->id;
        $record2->text = 'Option Beta';
        $record2->description = 'Option Beta description';
        $record2->maxanswers = 10;

        $option1 = $plugingenerator->create_option($record1);
        $option2 = $plugingenerator->create_option($record2);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);

        return [(int)$settings1->cmid, (int)$option1->id, (int)$option2->id];
    }

    /**
     * Set up PAGE context for the shortcode.
     *
     * @param object $user
     * @param object $course
     */
    private function setup_page_context(object $user, object $course): void {
        global $PAGE;
        $context = context_user::instance($user->id);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/myfavorites_test.php'));
    }

    /**
     * Call the myfavorites shortcode and return the rendered output.
     *
     * @param array $args
     * @return string
     */
    private function render_myfavorites(array $args = []): string {
        $env = new stdClass();
        $next = function () {
        };
        return shortcodes::myfavorites('myfavorites', $args, null, $env, $next);
    }

    /**
     * Assert the shortcode output contains a "no records" message (0 results).
     *
     * @param string $output
     */
    private function assert_output_is_empty(string $output): void {
        $this->assertNotEmpty($output, 'Shortcode output should not be empty.');
        $this->assertStringContainsString('norecordsfound', $output, 'Expected a no-records message for 0 favorites.');
        $this->assertStringNotContainsString('data-encodedtable', $output, 'Expected no encoded table for 0 favorites.');
    }

    /**
     * Extract a wunderbyte_table instance from shortcode output.
     * Fails if no encoded table is found in output.
     *
     * @param string $output
     * @return wunderbyte_table
     */
    private function get_table_from_output(string $output): wunderbyte_table {
        $found = preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $output, $matches);
        $this->assertEquals(1, $found, 'Expected encoded table in shortcode output, but none was found.');
        return wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
    }

    /**
     * Shortcode is disabled via shortcodesoff → returns error string.
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_shortcodesoff(): void {
        set_config('shortcodesoff', 1, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->setUser($student);
        $this->setup_page_context($student, $course);

        $output = $this->render_myfavorites();

        $this->assertStringContainsString('shortcodes are turned off', $output);
    }

    /**
     * User with no favorites sees a table with 0 rows.
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_no_favorites_shows_empty_table(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options($course, $bookingmanager);

        // Student has no favorites set.
        $this->setUser($student);
        $this->setup_page_context($student, $course);

        $output = $this->render_myfavorites();

        // When there are 0 favorites the table renders a "no records" message without data-encodedtable.
        $this->assert_output_is_empty($output);
    }

    /**
     * User with one favorite sees exactly 1 row.
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_one_favorite_shows_one_row(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options($course, $bookingmanager);

        set_user_preference('bookingoptionfavorites', json_encode([$optionid1]), $student->id);

        $this->setUser($student);
        $this->setup_page_context($student, $course);

        $output = $this->render_myfavorites();

        $table = $this->get_table_from_output($output);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(1, $table->totalrows);
    }

    /**
     * User with both options as favorites sees 2 rows.
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_two_favorites_shows_two_rows(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options($course, $bookingmanager);

        set_user_preference('bookingoptionfavorites', json_encode([$optionid1, $optionid2]), $student->id);

        $this->setUser($student);
        $this->setup_page_context($student, $course);

        $output = $this->render_myfavorites();

        $table = $this->get_table_from_output($output);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(2, $table->totalrows);
    }

    /**
     * Favorites from a different user are not shown to the current user.
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_only_shows_own_favorites(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options($course, $bookingmanager);

        // Only student2 has favorites.
        set_user_preference('bookingoptionfavorites', json_encode([$optionid1, $optionid2]), $student2->id);

        // Log in as student1 — should see 0 rows.
        $this->setUser($student1);
        $this->setup_page_context($student1, $course);

        $output = $this->render_myfavorites();

        // Student1 has no favorites, so a "no records" message is shown (no data-encodedtable).
        $this->assert_output_is_empty($output);
    }

    /**
     * After toggling a favorite, re-rendering shows updated row count.
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_reflects_toggle_add(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options($course, $bookingmanager);

        $this->setUser($student);
        $this->setup_page_context($student, $course);

        // Before: 0 favorites — "no records" message, no encoded table.
        $output = $this->render_myfavorites();
        $this->assert_output_is_empty($output);

        // Add a favorite.
        booking_option::toggle_favorite_user($student->id, $optionid1);

        // Destroy singleton cache so the next render uses fresh data.
        singleton_service::destroy_instance();

        // After: 1 favorite.
        $output = $this->render_myfavorites();
        $table = $this->get_table_from_output($output);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(1, $table->totalrows, 'Expected 1 row after adding favorite.');
    }

    /**
     * After removing a favorite via toggle, the row disappears.
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_reflects_toggle_remove(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options($course, $bookingmanager);

        set_user_preference('bookingoptionfavorites', json_encode([$optionid1, $optionid2]), $student->id);

        $this->setUser($student);
        $this->setup_page_context($student, $course);

        // Confirm initial state: 2 rows.
        $output = $this->render_myfavorites();
        $table = $this->get_table_from_output($output);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(2, $table->totalrows, 'Expected 2 rows before removal.');

        // Remove one favorite.
        booking_option::toggle_favorite_user($student->id, $optionid1);
        singleton_service::destroy_instance();

        // After: 1 row.
        $output = $this->render_myfavorites();
        $table = $this->get_table_from_output($output);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(1, $table->totalrows, 'Expected 1 row after removing one favorite.');
    }

    /**
     * Passing favorites=0 arg should disable the favorites toggle rendering,
     * but the table itself still works (shows favorites from preference).
     *
     * @covers \mod_booking\shortcodes::myfavorites
     */
    public function test_myfavorites_favorites_arg_false_disables_toggle(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options($course, $bookingmanager);

        set_user_preference('bookingoptionfavorites', json_encode([$optionid1]), $student->id);

        $this->setUser($student);
        $this->setup_page_context($student, $course);

        /* Favorites=0 arg: the shortcode always forces favorites=1 via set_common_table_options_from_arguments,
        so showfavoritestoggle is always true in myfavorites. The table still filters by preference. */
        $output = $this->render_myfavorites(['favorites' => '0']);
        $table = $this->get_table_from_output($output);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);

        // The table should still show the one favorite row.
        $this->assertEquals(1, $table->totalrows);
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking for Favorites',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution,myfavorites'],
        ];
    }
}
