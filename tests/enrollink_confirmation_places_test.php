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
 * Tests that confirming an enrollink booking from the waiting list consumes a bundle place.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\bo_info;
use mod_booking\local\mobile\customformstore;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that confirming an enrollink booking from the waiting list consumes a bundle place.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrollink_confirmation_places_test extends booking_advanced_testcase {
    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        enrollink::destroy_instances();
    }

    /**
     * Confirming an enrollink user from the waiting list must reduce the bundle answer places.
     *
     * A buyer purchases an option with "enrol multiple users" (10 places) where enrollink
     * enrollments have to be confirmed (enroluserstowaitinglist). A user books via enrollink and
     * lands on the waiting list. When an admin confirms the booking via the manage users table,
     * one place must be deduced from the buyer's booking answer: 9 remaining bundle places plus
     * the confirmed user's own answer equal the 10 places bought - not 11.
     *
     * @covers \mod_booking\table\manageusers_table::action_confirmbooking
     * @covers \mod_booking\enrollink::add_consumed_item
     * @covers \mod_booking\booking_option::user_submit_response
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_confirming_enrollink_user_reduces_bundle_places(array $bdata): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $buyer = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $bdata['autoenrol'] = 1;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($buyer->id, $course->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata = (object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 25,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata);

        // Option with "enrol multiple users" where enrollink enrollments need confirmation.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (enrollink confirmation places)';
        $record->importing = 1;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->useprice = 1;
        $record->enrolmentstatus = 2;
        $record->waitforconfirmation = 1;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        // Enrollink enrollments are forced to the waiting list until confirmed.
        $record->bo_cond_customform_enroluserstowaitinglist1 = 1;

        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        enrollink::destroy_instances();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // The buyer books 10 places (including themselves).
        $this->setUser($buyer);
        singleton_service::destroy_user($buyer->id);
        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $buyer->id,
            'customform_enrolusersaction_1' => 10,
            'customform_enroluserwhobookedcheckbox_enrolusersaction_1' => 1,
        ];
        $customformstore = new customformstore($buyer->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        booking_bookit::bookit('option', $settings->id, $buyer->id);
        booking_bookit::bookit('option', $settings->id, $buyer->id);
        booking_bookit::bookit('option', $settings->id, $buyer->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $buyer->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Admin confirms the buyer's booking (payment process is skipped in this test).
        $this->setAdminUser();
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $optionobj->user_submit_response($buyer, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION, MOD_BOOKING_VERIFIED);
        $optionobj->user_submit_response($buyer, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $buyer->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // The purchase created the enrollink bundle with 10 places on the buyer's answer.
        $bundle = $DB->get_record('booking_enrollink_bundles', ['optionid' => $option->id], '*', MUST_EXIST);
        $this->assertEquals(10, (int)$bundle->places);
        $buyeranswer = $DB->get_record('booking_answers', ['id' => $bundle->baid], '*', MUST_EXIST);
        $this->assertEquals(10, (int)$buyeranswer->places);

        // Student1 books via enrollink and lands on the waiting list (confirmation required).
        $this->setUser($student1);
        enrollink::destroy_instances();
        $enrollinkobj = enrollink::get_instance($bundle->erlid);
        $result = $enrollinkobj->enrol_user($student1->id);
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_WAITINGLIST, $result);

        // The buyer consumed one place for themselves; nothing is consumed for the waiting user yet.
        $this->assertEquals(9, $enrollinkobj->free_places_left());
        $buyeranswer = $DB->get_record('booking_answers', ['id' => $bundle->baid], '*', MUST_EXIST);
        $this->assertEquals(10, (int)$buyeranswer->places);

        $studentanswer = $DB->get_record(
            'booking_answers',
            ['optionid' => $option->id, 'userid' => $student1->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, (int)$studentanswer->waitinglist);

        // Admin confirms the enrollink booking via the manage users table (the real UI flow).
        $this->setAdminUser();
        $table = new manageusers_table('enrollinkconfirmationtest');
        $response = $table->action_confirmbooking(0, json_encode(['id' => $studentanswer->id]));
        $this->assertEquals(1, $response['success']);

        // The student must be booked now.
        $studentanswer = $DB->get_record('booking_answers', ['id' => $studentanswer->id], '*', MUST_EXIST);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$studentanswer->waitinglist);

        // One place must be deduced from the buyer's answer: 9 bundle places + 1 booked user = 10.
        $buyeranswer = $DB->get_record('booking_answers', ['id' => $bundle->baid], '*', MUST_EXIST);
        $this->assertEquals(
            9,
            (int)$buyeranswer->places,
            'Confirming an enrollink booking must deduce one place from the bundle answer (9 + 1 = 10, not 10 + 1 = 11).'
        );

        // The confirmed user also consumed a bundle place.
        enrollink::destroy_instances();
        $enrollinkobj = enrollink::get_instance($bundle->erlid);
        $this->assertEquals(8, $enrollinkobj->free_places_left());
    }

    /**
     * Data provider with minimal booking instance settings.
     *
     * @return array
     */
    public static function booking_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking 1',
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
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
