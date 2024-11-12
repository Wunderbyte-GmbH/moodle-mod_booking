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
 * Tests for booking option policy.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use context_system;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_campaigns\campaigns_info;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for booking options policy.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_campaigns_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test campaign blockbooking.
     *
     * @covers \condition\campaign_blockbooking::is_available
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_campaigns_settings_provider
     */
    public function test_booking_bookit_campaign_blockbooking(array $bdata): void {
        global $DB, $CFG;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        $categorydata            = new stdClass();
        $categorydata->name      = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area      = 'booking';
        $categorydata->itemid    = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array)$categorydata);
        $bookingcat->save();

        $fielddata                = new stdClass();
        $fielddata->categoryid    = $bookingcat->get('id');
        $fielddata->name       = 'Sport1';
        $fielddata->shortname  = 'spt1';
        $fielddata->type = 'text';
        $fielddata->configdata    = "{\"required\":\"0\",\"uniquevalues\":\"0\",\"locked\":\"0\",\"visibility\":\"2\",
                                    \"defaultvalue\":\"\",\"displaysize\":30,\"maxlength\":50,\"ispassword\":\"0\",
                                    \"link\":\"\",\"linktarget\":\"\"}";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array)$fielddata);
        $bookingfield->save();
        $this->assertTrue(\core_customfield\field::record_exists($bookingfield->get('id')));

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 3;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 3 day');
        $record->courseendtime_1 = strtotime('now + 6 day');
        $record->customfield_spt1 = 'tennis';

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create blocking campaing.
        $campaingdata = (object)[
            'bofieldname' => 'spt1',
            'fieldvalue' => 'tennis',
            'campaignfieldnameoperator' => '=',
            'cpfield' => null,
            'cpoperator' => null,
            'cpvalue' => null,
            'blockoperator' => 'blockabove',
            'blockinglabel' => 'block_above_30',
            'hascapability' => null,
            'percentageavailableplaces' => 30,
        ];
        $campaing = new stdClass();
        $campaing = [
            'name' => 'bloking', 'type' => 1,
            'starttime' => strtotime('yesterday'), 'endtime' => strtotime('now + 1 month'),
            'pricefactor' => 1, 'limitfactor' => 1,
            'json' => json_encode($campaingdata),
        ];

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id); // Mandatory there.

        $plugingenerator->create_campaign($campaing);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $optionobj1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $option1->id);

        // Book the first user without any problem.
        $boinfo1 = new bo_info($settings1);

        $this->setUser($student1);
        // Book option1 by student1.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book option1 with student2.
        $this->setUser($student2);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);

        // Force campaing deletion.
        $camps = campaigns_info::get_all_campaigns();
        foreach ($camps as $camp) {
            campaigns_info::delete_campaign($camp->id);
        }
        singleton_service::get_instance()->campaigns = [];
    }

    /**
     * Test campaign blockbooking.
     *
     * @covers \condition\campaign_blockbooking::is_available
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_campaigns_settings_provider
     */
    public function test_booking_campaign_blockbooking_customfields(array $bdata): void {
        global $DB, $CFG;

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'ucustom1', 'name' => 'ucustom1',
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'ucustom2', 'name' => 'ucustom2',
        ]);

        $users = [
            ['profile_field_ucustom1' => 'student', 'profile_field_ucustom2' => 'no'],
            ['profile_field_ucustom1' => '', 'profile_field_ucustom2' => 'yes'],
            ['profile_field_ucustom1' => 'teacher', 'profile_field_ucustom2' => ''],
        ];

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user($users[0]);
        $student2 = $this->getDataGenerator()->create_user($users[1]);
        $teacher = $this->getDataGenerator()->create_user($users[2]);
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        // Create booking custom field.
        $categorydata            = new stdClass();
        $categorydata->name      = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area      = 'booking';
        $categorydata->itemid    = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array)$categorydata);
        $bookingcat->save();

        $fielddata                = new stdClass();
        $fielddata->categoryid    = $bookingcat->get('id');
        $fielddata->name       = 'bcustom1';
        $fielddata->shortname  = 'bcustom1';
        $fielddata->type = 'text';
        $fielddata->configdata    = "{\"required\":\"0\",\"uniquevalues\":\"0\",\"locked\":\"0\",\"visibility\":\"2\",
                                    \"defaultvalue\":\"\",\"displaysize\":30,\"maxlength\":50,\"ispassword\":\"0\",
                                    \"link\":\"\",\"linktarget\":\"\"}";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array)$fielddata);
        $bookingfield->save();
        $this->assertTrue(\core_customfield\field::record_exists($bookingfield->get('id')));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->useprice = 0;
        $record->bookedusers = 5;
        $record->maxanswers = 6;
        //$record->optiondateid_1 = "0";
        //$record->daystonotify_1 = "0";
        //$record->coursestarttime_1 = strtotime('now - 2 day');
        //$record->courseendtime_1 = strtotime('now + 2 day');
        $record->customfield_bcustom1 = 'exclude';

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id); // Mandatory there.

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->useprice = 0;
        $record->bookedusers = 0;
        $record->maxanswers = 6;
        //$record->optiondateid_1 = "0";
        //$record->daystonotify_1 = "0";
        //$record->coursestarttime_1 = strtotime('now - 2 day');
        //$record->courseendtime_1 = strtotime('now + 2 day');
        $record->customfield_bcustom1 = '';

        $option2 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option2->id); // Mandatory there.

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->useprice = 0;
        $record->bookedusers = 2;
        $record->maxanswers = 6;
        //$record->optiondateid_1 = "0";
        //$record->daystonotify_1 = "0";
        //$record->coursestarttime_1 = strtotime('now - 2 day');
        //$record->courseendtime_1 = strtotime('now + 2 day');
        $record->customfield_bcustom1 = 'include';

        $option3 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option3->id); // Mandatory there.

        // Create blocking campaing.
        $campaingdata = (object)[
            'bofieldname' => 'bcustom1',
            'fieldvalue' => 'exclude',
            'campaignfieldnameoperator' => '!~', // Does not contain!
            'cpfield' => 'ucustom1',
            'cpoperator' => '~',
            'cpvalue' => 'student',
            'blockoperator' => 'blockbelow',
            'blockinglabel' => 'block_below_50',
            'hascapability' => null,
            'percentageavailableplaces' => 50,
        ];
        $campaing = new stdClass();
        $campaing = [
            'name' => 'bloking1', 'type' => 1,
            'starttime' => strtotime('yesterday'), 'endtime' => strtotime('now + 1 day'),
            'pricefactor' => 1, 'limitfactor' => 1,
            'json' => json_encode($campaingdata),
        ];

        $plugingenerator->create_campaign($campaing);
        $camps = campaigns_info::get_all_campaigns();

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $optionobj1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $option1->id);
        $boinfo1 = new bo_info($settings1);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $optionobj2 = singleton_service::get_instance_of_booking_option($settings2->cmid, $option2->id);
        $boinfo2 = new bo_info($settings2);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $optionobj3 = singleton_service::get_instance_of_booking_option($settings3->cmid, $option3->id);
        $boinfo3 = new bo_info($settings3);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings2->id, $student1->id, true);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings3->id, $student1->id, true);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings2->id, $student2->id, true);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings3->id, $student2->id, true);

        $this->setUser($student1);
        // Book option1 by student1.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book option1 with student2.
        $this->setUser($student2);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);
    }

    /**
     * Data provider for condition_allowupdate_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_campaigns_settings_provider(): array {
        $bdata = [
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
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
