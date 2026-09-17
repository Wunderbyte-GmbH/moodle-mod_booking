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
 * Semantic anchor tests for the SQL availability filter (usesqlfilteravailability).
 *
 * These tests pin the OBSERVABLE list visibility produced by
 * bo_info::return_sql_from_conditions() through the real option table pipeline,
 * so the planned refactoring of the SQL filter (config-deduplicated PHP verdicts
 * instead of per-condition JSON SQL) can be proven behaviour-preserving. They
 * intentionally cover the paths that had no SQL-path coverage before: the OR
 * operators, the cohort and string-profile-field list filtering, the bypass for
 * already-booked users, the capability bypass, options with several filter
 * conditions, users with empty course/cohort/profile sets, and the booking-time
 * windows.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use context_system;
use mod_booking\bo_availability\bo_info;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Semantic anchor tests for the SQL availability filter.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_sqlfilter_semantics_test extends booking_advanced_testcase {
    /** @var mod_booking_generator $plugingenerator the plugin generator */
    private mod_booking_generator $plugingenerator;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Create the booking instance, fix $PAGE onto its cm and enable the SQL filter.
     *
     * @param array $bdata booking instance data
     * @return array{0:stdClass,1:stdClass} [course1, booking1]
     */
    private function seed_instance(array $bdata): array {
        global $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $this->plugingenerator = $plugingenerator;

        return [$course1, $booking1];
    }

    /**
     * Build the base option record (no availability condition yet).
     *
     * @param stdClass $booking1
     * @param stdClass $course1
     * @param string $text
     * @return stdClass
     */
    private function base_option_record(stdClass $booking1, stdClass $course1, string $text): stdClass {
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        return $record;
    }

    /**
     * Whether the given user sees the option through the real table pipeline.
     *
     * @param stdClass $user
     * @param int $optionid
     * @return bool
     */
    private function is_visible_for(stdClass $user, int $optionid): bool {
        $this->setUser($user);
        $rows = $this->plugingenerator->create_table_for_one_option($optionid);
        return count($rows) === 1;
    }

    /**
     * Set a custom profile field value for a user.
     *
     * @param stdClass $user
     * @param stdClass $profilefield
     * @param string $value
     * @return void
     */
    private function set_profile_value(stdClass $user, stdClass $profilefield, string $value): void {
        global $DB;
        $DB->insert_record('user_info_data', [
            'userid' => $user->id,
            'fieldid' => $profilefield->id,
            'data' => $value,
        ]);
    }

    /**
     * G1 + G8: enrolledincourse with the OR operator must accept enrolment in ANY
     * of the selected courses, and an option without any filter condition must
     * stay visible to everybody while the setting is active.
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincourse::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolledincourse_or_operator_and_untouched_option(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();

        $userina = $this->getDataGenerator()->create_user();
        $userinb = $this->getDataGenerator()->create_user();
        $usernone = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($userina->id, $coursea->id);
        $this->getDataGenerator()->enrol_user($userinb->id, $courseb->id);
        foreach ([$userina, $userinb, $usernone] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        }

        $record = $this->base_option_record($booking1, $course1, 'Requires course A OR course B');
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$coursea->id, $courseb->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'OR';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;
        $filtered = $this->plugingenerator->create_option($record);

        $unfiltered = $this->plugingenerator->create_option(
            $this->base_option_record($booking1, $course1, 'No filter at all')
        );

        $this->assertTrue(
            $this->is_visible_for($userina, $filtered->id),
            'OR operator: enrolment in course A alone must satisfy the filter'
        );
        $this->assertTrue(
            $this->is_visible_for($userinb, $filtered->id),
            'OR operator: enrolment in course B alone must satisfy the filter'
        );
        $this->assertFalse(
            $this->is_visible_for($usernone, $filtered->id),
            'OR operator: a user enrolled in neither course must be hidden'
        );

        foreach ([$userina, $userinb, $usernone] as $user) {
            $this->assertTrue(
                $this->is_visible_for($user, $unfiltered->id),
                'an option without filter conditions must stay visible to everybody while the setting is on'
            );
        }
    }

    /**
     * G2: enrolledincohorts through the SQL list path, both operators: AND requires
     * membership in all selected cohorts, OR in at least one.
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincohorts::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolledincohorts_and_or_operators_sql_path(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);

        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort one', 'idnumber' => 'CO1']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort two', 'idnumber' => 'CO2']);

        $userboth = $this->getDataGenerator()->create_user();
        $userone = $this->getDataGenerator()->create_user();
        $usernone = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort1->id, $userboth->id);
        cohort_add_member($cohort2->id, $userboth->id);
        cohort_add_member($cohort1->id, $userone->id);
        foreach ([$userboth, $userone, $usernone] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        }

        $record = $this->base_option_record($booking1, $course1, 'Requires cohort 1 AND cohort 2');
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id, $cohort2->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
        $andoption = $this->plugingenerator->create_option($record);

        $record = $this->base_option_record($booking1, $course1, 'Requires cohort 1 OR cohort 2');
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id, $cohort2->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'OR';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
        $oroption = $this->plugingenerator->create_option($record);

        $this->assertTrue(
            $this->is_visible_for($userboth, $andoption->id),
            'AND operator: member of both cohorts must see the option'
        );
        $this->assertFalse(
            $this->is_visible_for($userone, $andoption->id),
            'AND operator: member of only one cohort must be hidden'
        );
        $this->assertFalse(
            $this->is_visible_for($usernone, $andoption->id),
            'AND operator: member of no cohort must be hidden'
        );

        $this->assertTrue(
            $this->is_visible_for($userone, $oroption->id),
            'OR operator: member of one cohort must see the option'
        );
        $this->assertFalse(
            $this->is_visible_for($usernone, $oroption->id),
            'OR operator: member of no cohort must be hidden'
        );
    }

    /**
     * G3: userprofilefield string operators through the SQL list path: equals (=),
     * contains (~) and not-equals (!=).
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_userprofilefield_string_operators_sql_path(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);

        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'department',
            'name' => 'Department',
        ]);

        $salesuser = $this->getDataGenerator()->create_user();
        $marketinguser = $this->getDataGenerator()->create_user();
        $this->set_profile_value($salesuser, $profilefield, 'sales');
        $this->set_profile_value($marketinguser, $profilefield, 'marketing');
        foreach ([$salesuser, $marketinguser] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        }

        $makeoption = function (string $operator, string $value, string $text) use ($booking1, $course1): stdClass {
            $record = $this->base_option_record($booking1, $course1, $text);
            $record->bo_cond_userprofilefield_2_custom_restrict = 1;
            $record->bo_cond_customuserprofilefield_field = 'department';
            $record->bo_cond_customuserprofilefield_operator = $operator;
            $record->bo_cond_customuserprofilefield_value = $value;
            $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;
            return $this->plugingenerator->create_option($record);
        };

        $equals = $makeoption('=', 'sales', 'Department equals sales');
        $contains = $makeoption('~', 'al', 'Department contains al');
        $notequals = $makeoption('!=', 'sales', 'Department is not sales');

        $this->assertTrue($this->is_visible_for($salesuser, $equals->id), 'equals: sales user must see the option');
        $this->assertFalse(
            $this->is_visible_for($marketinguser, $equals->id),
            'equals: marketing user must be hidden'
        );

        $this->assertTrue(
            $this->is_visible_for($salesuser, $contains->id),
            'contains: "sales" contains "al", user must see the option'
        );
        $this->assertFalse(
            $this->is_visible_for($marketinguser, $contains->id),
            'contains: "marketing" does not contain "al", user must be hidden'
        );

        $this->assertFalse(
            $this->is_visible_for($salesuser, $notequals->id),
            'not-equals: sales user must be hidden'
        );
        $this->assertTrue(
            $this->is_visible_for($marketinguser, $notequals->id),
            'not-equals: marketing user must see the option'
        );
    }

    /**
     * G4: in a USER-SCOPED options query (the caller passes a userid, as the
     * my-bookings style tables do), a user with an active booking answer must keep
     * seeing the option although the filter condition would hide it
     * (bookeduserbypass). A condition-failing user without a booking stays hidden.
     * The general list (userid null, e.g. the course view) applies no bypass.
     *
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_booked_user_bypass_in_user_scoped_query(array $bdata): void {
        global $DB;

        [$course1, $booking1] = $this->seed_instance($bdata);
        $courseb = $this->getDataGenerator()->create_course();

        $bookedstudent = $this->getDataGenerator()->create_user();
        $unbookedstudent = $this->getDataGenerator()->create_user();
        foreach ([$bookedstudent, $unbookedstudent] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        }

        $record = $this->base_option_record($booking1, $course1, 'Requires course B');
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$courseb->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;
        $option = $this->plugingenerator->create_option($record);

        // The admin books the first student onto the option (limits allow it).
        $this->setAdminUser();
        $bookingoption = singleton_service::get_instance_of_booking_option((int) $booking1->cmid, (int) $option->id);
        $this->assertNotFalse(
            $bookingoption->user_submit_response($bookedstudent, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'precondition: booking the student must succeed'
        );
        singleton_service::destroy_instance();

        // Helper running the user-scoped options query for a given user.
        $optionids = function (stdClass $user) use ($DB, $booking1): array {
            $this->setUser($user);
            [$fields, $from, $where, $params] = booking::get_options_filter_sql(
                0,
                0,
                '',
                null,
                null,
                [],
                ['bookingid' => (int) $booking1->id],
                (int) $user->id,
                [MOD_BOOKING_STATUSPARAM_BOOKED]
            );
            $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
            return array_map(static fn($r): int => (int) $r->id, $rows);
        };

        $this->assertContains(
            (int) $option->id,
            $optionids($bookedstudent),
            'a booked user must keep seeing the option in the user-scoped query although the condition fails'
        );
        $this->assertNotContains(
            (int) $option->id,
            $optionids($unbookedstudent),
            'an unbooked user failing the condition must stay hidden in the user-scoped query'
        );

        // Control through the general (userid-less) table pipeline: no bypass there,
        // the condition alone decides.
        $this->assertFalse(
            $this->is_visible_for($unbookedstudent, $option->id),
            'general list: the unbooked, condition-failing user must not see the option'
        );
    }

    /**
     * G5: users with mod/booking:updatebooking on the cm (per archetype: managers
     * and course creators) and cashiers (local/shopping_cart:cashier at system
     * level) see the unfiltered list.
     *
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_updatebooking_and_cashier_capability_bypass(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);

        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort one', 'idnumber' => 'CO1']);

        $manager = $this->getDataGenerator()->create_user();
        $cashier = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        // The manager role carries mod/booking:updatebooking per archetype.
        $this->getDataGenerator()->enrol_user($manager->id, $course1->id, 'manager');
        $this->getDataGenerator()->enrol_user($student->id, $course1->id);

        if (class_exists('local_shopping_cart\shopping_cart')) {
            $roleid = create_role('Cashier role', 'cashierrole', 'Cashier test role');
            assign_capability(
                'local/shopping_cart:cashier',
                CAP_ALLOW,
                $roleid,
                context_system::instance()->id
            );
            role_assign($roleid, $cashier->id, context_system::instance()->id);
        }

        $record = $this->base_option_record($booking1, $course1, 'Requires cohort 1');
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
        $option = $this->plugingenerator->create_option($record);

        // Control: an ordinary student outside the cohort is hidden.
        $this->assertFalse(
            $this->is_visible_for($student, $option->id),
            'control: a student outside the cohort must not see the option'
        );

        // The manager is not in the cohort either but must see everything.
        $this->assertTrue(
            $this->is_visible_for($manager, $option->id),
            'a user with mod/booking:updatebooking on the cm must see the unfiltered list'
        );

        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertTrue(
                $this->is_visible_for($cashier, $option->id),
                'a cashier must see the unfiltered list'
            );
        }
    }

    /**
     * G6: an option with SEVERAL filter conditions hides the user as soon as one
     * of them fails (AND semantics between conditions).
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincourse::return_sql
     * @covers \mod_booking\bo_availability\conditions\enrolledincohorts::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_multiple_conditions_on_one_option_all_must_pass(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);
        $courseb = $this->getDataGenerator()->create_course();
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort one', 'idnumber' => 'CO1']);

        $userboth = $this->getDataGenerator()->create_user();
        $courseonly = $this->getDataGenerator()->create_user();
        $cohortonly = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($userboth->id, $courseb->id);
        cohort_add_member($cohort1->id, $userboth->id);
        $this->getDataGenerator()->enrol_user($courseonly->id, $courseb->id);
        cohort_add_member($cohort1->id, $cohortonly->id);
        foreach ([$userboth, $courseonly, $cohortonly] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        }

        $record = $this->base_option_record($booking1, $course1, 'Requires course B AND cohort 1');
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$courseb->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
        $option = $this->plugingenerator->create_option($record);

        $this->assertTrue(
            $this->is_visible_for($userboth, $option->id),
            'a user passing both conditions must see the option'
        );
        $this->assertFalse(
            $this->is_visible_for($courseonly, $option->id),
            'a user failing the cohort condition must be hidden although the course condition passes'
        );
        $this->assertFalse(
            $this->is_visible_for($cohortonly, $option->id),
            'a user failing the course condition must be hidden although the cohort condition passes'
        );
    }

    /**
     * G7: a user with completely empty sets (no course enrolments, no cohorts, no
     * profile field value) is hidden from every filtered option and still sees the
     * unfiltered one - the empty-set SQL branches of all three user conditions.
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincourse::return_sql
     * @covers \mod_booking\bo_availability\conditions\enrolledincohorts::return_sql
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_user_with_empty_sets_is_hidden_from_all_filtered_options(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);
        $courseb = $this->getDataGenerator()->create_course();
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort one', 'idnumber' => 'CO1']);
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'department',
            'name' => 'Department',
        ]);

        // The user is enrolled nowhere, member of nothing and has no profile value.
        $emptyuser = $this->getDataGenerator()->create_user();

        $record = $this->base_option_record($booking1, $course1, 'Requires course B');
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$courseb->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;
        $courseoption = $this->plugingenerator->create_option($record);

        $record = $this->base_option_record($booking1, $course1, 'Requires cohort 1');
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
        $cohortoption = $this->plugingenerator->create_option($record);

        $record = $this->base_option_record($booking1, $course1, 'Requires department sales');
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'department';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'sales';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;
        $profileoption = $this->plugingenerator->create_option($record);

        $unfiltered = $this->plugingenerator->create_option(
            $this->base_option_record($booking1, $course1, 'No filter at all')
        );

        $this->assertFalse(
            $this->is_visible_for($emptyuser, $courseoption->id),
            'empty sets: user without any enrolment must be hidden from the course-filtered option'
        );
        $this->assertFalse(
            $this->is_visible_for($emptyuser, $cohortoption->id),
            'empty sets: user without any cohort must be hidden from the cohort-filtered option'
        );
        $this->assertFalse(
            $this->is_visible_for($emptyuser, $profileoption->id),
            'empty sets: user without a profile value must be hidden from the profile-filtered option'
        );
        $this->assertTrue(
            $this->is_visible_for($emptyuser, $unfiltered->id),
            'empty sets: the unfiltered option must stay visible even for a user with empty sets'
        );
    }

    /**
     * G9: the booking-time SQL filter hides options outside their booking window
     * (not yet open, already closed) and shows options with an open window.
     *
     * @covers \mod_booking\bo_availability\conditions\booking_time::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_bookingtime_window_sql_filter(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course1->id);

        $makeoption = function (int $opening, int $closing, string $text) use ($booking1, $course1): stdClass {
            $record = $this->base_option_record($booking1, $course1, $text);
            $record->restrictanswerperiodopening = 1;
            $record->restrictanswerperiodclosing = 1;
            $record->bookingopeningtime = $opening;
            $record->bookingclosingtime = $closing;
            $record->bo_cond_booking_time_sqlfiltercheck = 1;
            return $this->plugingenerator->create_option($record);
        };

        $open = $makeoption(strtotime('now - 1 day'), strtotime('now + 1 day'), 'Window open');
        $notyet = $makeoption(strtotime('now + 1 day'), strtotime('now + 2 day'), 'Window not open yet');
        $closed = $makeoption(strtotime('now - 2 day'), strtotime('now - 1 day'), 'Window already closed');

        $this->assertTrue(
            $this->is_visible_for($student, $open->id),
            'an option with an open booking window must be visible'
        );
        $this->assertFalse(
            $this->is_visible_for($student, $notyet->id),
            'an option whose booking window has not opened yet must be hidden'
        );
        $this->assertFalse(
            $this->is_visible_for($student, $closed->id),
            'an option whose booking window has already closed must be hidden'
        );
    }

    /**
     * G10: a condition with TWO connected profile fields (field && field2) through
     * the SQL list path. This guards in particular that the second field survives
     * the relevance trimming: if field2 were trimmed away, the user value would
     * read as empty and "status != inactive" would wrongly pass for an inactive
     * user.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::sqlfilter_referenced_values
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_userprofilefield_two_connected_fields_sql_path(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);

        $deptfield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'depttwo',
            'name' => 'Department two',
        ]);
        $statusfield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'statustwo',
            'name' => 'Status two',
        ]);

        $activesales = $this->getDataGenerator()->create_user();
        $inactivesales = $this->getDataGenerator()->create_user();
        $activemarketing = $this->getDataGenerator()->create_user();
        $this->set_profile_value($activesales, $deptfield, 'sales');
        $this->set_profile_value($activesales, $statusfield, 'active');
        $this->set_profile_value($inactivesales, $deptfield, 'sales');
        $this->set_profile_value($inactivesales, $statusfield, 'inactive');
        $this->set_profile_value($activemarketing, $deptfield, 'marketing');
        $this->set_profile_value($activemarketing, $statusfield, 'active');
        foreach ([$activesales, $inactivesales, $activemarketing] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course1->id);
        }

        $record = $this->base_option_record($booking1, $course1, 'Requires sales AND not inactive');
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'depttwo';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'sales';
        $record->bo_cond_customuserprofilefield_connectsecondfield = '&&';
        $record->bo_cond_customuserprofilefield_field2 = 'statustwo';
        $record->bo_cond_customuserprofilefield_operator2 = '!=';
        $record->bo_cond_customuserprofilefield_value2 = 'inactive';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;
        $option = $this->plugingenerator->create_option($record);

        $this->assertTrue(
            $this->is_visible_for($activesales, $option->id),
            'two fields (&&): a sales user who is not inactive must see the option'
        );
        $this->assertFalse(
            $this->is_visible_for($inactivesales, $option->id),
            'two fields (&&): an INACTIVE sales user must be hidden - if this fails, the second field '
                . 'was lost (e.g. trimmed away) and its empty value wrongly passed the != check'
        );
        $this->assertFalse(
            $this->is_visible_for($activemarketing, $option->id),
            'two fields (&&): a non-sales user must be hidden although the second field passes'
        );
    }

    /**
     * G11: conditions no option on the site uses are skipped in the filter SQL -
     * their fragments and params must not appear in the WHERE. This covers both
     * detection contracts: json-visible usage (course/cohort/profile conditions)
     * and marker-column-signalled usage (booking time, whose day-bucketed params
     * would otherwise rotate every cache key daily on sites not even using it).
     * Creating an option with a previously unused condition brings its fragment
     * back (proves the relevance cache invalidation).
     *
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     * @covers \mod_booking\bo_availability\sqlfilter_relevance::condition_is_skippable
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_unused_conditions_are_skipped_in_sql(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);
        $courseb = $this->getDataGenerator()->create_course();
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort one', 'idnumber' => 'CO1']);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student->id, $courseb->id);
        cohort_add_member($cohort1->id, $student->id);

        // Only a COURSE filtered option exists.
        $record = $this->base_option_record($booking1, $course1, 'Requires course B');
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$courseb->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;
        $this->plugingenerator->create_option($record);

        $this->setUser($student);
        [, , , $params, $where] = bo_info::return_sql_from_conditions((int) $student->id);

        $this->assertStringContainsString('courseids', $where, 'the used course condition must contribute its SQL');
        $this->assertStringNotContainsString(
            'bookingopeningtime',
            $where,
            'without any option carrying the booking time marker its (day-bucketed!) SQL must be skipped'
        );
        $this->assertStringNotContainsString(
            'cohortids',
            $where,
            'the unused cohort condition must be skipped entirely'
        );
        $profileparams = array_filter(
            array_keys($params),
            static fn(string $key): bool => str_starts_with($key, 'profilevalue')
        );
        $this->assertSame(
            [],
            $profileparams,
            'the unused profile field condition must not contribute any user value params'
        );

        // Creating a COHORT filtered option invalidates the relevance cache and
        // brings the cohort fragment back.
        $this->setAdminUser();
        $record = $this->base_option_record($booking1, $course1, 'Requires cohort 1');
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
        $this->plugingenerator->create_option($record);

        $this->setUser($student);
        [, , , , $where] = bo_info::return_sql_from_conditions((int) $student->id);
        $this->assertStringContainsString(
            'cohortids',
            $where,
            'after creating a cohort filtered option its condition must contribute SQL again'
        );

        // Creating an option with the booking time SQL filter sets the marker
        // column and brings the (marker-signalled) time fragment back.
        $this->setAdminUser();
        $record = $this->base_option_record($booking1, $course1, 'Open booking window');
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;
        $record->bookingopeningtime = strtotime('now - 1 day');
        $record->bookingclosingtime = strtotime('now + 1 day');
        $record->bo_cond_booking_time_sqlfiltercheck = 1;
        $this->plugingenerator->create_option($record);

        $this->setUser($student);
        [, , , , $where] = bo_info::return_sql_from_conditions((int) $student->id);
        $this->assertStringContainsString(
            'bookingopeningtime',
            $where,
            'once an option carries the booking time marker its condition must contribute SQL again'
        );
    }

    /**
     * G12: at the midnight rollover of the day-bucketed booking time params the
     * previous day's table cache generation becomes unreachable; the booking
     * time condition must detect the bucket change once and queue the dated
     * cache purge task, which empties the affected caches.
     *
     * @covers \mod_booking\bo_availability\conditions\booking_time::return_sql
     * @covers \mod_booking\task\purge_dated_table_caches
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_day_rollover_queues_dated_cache_purge(array $bdata): void {
        [$course1, $booking1] = $this->seed_instance($bdata);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course1->id);

        // An option with the booking time SQL filter (open window).
        $record = $this->base_option_record($booking1, $course1, 'Open booking window');
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;
        $record->bookingopeningtime = strtotime('now - 1 day');
        $record->bookingclosingtime = strtotime('now + 1 day');
        $record->bo_cond_booking_time_sqlfiltercheck = 1;
        $this->plugingenerator->create_option($record);

        $taskclass = '\mod_booking\task\purge_dated_table_caches';
        $todaybucket = strtotime('today 00:00');

        // First use ever: the bucket is recorded, but there is nothing stale yet.
        $this->setUser($student);
        bo_info::return_sql_from_conditions((int) $student->id);
        $this->assertEquals(
            $todaybucket,
            (int) get_config('booking', 'sqlfilterdaybucket'),
            'the first filter SQL build must record the current day bucket'
        );
        $this->assertCount(
            0,
            \core\task\manager::get_adhoc_tasks($taskclass),
            'the very first bucket recording must not queue a purge (nothing is stale yet)'
        );

        // Simulate the midnight rollover: yesterday's bucket is on record.
        set_config('sqlfilterdaybucket', strtotime('yesterday 00:00'), 'booking');
        bo_info::return_sql_from_conditions((int) $student->id);
        $this->assertEquals(
            $todaybucket,
            (int) get_config('booking', 'sqlfilterdaybucket'),
            'the rollover must update the recorded day bucket'
        );
        $tasks = \core\task\manager::get_adhoc_tasks($taskclass);
        $this->assertCount(1, $tasks, 'the rollover must queue exactly one dated cache purge task');

        // Further filter SQL builds on the same day must not queue anything else.
        bo_info::return_sql_from_conditions((int) $student->id);
        $this->assertCount(
            1,
            \core\task\manager::get_adhoc_tasks($taskclass),
            'repeated builds within the same day must not queue further purge tasks'
        );

        // The task empties the caches holding day-bucketed table data.
        $bookingtablecache = \cache::make('mod_booking', 'bookingoptionstable');
        $encodedtablescache = \cache::make('local_wunderbyte_table', 'encodedtables');
        $bookingtablecache->set('dummyentry', 'stale');
        $encodedtablescache->set('dummyentry', 'stale');

        $task = reset($tasks);
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertFalse(
            $bookingtablecache->get('dummyentry'),
            'the purge task must empty the booking options table cache'
        );
        $this->assertFalse(
            $encodedtablescache->get('dummyentry'),
            'the purge task must empty the encoded tables cache'
        );
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test SQL filter semantics',
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
