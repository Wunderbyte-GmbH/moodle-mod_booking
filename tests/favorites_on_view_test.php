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
 * Tests for the favorites star toggle on the tables (tabs) of view.php.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_course;
use mod_booking\output\view;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that the favorites star toggle is rendered on all tables (tabs) of view.php.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\output\view
 */
final class favorites_on_view_test extends advanced_testcase {
    /**
     * The markup of the favorites star toggle (see template actionbutton/bookingfavorite).
     */
    private const TOGGLEMARKUP = 'data-methodname="toggle_favorite"';

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
     * Create a booking instance with two visible options and one invisible option.
     *
     * @param object $course
     * @param object $bookingmanager
     * @return array [int $cmid, int $optionid1, int $optionid2, int $invisibleoptionid]
     */
    private function create_booking_with_options(object $course, object $bookingmanager): array {
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

        $record3 = new stdClass();
        $record3->bookingid = $booking->id;
        $record3->text = 'Option Gamma invisible';
        $record3->description = 'Option Gamma description';
        $record3->maxanswers = 10;
        $record3->invisible = 1;

        // A session in the future, so the options count as active (the tab checks courseendtime).
        foreach ([$record1, $record2, $record3] as $record) {
            $record->optiondateid_0 = "0";
            $record->daystonotify_0 = "0";
            $record->coursestarttime_0 = strtotime('now + 1 day');
            $record->courseendtime_0 = strtotime('now + 3 day');
        }

        $option1 = $plugingenerator->create_option($record1);
        $option2 = $plugingenerator->create_option($record2);
        $option3 = $plugingenerator->create_option($record3);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);

        return [(int)$settings1->cmid, (int)$option1->id, (int)$option2->id, (int)$option3->id];
    }

    /**
     * Set up PAGE for table rendering.
     *
     * @param object $course
     */
    private function setup_page(object $course): void {
        global $PAGE;
        $PAGE->set_context(context_course::instance($course->id));
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/favorites_on_view_test.php'));
    }

    /**
     * With the enablefavoritestoggle setting on, all tables of view.php show the favorites star.
     *
     * @covers \mod_booking\output\view::wbtable_initialize_layout
     */
    public function test_favorites_toggle_shown_on_all_view_tables(): void {
        set_config('enablefavoritestoggle', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2, $invisibleoptionid] = $this->create_booking_with_options($course, $bookingmanager);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        // Book the student on one option, so the "My bookings" table has a row.
        $plugingenerator->create_answer(['optionid' => $optionid1, 'userid' => $student->id]);
        // Mark one option as favorite, so the "My favorites" table has a row.
        set_user_preference('bookingoptionfavorites', json_encode([$optionid1]), $student->id);
        singleton_service::destroy_instance();

        $this->setUser($student);
        $this->setup_page($course);

        // The view constructed with 'shownothing' renders no tables in the constructor,
        // so each table can be rendered explicitly below.
        $view = new view($cmid, 'shownothing');

        $tables = [
            'All booking options' => $view->get_rendered_all_options_table(false),
            'Active booking options' => $view->get_rendered_active_options_table(false),
            'My bookings' => $view->get_rendered_my_booked_options_table(false),
            'My favorites' => $view->get_rendered_my_favorite_options_table(false),
            'Show only one option' => $view->get_rendered_showonlyone_table($optionid1),
        ];
        foreach ($tables as $tabname => $out) {
            $this->assertStringContainsString(
                self::TOGGLEMARKUP,
                $out,
                "Expected the favorites star toggle on the '$tabname' table."
            );
        }

        // The tabs for visible/invisible options require the canseeinvisibleoptions capability, so render as admin.
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $view = new view($cmid, 'shownothing');
        $tables = [
            'Visible options' => $view->get_rendered_visible_options_table(false),
            'Invisible options' => $view->get_rendered_invisible_options_table(false),
        ];
        foreach ($tables as $tabname => $out) {
            $this->assertStringContainsString(
                self::TOGGLEMARKUP,
                $out,
                "Expected the favorites star toggle on the '$tabname' table."
            );
        }
    }

    /**
     * With the enablefavoritestoggle setting off (default), no table of view.php shows the favorites star.
     *
     * @covers \mod_booking\output\view::wbtable_initialize_layout
     */
    public function test_favorites_toggle_hidden_when_setting_off(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        [$cmid, $optionid1, $optionid2, $invisibleoptionid] = $this->create_booking_with_options($course, $bookingmanager);

        $this->setUser($student);
        $this->setup_page($course);

        $view = new view($cmid, 'shownothing');

        $tables = [
            'All booking options' => $view->get_rendered_all_options_table(false),
            'Active booking options' => $view->get_rendered_active_options_table(false),
            'Show only one option' => $view->get_rendered_showonlyone_table($optionid1),
        ];
        foreach ($tables as $tabname => $out) {
            $this->assertStringNotContainsString(
                self::TOGGLEMARKUP,
                $out,
                "Expected no favorites star toggle on the '$tabname' table while the setting is off."
            );
        }
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking for Favorites on view.php',
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
