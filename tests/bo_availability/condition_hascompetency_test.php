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
 * Tests for hascompetency availability condition.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_module;
use context_system;
use core_competency\api;
use core_competency\competency;
use core_competency\user_competency;
use mod_booking\bo_availability\bo_info;
use stdClass;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

/**
 * Tests for hascompetency availability condition.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_hascompetency_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_hascompetency_condition(array $bdata): void {
        global $PAGE;

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
        $framework = api::create_framework((object)[
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
        $competency1 = new competency(0, $record);
        $competency1->set('sortorder', 0);
        $competency1->create();

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
        set_config('usecompetencies', 1, 'booking');

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');

        // User 1 has both competencies.
        api::get_user_competency($user1->id, $competency1->get('id'));
        api::get_user_competency($user1->id, $competency2->get('id'));
        $this->assertNotEmpty(user_competency::get_records(['userid' => $user1->id, 'competencyid' => $competency1->get('id')]));
        $this->assertNotEmpty(user_competency::get_records(['userid' => $user1->id, 'competencyid' => $competency2->get('id')]));

        // User 2 has only one competency.
        api::get_user_competency($user2->id, $competency1->get('id'));
        $this->assertNotEmpty(user_competency::get_records(['userid' => $user2->id, 'competencyid' => $competency1->get('id')]));
        $this->assertEmpty(user_competency::get_records(['userid' => $user2->id, 'competencyid' => $competency2->get('id')]));

        // User 3 has no competencies.
        $this->assertEmpty(user_competency::get_records(['userid' => $user3->id]));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'testoption';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->bo_cond_hascompetency_restrict = "1";
        $record->bo_cond_hascompetency_competencyids = [
            $competency1->get('id'),
            $competency2->get('id'),
        ];
        $record->bo_cond_hascompetency_competencyids_operator = "AND";
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $this->setUser($user1);
        // User 1 has both competencies - allowed.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $this->setUser($user2);
        // User 2 has one competency - not allowed because of "AND" operator.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY, $id);

        $this->setUser($user3);
        // User 3 has no competency - not allowed.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY, $id);

        // Now we switch to "OR" operator.
        $this->setAdminUser();
        $record->id = $option->id;
        $record->bo_cond_hascompetency_competencyids_operator = "OR";
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        $this->setUser($user1);
        // User 1 has both competencies - allowed.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $this->setUser($user2);
        // User 2 has one competency - still allowed because of "OR" operator.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $this->setUser($user3);
        // User 3 has no competency - still not allowed.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_HASCOMPETENCY, $id);
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
