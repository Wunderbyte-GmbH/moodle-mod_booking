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
 * Tests for campaign triggering the bookingoption_freetobookagain event.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking_generator;
use context_system;
use mod_booking\bo_availability\bo_info;
use mod_booking\task\purge_campaign_caches;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that campaign transitions trigger bookingoption_freetobookagain event.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class campaign_freetobookagain_test extends advanced_testcase {
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
     * Test that the purge_campaign_caches task triggers the freetobookagain event
     * when a campaign with limitfactor > 1 increases available places above booked count.
     *
     * Scenario:
     * - Booking option with maxanswers=2 in DB.
     * - 2 students are booked (fully booked).
     * - 1 student is on the waitinglist.
     * - A campaign with limitfactor=2 starts, doubling available places to 4.
     * - The purge_campaign_caches task detects freed places and triggers the event.
     *
     * @covers \mod_booking\task\purge_campaign_caches::execute
     * @covers \mod_booking\booking_option::check_if_free_to_book_again
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_campaigns_settings_provider
     */
    public function test_campaign_limitfactor_triggers_freetobookagain(array $bdata): void {
        global $DB;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Create custom field for campaign matching.
        $categorydata = new stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Sport1';
        $fielddata->shortname = 'spt1';
        $fielddata->type = 'text';
        $fielddata->configdata = "{\"required\":\"0\",\"uniquevalues\":\"0\",\"locked\":\"0\",\"visibility\":\"2\",
                                    \"defaultvalue\":\"\",\"displaysize\":30,\"maxlength\":50,\"ispassword\":\"0\",
                                    \"link\":\"\",\"linktarget\":\"\"}";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();
        $this->assertTrue(\core_customfield\field::record_exists($bookingfield->get('id')));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule reacting to bookingoption_freetobookagain event.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"campaignfreesubj","template":"campaignfreemsg","templateformat":"1"}';
        $ruledata = [
            'name' => 'campaign_freetobookagain_rule',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Create booking option with maxanswers=2, waitinglist enabled.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Campaign test option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 2;
        $record->maxoverbooking = 3;
        $record->waitforconfirmation = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 day');
        $record->courseendtime_0 = strtotime('now + 6 day');
        $record->teachersforoption = $teacher->username;
        $record->customfield_spt1 = 'tennis';
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Book student1 via waitinglist then confirm (verified).
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $this->setAdminUser();
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book student2 via waitinglist then confirm (verified).
        time_mock::set_mock_time(strtotime('+1 hour', time()));
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $this->setAdminUser();
        $option->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Now the option is fully booked (2/2). Put student3 on the waitinglist.
        time_mock::set_mock_time(strtotime('+2 hours', time()));
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Verify the option is fully booked before campaign.
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $this->assertEquals(2, $settings->maxanswers);

        // Now create a campaign with limitfactor=2 that is already active.
        // The campaign doubles available places: 2 * 2 = 4.
        $campaigndata = (object) [
            'bofieldname' => 'spt1',
            'fieldvalue' => 'tennis',
            'campaignfieldnameoperator' => '=',
            'cpfield' => null,
            'cpoperator' => null,
            'cpvalue' => null,
        ];
        $campaign = [
            'name' => 'double_places',
            'type' => 0,
            'starttime' => strtotime('yesterday'),
            'endtime' => strtotime('now + 1 month'),
            'pricefactor' => 1,
            'limitfactor' => 2,
            'extendlimitforoverbooked' => 0,
            'json' => json_encode($campaigndata),
        ];
        $plugingenerator->create_campaign($campaign);

        // Purge caches and reset singletons so campaign takes effect.
        singleton_service::reset_campaigns();
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);

        // Verify the campaign modified maxanswers.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $this->assertEquals(4, $settings->maxanswers);

        // DB maxanswers is still 2.
        $dbmaxanswers = (int) $DB->get_field('booking_options', 'maxanswers', ['id' => $option1->id]);
        $this->assertEquals(2, $dbmaxanswers);

        // Clear any adhoc tasks that were created during setup.
        $DB->delete_records('task_adhoc', ['classname' => '\mod_booking\task\send_mail_by_rule_adhoc']);

        // Now execute the purge_campaign_caches task with campaign data.
        $task = new purge_campaign_caches();
        $task->set_custom_data((object) [
            'campaignid' => 1,
            'limitfactor' => 2.0,
            'campaignstart' => true,
        ]);

        // Reset singletons again before task execution (simulating cron environment).
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        singleton_service::destroy_instance();

        ob_start();
        $task->execute();
        $taskoutput = ob_get_clean();

        // The task should have detected that places were freed and triggered the event.
        $this->assertStringContainsString('event triggered', $taskoutput);

        // Verify that send_mail_by_rule_adhoc tasks were created for the waitlist student.
        $mailtasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertNotEmpty($mailtasks, 'Mail tasks should have been created for waitlist students');

        // Verify the task content targets the waitlisted student.
        $foundstudent3 = false;
        foreach ($mailtasks as $mailtask) {
            $customdata = $mailtask->get_custom_data();
            if (isset($customdata->customsubject) && $customdata->customsubject === 'campaignfreesubj') {
                $this->assertEquals('campaignfreemsg', $customdata->custommessage);
                if ($customdata->userid == $student3->id) {
                    $foundstudent3 = true;
                }
            }
        }
        $this->assertTrue($foundstudent3, 'Waitlisted student3 should receive a freetobookagain notification');
    }

    /**
     * Test that the purge_campaign_caches task does NOT trigger the event
     * when the option is not fully booked (no places freed).
     *
     * @covers \mod_booking\task\purge_campaign_caches::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_campaigns_settings_provider
     */
    public function test_campaign_limitfactor_no_trigger_when_not_fully_booked(array $bdata): void {
        global $DB;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student1 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Create custom field.
        $categorydata = new stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Sport1';
        $fielddata->shortname = 'spt1';
        $fielddata->type = 'text';
        $fielddata->configdata = "{\"required\":\"0\",\"uniquevalues\":\"0\",\"locked\":\"0\",\"visibility\":\"2\",
                                    \"defaultvalue\":\"\",\"displaysize\":30,\"maxlength\":50,\"ispassword\":\"0\",
                                    \"link\":\"\",\"linktarget\":\"\"}";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking option with maxanswers=5.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Not full option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 5;
        $record->maxoverbooking = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 day');
        $record->courseendtime_0 = strtotime('now + 6 day');
        $record->teachersforoption = $teacher->username;
        $record->customfield_spt1 = 'tennis';
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Book only 1 student (option not fully booked: 1/5).
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // Create an active campaign with limitfactor=2.
        $campaigndata = (object) [
            'bofieldname' => 'spt1',
            'fieldvalue' => 'tennis',
            'campaignfieldnameoperator' => '=',
            'cpfield' => null,
            'cpoperator' => null,
            'cpvalue' => null,
        ];
        $campaign = [
            'name' => 'double_places',
            'type' => 0,
            'starttime' => strtotime('yesterday'),
            'endtime' => strtotime('now + 1 month'),
            'pricefactor' => 1,
            'limitfactor' => 2,
            'extendlimitforoverbooked' => 0,
            'json' => json_encode($campaigndata),
        ];
        $plugingenerator->create_campaign($campaign);

        // Purge caches.
        singleton_service::reset_campaigns();
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);

        // Clear any adhoc tasks.
        $DB->delete_records('task_adhoc', ['classname' => '\mod_booking\task\send_mail_by_rule_adhoc']);

        // Execute the task.
        $task = new purge_campaign_caches();
        $task->set_custom_data((object) [
            'campaignid' => 1,
            'limitfactor' => 2.0,
            'campaignstart' => true,
        ]);

        singleton_service::destroy_instance();

        ob_start();
        $task->execute();
        $taskoutput = ob_get_clean();

        // The task should NOT have triggered any event (option was never fully booked).
        $this->assertStringNotContainsString('event triggered', $taskoutput);

        // No mail tasks should have been created.
        $mailtasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertEmpty($mailtasks, 'No mail tasks should be created when option was not fully booked');
    }

    /**
     * Data provider for campaign freetobookagain tests.
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_campaigns_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking Campaign Freeplace',
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
