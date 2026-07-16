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
 * Hard-enforcing tests for the SQL availability filter: numeric and list operators.
 *
 * These tests pin the *intended* cross-database behaviour of the comparison
 * operators built by {@see \mod_booking\local\sql\operator_builder}. They are
 * deliberately written as strict behavioural assertions on the SQL-filter
 * visibility path ({@see mod_booking_generator::create_table_for_one_option}),
 * because that is the path the operators actually drive.
 *
 * IMPORTANT - why some assertions may currently be RED on PostgreSQL:
 * For the '<' and '>' operators, operator_builder builds a *textual* comparison
 * on PostgreSQL (operator_builder.php, build_postgres_check: "... < $condval"
 * where both sides are cast ::text), but a *numeric* comparison on MySQL/MariaDB
 * (CAST(... AS SIGNED)). Textual comparison makes '9' < '10' evaluate to FALSE.
 * These tests enforce the numeric interpretation (9 < 10 == true). If they fail
 * on PostgreSQL, that failure is the finding: the two DB families disagree and
 * the PostgreSQL branch must cast to a numeric type to become DB-agnostic.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Hard-enforcing tests for numeric and list operators of the SQL availability filter.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_sqlfilter_numeric_operator_test extends booking_advanced_testcase {
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
     * The '<' operator must compare NUMERICALLY on every supported DB family.
     *
     * Discriminating case: user value "9" against condition value "10".
     *  - Numeric (intended):   9 < 10  => available  => option visible.
     *  - Textual (pg current): '9' < '10' => false   => option hidden (BUG).
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_lessthan_operator_is_numeric_across_dbs(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');

        [$course1, $booking1] = $this->create_course_and_booking($bdata);

        $this->setAdminUser();

        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'seniorityyears',
            'name' => 'Seniority years',
        ]);

        // Junior "9" must qualify for "< 10" (9 < 10). Senior "20" must not (20 < 10 is false).
        $junior = $this->getDataGenerator()->create_user();
        $senior = $this->getDataGenerator()->create_user();
        $this->set_profile_value($DB, $junior, $profilefield, '9');
        $this->set_profile_value($DB, $senior, $profilefield, '20');
        $this->getDataGenerator()->enrol_user($junior->id, $course1->id);
        $this->getDataGenerator()->enrol_user($senior->id, $course1->id);

        $record = $this->base_option_record($booking1, $course1, 'Option requires seniority < 10');
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'seniorityyears';
        $record->bo_cond_customuserprofilefield_operator = '<';
        $record->bo_cond_customuserprofilefield_value = '10';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        $this->fix_page_context($booking1, $PAGE);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        // Junior (9 < 10) must SEE the option. This is the cross-DB enforcing assertion.
        $this->setUser($junior);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(
            1,
            count($rawdata),
            "User value '9' must satisfy '< 10' (numeric). A textual comparison ('9' < '10' = false) "
            . "would wrongly hide the option - that means the PostgreSQL branch of operator_builder "
            . "is comparing strings instead of numbers."
        );

        // Senior (20 < 10 is false) must NOT see the option.
        $this->setUser($senior);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata), "User value '20' must not satisfy '< 10'.");
    }

    /**
     * The '>' operator must compare NUMERICALLY on every supported DB family.
     *
     * Discriminating case: user value "10" against condition value "9".
     *  - Numeric (intended):   10 > 9  => available  => option visible.
     *  - Textual (pg current): '10' > '9' => false   => option hidden (BUG).
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_greaterthan_operator_is_numeric_across_dbs(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');

        [$course1, $booking1] = $this->create_course_and_booking($bdata);

        $this->setAdminUser();

        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'seniorityyears',
            'name' => 'Seniority years',
        ]);

        // Value "10" must qualify for "> 9" (10 > 9). "5" must not (5 > 9 is false).
        $tenyears = $this->getDataGenerator()->create_user();
        $fiveyears = $this->getDataGenerator()->create_user();
        $this->set_profile_value($DB, $tenyears, $profilefield, '10');
        $this->set_profile_value($DB, $fiveyears, $profilefield, '5');
        $this->getDataGenerator()->enrol_user($tenyears->id, $course1->id);
        $this->getDataGenerator()->enrol_user($fiveyears->id, $course1->id);

        $record = $this->base_option_record($booking1, $course1, 'Option requires seniority > 9');
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'seniorityyears';
        $record->bo_cond_customuserprofilefield_operator = '>';
        $record->bo_cond_customuserprofilefield_value = '9';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        $this->fix_page_context($booking1, $PAGE);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        // 10 > 9 must SEE the option. Cross-DB enforcing assertion.
        $this->setUser($tenyears);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(
            1,
            count($rawdata),
            "User value '10' must satisfy '> 9' (numeric). A textual comparison ('10' > '9' = false) "
            . "would wrongly hide the option - PostgreSQL branch compares strings instead of numbers."
        );

        // 5 > 9 is false: must NOT see the option.
        $this->setUser($fiveyears);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata), "User value '5' must not satisfy '> 9'.");
    }

    /**
     * The '[]' (value in comma-separated list) operator must behave identically on every DB family.
     *
     * This operator is implemented in operator_builder (postgres: = ANY(string_to_array),
     * mysql: FIND_IN_SET) but was previously untested. It enforces simple membership semantics.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_inlist_operator_membership_across_dbs(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');

        [$course1, $booking1] = $this->create_course_and_booking($bdata);

        $this->setAdminUser();

        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'team',
            'name' => 'Team',
        ]);

        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->set_profile_value($DB, $member, $profilefield, 'green');
        $this->set_profile_value($DB, $outsider, $profilefield, 'yellow');
        $this->getDataGenerator()->enrol_user($member->id, $course1->id);
        $this->getDataGenerator()->enrol_user($outsider->id, $course1->id);

        $record = $this->base_option_record($booking1, $course1, 'Option requires team in {red,green,blue}');
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'team';
        $record->bo_cond_customuserprofilefield_operator = '[]';
        $record->bo_cond_customuserprofilefield_value = 'red,green,blue';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        $this->fix_page_context($booking1, $PAGE);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        // Value "green" is in the list -> visible.
        $this->setUser($member);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata), "User team 'green' is in the list and must see the option.");

        // Value "yellow" is not in the list -> hidden.
        $this->setUser($outsider);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata), "User team 'yellow' is not in the list and must not see the option.");
    }

    /**
     * Create a course (the booking course) and a booking module on it.
     *
     * @param array $bdata
     * @return array [stdClass $course1, stdClass $booking1]
     */
    private function create_course_and_booking(array $bdata): array {
        $bdata['cancancelbook'] = 1;
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        return [$course1, $booking1];
    }

    /**
     * Set a custom profile field value for a user via user_info_data (mirrors existing tests).
     *
     * @param \moodle_database $db
     * @param stdClass $user
     * @param stdClass $profilefield
     * @param string $value
     */
    private function set_profile_value(\moodle_database $db, stdClass $user, stdClass $profilefield, string $value): void {
        $db->insert_record('user_info_data', [
            'userid' => $user->id,
            'fieldid' => $profilefield->id,
            'data' => $value,
        ]);
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
     * Fix the $PAGE context to the booking cm (required before option creation).
     *
     * @param stdClass $booking1
     * @param \moodle_page $page
     */
    private function fix_page_context(stdClass $booking1, \moodle_page $page): void {
        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $page->set_cm($cm, $course);
        $page->set_context(context_module::instance($booking1->cmid));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test SQL filter numeric operators',
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
