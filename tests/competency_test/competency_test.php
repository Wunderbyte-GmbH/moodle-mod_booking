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
 * Tests for competencies.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_course;
use context_system;
use core_competency\competency;
use core_competency\user_competency;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoption_booked;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoptioncompleted;
use mod_booking\booking_rules\rules\templates\ruletemplate_confirmwaitinglist;
use mod_booking\booking_rules\rules\templates\ruletemplate_courseupdate;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;
use mod_booking\booking_rules\rules\templates\ruletemplate_paymentconfirmation;
use mod_booking\booking_rules\rules\templates\ruletemplate_trainerpoll;
use mod_booking\booking_rules\rules\templates\ruletemplate_userpoll;
use mod_booking\option\fields\competencies;
use stdClass;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use mod_booking_generator;

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class competency_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option->completion
     * @covers \mod_booking\event\bookingoption_booked
     * @covers \mod_booking\event\bookingoption_completed
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event->execute
     * @covers \mod_booking\booking_rules\conditions\select_user_from_event->execute
     * @covers \mod_booking\booking_rules\conditions\match_userprofilefield->execute
     * @covers \mod_booking\booking_rules\actions\send_mail->execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_assign_competency_on_option_completion(array $bdata): void {

        singleton_service::destroy_instance();

        $this->setAdminUser();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $scale = $this->getDataGenerator()->create_scale([
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
        ]);

        // Create a competency.
        $framework = \core_competency\api::create_framework((object)[
            'shortname' => 'testframework',
            'idnumber' => 'testframework',
            'contextid' => context_system::instance()->id,
            'scaleid' => $scale->id,
            'scaleconfiguration' => json_encode([
                ['scaleid' => $scale->id],
                ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
                ['id' => 2, 'scaledefault' => 0, 'proficient' => 1],
            ]),
        ]);
        // Create compentencies.
        $record = (object)[
            'shortname' => 'testcompetency',
            'idnumber' => 'testcompetency',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $competency = new competency(0, $record);
        $competency->set('sortorder', 0);
        $competency->create();

        $record = (object)[
            'shortname' => 'testcompetency2',
            'idnumber' => 'testcompetency2',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency2',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $competency2 = new competency(0, $record);
        $competency2->set('sortorder', 0);
        $competency2->create();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'editingteacher');

        // User 2 already has competency 1.
        $usercompetency = new user_competency(0, (object)[
            'userid' => $user2->id,
            'competencyid' => $competency->get('id'),
            'proficiency' => 1, // 1 for proficient, 0 for not proficient.
            'grade' => null,
            'status' => user_competency::STATUS_IDLE,
            'reviewerid' => $user1->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $usercompetency->create();
        $existing = user_competency::get_record(['userid' => $user2->id, 'competencyid' => $competency->get('id')]);
        $this->assertEquals(1, count($existing), 'Competency could not be created for user');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $record->competencies = [$competency->get('id'), $competency2->get('id')];
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->assertEquals(false, $option->user_completed_option());
        booking_activitycompletion([$user2->id], $option->booking->settings, $settings->cmid, $option1->id);
        $this->assertEquals(true, $option->user_completed_option());

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(2, $messages);
        $keys = array_keys($messages);
        // Task 1 has to be "match_userprofilefield".
        $message = $messages[$keys[0]];
        // Validate adhoc tasks for rule 1.
        $customdata = $message->get_custom_data();
        $this->assertEquals($user2->id, $customdata->userid);
        $this->assertStringContainsString('bookingoption_booked', $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew['actiondata'], $customdata->rulejson);
        $this->assertEquals($user2->id, $message->get_userid());
        // Task 2 has to be "select_user_from_event".
        $message = $messages[$keys[1]];
        // Validate adhoc tasks for rule 2.
        $customdata = $message->get_custom_data();
        $this->assertEquals($user2->id, $customdata->userid);
        $this->assertStringContainsString("bookingoption_completed", $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew2['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew2['actiondata'], $customdata->rulejson);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($user2->id, $rulejson->datafromevent->relateduserid);
        $this->assertEquals($user2->id, $message->get_userid());

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
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
