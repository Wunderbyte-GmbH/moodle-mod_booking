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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use context_system;
use local_wunderbyte_table\filters\types\customfieldfilter;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Class handling tests for bookinghistory.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class bookingoption_filter_test extends advanced_testcase {

    public function test_customfieldfilter_on_booking_options() {

        // Create custom field category.
        $categorydata = new \stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        // Create some custom fields.
        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();
        $fielddata = new \stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Textfield';
        $fielddata->shortname = 'customcat';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        // Create a course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create booking manager.
        $bookingmanager = $this->getDataGenerator()->create_user();

        // Create a booking module inside the course.
        $bdata = self::provide_bdata();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $cmids = [];
        // Create some booking options with customfileds.
        foreach ($bdata['standardbookingoptions'] as $option) {
            $record = (object) $option;
            $record->bookingid = $booking->id;
            /** @var mod_booking_generator $plugingenerator */
            $option1 = $plugingenerator->create_option($record);
            $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
            $cmids[$settings->cmid] = $settings->cmid;
        }

        // Then try to to filter them by custom field using customfiedlfilter.
        // Create the table.
        $table = new bookingoptions_wbtable("cmid_{$cmid}_showonetable");

        $customfieldfilter = new customfieldfilter('customcat');
        // $customfieldfilter->set_sql("id IN (SELECT userid
        //         FROM {user_info_data} uid
        //         JOIN {user_info_field} uif ON uid.fieldid = uif.id
        //         WHERE uif.shortname = 'supervisor'
        //         AND :where)");
        $customfieldfilter->set_sql("id IN (SELECT userid
                FROM {customfield_data} cfd
                JOIN {customfield_field} cff ON cfd.fieldid = cff.id
                WHERE cff.shortname = 'customcat'
                AND :where)");
        $customfieldfilter->set_subquery_column('cfd.value');


        $table->add_filter($customfieldfilter);

        // Make assertion to see the results.
        $this->assertCount(200, $table->rawdata);
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking Policy 1',
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'standardbookingoptions' => [
                [
                    'text' => 'Test Booking Option without price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'noprice',
                    'maxanswers' => 1,
                    'customfield_customcat' => 'Text 1',
                ],
                [
                    'text' => 'Test Booking Option with price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'withprice',
                    'maxanswers' => 1,
                    'customfield_customcat' => 'Text 1',
                ],
                [
                    'text' => 'Disalbed Test Booking Option',
                    'description' => 'Test Booking Option',
                    'identifier' => 'disabledoption',
                    'maxanswers' => 1,
                    'disablebookingusers' => 1,
                    'customfield_customcat' => 'Text 1',
                ],
                [
                    'text' => 'Wait for confirmation Booking Option, no price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'waitforconfirmationnoprice',
                    'maxanswers' => 1,
                    'waitforconfirmation' => 1,
                    'customfield_customcat' => 'Text 2',
                ],
            ],
        ];
    }
}