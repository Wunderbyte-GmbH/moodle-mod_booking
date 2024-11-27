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
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::reset_campaigns();
        singleton_service::get_instance()->users = [];
        singleton_service::get_instance()->bookinganswers = [];
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
        $student3 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');
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

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create 1st blocking campaing: with "abowe" condition.
        $campaingdata1 = (object)[
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
        $campaing1 = [
            'name' => 'bloking_above30', 'type' => 1,
            'starttime' => strtotime('yesterday'), 'endtime' => strtotime('now + 1 month'),
            'pricefactor' => 1, 'limitfactor' => 1,
            'json' => json_encode($campaingdata1),
        ];
        $plugingenerator->create_campaign($campaing1);

        // Create 2nd blocking campaing: with "below" condition.
        $campaingdata2 = (object)[
            'bofieldname' => 'spt1',
            'fieldvalue' => 'yoga',
            'campaignfieldnameoperator' => '=',
            'cpfield' => null,
            'cpoperator' => null,
            'cpvalue' => null,
            'blockoperator' => 'blockbelow',
            'blockinglabel' => 'block_below_30',
            'hascapability' => null,
            'percentageavailableplaces' => 30,
        ];
        $campaing2 = [
            'name' => 'bloking_below30', 'type' => 1,
            'starttime' => strtotime('yesterday'), 'endtime' => strtotime('now + 1 month'),
            'pricefactor' => 1, 'limitfactor' => 1,
            'json' => json_encode($campaingdata2),
        ];
        $plugingenerator->create_campaign($campaing2);

        // Create 1st booking option.
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
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id); // Mandatory there.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $optionobj1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $option1->id);
        $boinfo1 = new bo_info($settings1);

        // Create 2nd booking option.
        $record->text = 'Test option2';
        $record->customfield_spt1 = 'yoga';
        $option2 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option2->id); // Mandatory there.
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $optionobj2 = singleton_service::get_instance_of_booking_option($settings2->cmid, $option2->id);
        $boinfo2 = new bo_info($settings2);

        // Try to book options with student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Book option1.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        // Try to book option2 but cannot: "block_below_30".
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);

        // Try to book options with student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        // Try to book option1 but cannot: "block_above_30".
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);
        // Try to book option2 but cannot: "block_below_30".
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);

        // Admin's adjustments for options / campaigns.
        $this->setAdminUser();
        // Book the student1 directly into option2 to make it accessible for other users.
        $optionobj2->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Validate that option2 become accessible for student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $this->setAdminUser();
        // Get campaign IDs.
        $campaigns = singleton_service::get_all_campaigns();
        foreach ($campaigns as $campaignobj) {
            switch ($campaignobj->name) {
                case $campaing1['name']:
                    $campaing1['id'] = $campaignobj->id;
                    break;
                case $campaing2['name']:
                    $campaing2['id'] = $campaignobj->id;
                    break;
            }
        }
        // Adjust 1st blocking campaing: set to future only.
        $campaing1['name'] = 'bloking_above30-future';
        $campaing1['starttime'] = strtotime('now + 2 day');
        $plugingenerator->create_campaign($campaing1);
        // Adjust 2nd blocking campaing: with "below" condition.
        $campaingdata2->blockinglabel = 'block_below_50';
        $campaingdata2->percentageavailableplaces = 50;
        $campaing2['json'] = json_encode($campaingdata2);
        $campaing2['name'] = 'bloking_below50';
        $plugingenerator->create_campaign($campaing2);

        // Reset caches (campaigns and options).
        singleton_service::reset_campaigns();

        singleton_service::destroy_booking_option_singleton($option1->id); // Mandatory there.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $optionobj1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $option1->id);
        $boinfo1 = new bo_info($settings1);
        singleton_service::destroy_booking_option_singleton($option2->id); // Mandatory there.
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $optionobj2 = singleton_service::get_instance_of_booking_option($settings2->cmid, $option2->id);
        $boinfo2 = new bo_info($settings2);

        // Try to book options with student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Validate option1 already booked.
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        // Try to book option2.
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book options with student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        // Validate that option1 become accessible for student2 - campaign has not started yet.
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        // Validate that option2 became inaccessible for student2 again.
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);
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
        $student3 = $this->getDataGenerator()->create_user($users[2]);
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();
        $student6 = $this->getDataGenerator()->create_user();
        $student7 = $this->getDataGenerator()->create_user();
        $student8 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
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
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->useprice = 0;
        $record->maxanswers = 6;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 1 day');
        $record->courseendtime_1 = strtotime('now + 2 day');
        $record->customfield_bcustom1 = 'exclude';

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id); // Mandatory there.

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->useprice = 0;
        $record->maxanswers = 6;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 1 day');
        $record->courseendtime_1 = strtotime('now + 3 day');
        $record->customfield_bcustom1 = '';

        $option2 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option2->id); // Mandatory there.

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->useprice = 0;
        $record->maxanswers = 6;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 1 day');
        $record->courseendtime_1 = strtotime('now + 4 day');
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
            'cpvalue' => ['student'],
            'blockoperator' => 'blockbelow',
            'blockinglabel' => 'Below_50',
            'hascapability' => null,
            'percentageavailableplaces' => 50,
        ];
        $campaing = new stdClass();
        $campaing = [
            'name' => 'bloking1', 'type' => 1,
            'starttime' => strtotime('yesterday'), 'endtime' => strtotime('now + 1 week'),
            'pricefactor' => 1, 'limitfactor' => 1,
            'json' => json_encode($campaingdata),
        ];

        $plugingenerator->create_campaign($campaing);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $optionobj1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid);
        $boinfo1 = new bo_info($settings1);
        // Option1 - booke necessary users directly.
        $optionobj1->user_submit_response($student4, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $optionobj1->user_submit_response($student5, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $optionobj1->user_submit_response($student6, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $optionobj1->user_submit_response($student7, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $optionobj1->user_submit_response($student8, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $optionobj2 = singleton_service::get_instance_of_booking_option($settings2->cmid, $option2->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings2->cmid);
        $boinfo2 = new bo_info($settings2);

        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $optionobj3 = singleton_service::get_instance_of_booking_option($settings3->cmid, $option3->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings3->cmid);

        // Option3 - booke necessary users directly.
        $optionobj3->user_submit_response($student4, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $optionobj3->user_submit_response($student5, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $boinfo3 = new bo_info($settings3);

        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CAMPAIGN_BLOCKBOOKING, $id);

        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings3->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings2->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings3->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
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
