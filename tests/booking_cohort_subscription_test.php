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
 * Tests cohort subscription booking from book other users flow.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_module;
use context_system;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for cohort bookings in booking options.
 */
final class booking_cohort_subscription_test extends advanced_testcase {
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
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }
    /**
     * Validate cohort subscription booking logic used in the Behat scenario.
     *
     * @covers \mod_booking\booking_utils::book_cohort_or_group_members
     *
     * @dataProvider cohort_subscription_provider
     * @param string $actinguserkey
     * @param string $cohortidnumber
     * @param int $expectedcohortmembers
     * @param int $expectednotenrolled
     * @param int $expectedsubscribed
     * @param array $expectedbookedemails
     */
    public function test_book_users_via_cohort_subscription(
        string $actinguserkey,
        string $cohortidnumber,
        int $expectedcohortmembers,
        int $expectednotenrolled,
        int $expectedsubscribed,
        array $expectedbookedemails
    ): void {
        global $DB;

        $this->resetAfterTest(true);
        $data = $this->create_subscription_fixture();

        $this->setUser($data->users[$actinguserkey]);

        $fromform = new stdClass();
        $fromform->cohortids = [$data->cohorts[$cohortidnumber]->id];
        $fromform->groupids = [];

        $result = booking_utils::book_cohort_or_group_members($fromform, $data->bookingoption, $data->context);

        $this->assertEquals($expectedcohortmembers, $result->sumcohortmembers);
        $this->assertEquals(0, $result->sumgroupmembers);
        $this->assertEquals($expectednotenrolled, $result->notenrolledusers);
        $this->assertEquals(0, $result->notsubscribedusers);
        $this->assertEquals($expectedsubscribed, $result->subscribedusers);

        $bookedanswers = $DB->get_records('booking_answers', [
            'bookingid' => $data->booking->id,
            'optionid' => $data->option->id,
        ]);
        $bookedemails = [];
        foreach ($bookedanswers as $answer) {
            $bookedemails[] = $DB->get_field('user', 'email', ['id' => $answer->userid]);
        }
        sort($bookedemails);
        sort($expectedbookedemails);

        $this->assertSame($expectedbookedemails, $bookedemails);
    }

    /**
     * Data provider for cohort subscription booking test.
     *
     * @return array
     */
    public static function cohort_subscription_provider(): array {
        return [
            'editingteacher books fully enrolled cohort' => [
                'teacher1',
                'cohort_enrolled_001',
                3,
                0,
                3,
                [
                    'student1@example.com',
                    'student2@example.com',
                    'student3@example.com',
                ],
            ],
            'editingteacher gets unenrolled user count for mixed cohort' => [
                'teacher1',
                'cohort_mixed_001',
                4,
                1,
                3,
                [
                    'student1@example.com',
                    'student2@example.com',
                    'student3@example.com',
                ],
            ],
            'manager books mixed cohort including non-enrolled users' => [
                'teacher2',
                'cohort_mixed_001',
                4,
                0,
                4,
                [
                    'student1@example.com',
                    'student2@example.com',
                    'student3@example.com',
                    'student4@example.com',
                ],
            ],
        ];
    }

    /**
     * Create shared fixture matching the Behat cohort subscription scenario.
     *
     * @return stdClass
     */
    private function create_subscription_fixture(): stdClass {
        global $DB;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Cohort booking course',
            'shortname' => 'CBC1',
        ]);

        $users = [
            'teacher1' => $this->getDataGenerator()->create_user([
                'username' => 'teacher1',
                'firstname' => 'Teacher',
                'lastname' => 'One',
                'email' => 'teacher1@example.com',
            ]),
            'teacher2' => $this->getDataGenerator()->create_user([
                'username' => 'teacher2',
                'firstname' => 'Teacher',
                'lastname' => 'Two',
                'email' => 'teacher2@example.com',
            ]),
            'student1' => $this->getDataGenerator()->create_user([
                'username' => 'student1',
                'firstname' => 'Student',
                'lastname' => 'One',
                'email' => 'student1@example.com',
            ]),
            'student2' => $this->getDataGenerator()->create_user([
                'username' => 'student2',
                'firstname' => 'Student',
                'lastname' => 'Two',
                'email' => 'student2@example.com',
            ]),
            'student3' => $this->getDataGenerator()->create_user([
                'username' => 'student3',
                'firstname' => 'Student',
                'lastname' => 'Three',
                'email' => 'student3@example.com',
            ]),
            'student4' => $this->getDataGenerator()->create_user([
                'username' => 'student4',
                'firstname' => 'Student',
                'lastname' => 'Four',
                'email' => 'student4@example.com',
            ]),
        ];

        $viewsitecohortsid = create_role('ViewSiteCohorts', 'viewsitecohorts', 'viewsitecohorts', '');
        assign_capability('moodle/cohort:view', CAP_ALLOW, $viewsitecohortsid, context_system::instance()->id);
        role_assign($viewsitecohortsid, $users['teacher1']->id, context_system::instance()->id);

        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $users['teacher2']->id, context_system::instance()->id);

        $editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($users['teacher1']->id, $course->id, $editingteacherroleid);
        $this->getDataGenerator()->enrol_user($users['teacher2']->id, $course->id, $editingteacherroleid);
        $this->getDataGenerator()->enrol_user($users['student1']->id, $course->id, $studentroleid);
        $this->getDataGenerator()->enrol_user($users['student2']->id, $course->id, $studentroleid);
        $this->getDataGenerator()->enrol_user($users['student3']->id, $course->id, $studentroleid);

        $cohorts = [
            'cohort_enrolled_001' => $this->getDataGenerator()->create_cohort([
                'name' => 'Cohort enrolled users',
                'idnumber' => 'cohort_enrolled_001',
                'contextid' => context_system::instance()->id,
                'visible' => 1,
            ]),
            'cohort_mixed_001' => $this->getDataGenerator()->create_cohort([
                'name' => 'Cohort with non-enrolled',
                'idnumber' => 'cohort_mixed_001',
                'contextid' => context_system::instance()->id,
                'visible' => 1,
            ]),
        ];

        cohort_add_member($cohorts['cohort_enrolled_001']->id, $users['student1']->id);
        cohort_add_member($cohorts['cohort_enrolled_001']->id, $users['student2']->id);
        cohort_add_member($cohorts['cohort_enrolled_001']->id, $users['student3']->id);

        cohort_add_member($cohorts['cohort_mixed_001']->id, $users['student1']->id);
        cohort_add_member($cohorts['cohort_mixed_001']->id, $users['student2']->id);
        cohort_add_member($cohorts['cohort_mixed_001']->id, $users['student3']->id);
        cohort_add_member($cohorts['cohort_mixed_001']->id, $users['student4']->id);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Cohort booking',
            'intro' => 'Cohort booking instance',
            'bookingmanager' => $users['teacher1']->username,
            'eventtype' => 'Webinar',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Cohort option',
            'courseid' => $course->id,
            'description' => 'Cohort option 1',
            'limitanswers' => 0,
            'maxanswers' => 0,
            'teachersforoption' => $users['teacher1']->username,
        ]);

        $bookingoption = booking_option::create_option_from_optionid($option->id, $booking->id);
        $cm = get_coursemodule_from_instance('booking', $booking->id, $course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        return (object) [
            'course' => $course,
            'users' => $users,
            'cohorts' => $cohorts,
            'booking' => $booking,
            'option' => $option,
            'bookingoption' => $bookingoption,
            'context' => $context,
        ];
    }
}