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
 * Fixture-Builder shared by the local/waitlist progression tests (progression_test.php and all
 * B/A/C/D/E/F behavior-test scenarios built on top of it, see
 * WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md). Extracted 2026-08-21 once the same ~150
 * lines had been copy-pasted verbatim into a third file (b7) - same rationale as the precedent
 * trait for the migration tests, waitlist_old_chain_fixture_trait.php.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\tests\local\waitlist;

use mod_booking\local\waitlist\capacity_calculator;
use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\moodle_messaging_gateway;
use mod_booking\local\waitlist\price_based_decision_strategy;
use mod_booking\local\waitlist\progression;
use mod_booking\local\waitlist\rule_condition_checker;
use mod_booking\singleton_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Trait providing the standard priced-option-with-waiting-list fixture used across the
 * progression test suite.
 *
 * Consumers must be a PHPUnit test case with the standard Moodle generator helpers available
 * (getDataGenerator(), setAdminUser()) - i.e. any \advanced_testcase subclass.
 */
trait waitlist_progression_fixture_trait {
    /**
     * Creates a course + booking with a custom 'pricecat' profile field wired up as the
     * price-category selector.
     *
     * @param string $name booking instance name, only cosmetic - no test asserts on it
     * @return array [\stdClass $course, \stdClass $teacher, \stdClass $booking]
     */
    protected function prepare_course_and_booking(string $name = 'Waitlist Progression Fixture'): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        $bdata = [
            'name' => $name,
            'eventtype' => 'Test',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'statuschangebody'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ];
        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        return [$course, $teacher, $booking];
    }

    /**
     * Creates one useprice=1 option. Must be called AFTER all needed price categories already
     * exist (price resolution happens at option-creation time).
     *
     * @param stdClass $course
     * @param stdClass $teacher
     * @param stdClass $booking
     * @param int $maxanswers
     * @param int $maxoverbooking
     * @return int the new option's id
     */
    protected function create_priced_option(
        stdClass $course,
        stdClass $teacher,
        stdClass $booking,
        int $maxanswers,
        int $maxoverbooking
    ): int {
        $this->setAdminUser();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'progression-fixture-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = $maxanswers;
        $record->maxoverbooking = $maxoverbooking;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return (int) $option->id;
    }

    /**
     * Creates one price category.
     *
     * @param string $identifier
     * @param float $value
     * @return void
     */
    protected function create_pricecategory(string $identifier, float $value): void {
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => $identifier,
            'identifier' => $identifier,
            'defaultvalue' => $value,
            'pricecatsortorder' => 1,
        ]);
    }

    /**
     * Creates one active rule_react_on_event + send_mail_interval rule.
     *
     * @param int $condition one of the rule_react_on_event::* constants
     * @param string $subject
     * @param string $template
     * @param int $intervalminutes
     * @return int the new rule's id
     */
    protected function create_interval_rule(
        int $condition,
        string $subject = 'progsubj',
        string $template = 'progtmpl',
        int $intervalminutes = 60
    ): int {
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $actstr = json_encode([
            'interval' => $intervalminutes,
            'subject' => $subject,
            'template' => $template,
            'templateformat' => '1',
        ]);
        $record = $plugingenerator->create_rule([
            'name' => 'progression-fixture-rule-' . $condition . '-' . $intervalminutes,
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) $condition,
        ]);
        return (int) $record->id;
    }

    /**
     * Creates a user with the given price-category profile value, enrols them into the course,
     * and puts them on the option's waiting list via a direct booking_answers insert.
     *
     * @param stdClass $course
     * @param int $optionid
     * @param string $pricecat
     * @param int $timemodified used for O1/O2 join-order control
     * @return stdClass the created user
     */
    protected function waitlist_user(stdClass $course, int $optionid, string $pricecat, int $timemodified): stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['profile_field_pricecat' => $pricecat]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $user->id,
            'optionid' => $optionid,
            'timemodified' => $timemodified,
            'timecreated' => $timemodified,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'status' => 0,
        ]);
        return $user;
    }

    /**
     * Builds a progression instance wired with real collaborators - same composition as
     * progression_factory::get(), duplicated here so tests do not depend on the factory's
     * internal wiring order.
     *
     * @return progression
     */
    protected function build_progression(): progression {
        return new progression(
            new db_waitlist_offer_repository(),
            new price_based_decision_strategy(),
            new capacity_calculator(new db_waitlist_offer_repository()),
            new rule_condition_checker(),
            new moodle_messaging_gateway()
        );
    }
}
