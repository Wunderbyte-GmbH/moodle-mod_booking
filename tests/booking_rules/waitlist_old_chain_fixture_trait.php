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
 * Fixture-Builder (WAITLIST_REFACTOR_IMPLEMENTATION_PLAN Phase 1b, item 1): builds real
 * mid-flight waitlist-progression chains through the CURRENT (pre-refactor) engine, so
 * Category C migration tests (C1-C5) exercise genuine old-format state instead of
 * hand-mocked task records.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\tests\booking_rules;

use stdClass;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_bookit;
use mod_booking\singleton_service;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Trait providing real, engine-driven fixtures of "old chain" waitlist-progression state.
 *
 * Consumers must be a PHPUnit test case with the standard Moodle generator helpers available
 * (getDataGenerator(), setUser(), setAdminUser(), runAdhocTasks()) - i.e. any
 * \advanced_testcase subclass.
 */
trait waitlist_old_chain_fixture_trait {
    /**
     * The bdata shape every booking_common_settings_provider() in this test suite uses.
     * Duplicated here deliberately (matches the codebase's existing per-file convention,
     * see e.g. booking_waitinglist_confirmation_test.php) rather than introducing a new
     * shared dependency.
     *
     * @return array
     */
    protected function fixture_bdata(): array {
        return [
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
            'cancancelbook' => 1,
        ];
    }

    /**
     * Builds a genuinely running send_mail_interval chain (M1 fixture): one waiting-list user
     * already treated (received their direct mail, "behandelt"), and a still-pending repeat
     * task carrying usersalreadytreated in its rulejson snapshot, ready to pick up the next
     * ($waitlistcount - 1) unbehandelt users on its next run.
     *
     * This is built entirely through the current (pre-refactor) engine - a real rule, a real
     * option, real bookit()/cancel calls, a real freetobookagain trigger, and the direct task
     * actually executed via runAdhocTasks() - not hand-constructed task_adhoc rows.
     *
     * @param int $waitlistcount total number of waiting-list users (>= 2)
     * @return stdClass {course, booking, option, settings, boinfo, optionobj, rule, teacher,
     *   occupant, waitlistusers (ordered array, join order), treateduser (already mailed),
     *   pendinguser (next in the still-pending repeat chain), repeattask (adhoc_task)}
     */
    protected function build_running_mail_interval_chain(int $waitlistcount = 3): stdClass {
        if ($waitlistcount < 2) {
            throw new \coding_exception('build_running_mail_interval_chain() needs at least 2 waiting-list users.');
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $occupant = $this->getDataGenerator()->create_user();
        $waitlistusers = [];
        for ($i = 0; $i < $waitlistcount; $i++) {
            $waitlistusers[] = $this->getDataGenerator()->create_user();
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($occupant->id, $course->id, 'student');
        foreach ($waitlistusers as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"","interval":60,';
        $actstr .= '"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata = [
            'name' => 'fixturechain',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule = $plugingenerator->create_rule($ruledata);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'fixture-mail-interval-chain';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Fill the single seat with the occupant.
        $this->setUser($occupant);
        singleton_service::destroy_user($occupant->id);
        booking_bookit::bookit('option', $settings->id, $occupant->id);
        [$id] = $boinfo->is_available($settings->id, $occupant->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $occupant->id);
            [$id] = $boinfo->is_available($settings->id, $occupant->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $optionobj->user_submit_response($occupant, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        // Every waiting-list user joins, in order.
        foreach ($waitlistusers as $u) {
            $this->setUser($u);
            singleton_service::destroy_user($u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            [$id] = $boinfo->is_available($settings->id, $u->id, true);
            if ($id !== MOD_BOOKING_BO_COND_ONWAITINGLIST) {
                throw new \coding_exception('Fixture setup failed: waiting-list user did not reach ONWAITINGLIST.');
            }
        }
        $this->setAdminUser();

        // Free the seat -> schedules the chain's first direct mail task + a repeat task.
        $this->setUser($occupant);
        $optionobj->user_delete_response($occupant->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        // Run only the DIRECT mail task (leave the repeat task pending) - this is what makes
        // the chain genuinely "running": one user already treated, the rest still pending.
        // Note: select_student_in_bo's forced-late-joiner branch also matches the now-DELETED
        // occupant row (see A9/A11 memory) and produces its own harmless task - filter it out
        // by only considering waiting-list users' own tasks. Also scope by THIS fixture's own
        // ruleid - the rule is created at system context (contextid=1), so a second, unrelated
        // build_running_mail_interval_chain() call within the same test (e.g. C3's orphaned-task
        // control group) would otherwise ALSO match here, since a system-context rule applies to
        // every option.
        $waitlistuserids = array_map(fn($u) => (int) $u->id, $waitlistusers);
        $taskclass = '\mod_booking\task\send_mail_by_rule_adhoc';
        $alltasks = \core\task\manager::get_adhoc_tasks($taskclass);
        $alltasks = array_filter(
            $alltasks,
            fn($t) => in_array((int) $t->get_custom_data()->userid, $waitlistuserids, true)
                && (int) $t->get_custom_data()->ruleid === (int) $rule->id
        );
        $directtasks = array_filter($alltasks, fn($t) => empty($t->get_custom_data()->repeat));
        $repeattasks = array_filter($alltasks, fn($t) => !empty($t->get_custom_data()->repeat));
        if (count($directtasks) !== 1 || count($repeattasks) !== 1) {
            throw new \coding_exception(
                'Fixture setup failed: expected exactly one direct and one repeat mail task, got '
                . count($directtasks) . ' direct, ' . count($repeattasks) . ' repeat.'
            );
        }
        $directtask = reset($directtasks);
        $treateduserid = (int) $directtask->get_custom_data()->userid;

        ob_start();
        $this->runAdhocTasks($taskclass, $treateduserid);
        ob_get_clean();
        $this->setAdminUser();

        $repeattasksafter = array_filter(
            \core\task\manager::get_adhoc_tasks($taskclass),
            fn($t) => !empty($t->get_custom_data()->repeat)
        );
        $repeattask = reset($repeattasksafter);

        $treateduser = null;
        $pendinguser = null;
        foreach ($waitlistusers as $u) {
            if ((int) $u->id === $treateduserid) {
                $treateduser = $u;
            } else if ($pendinguser === null) {
                $pendinguser = $u;
            }
        }

        return (object) [
            'course' => $course,
            'booking' => $booking,
            'option' => $option,
            'settings' => $settings,
            'boinfo' => $boinfo,
            'optionobj' => $optionobj,
            'rule' => $rule,
            'teacher' => $teacher,
            'occupant' => $occupant,
            'waitlistusers' => $waitlistusers,
            'treateduser' => $treateduser,
            'pendinguser' => $pendinguser,
            'repeattask' => $repeattask,
        ];
    }

    /**
     * Builds a genuinely OPEN confirm_bookinganswer offer (M2 fixture): one waiting-list user
     * has a pending, untouched direct confirm task - deliberately left un-executed, so it
     * represents an offer that is still open/awaiting the user's action at the moment a
     * migration would run.
     *
     * @param int $waitlistcount total number of waiting-list users (>= 2)
     * @param int $confirmationonnotification 1 (non-exclusive) or 2 (exclusive)
     * @return stdClass {course, booking, option, settings, boinfo, optionobj, rule, teacher,
     *   occupant, waitlistusers, offereduser (has the open task), confirmtask (adhoc_task,
     *   NOT executed)}
     */
    protected function build_running_confirm_chain(int $waitlistcount = 2, int $confirmationonnotification = 2): stdClass {
        if ($waitlistcount < 2) {
            throw new \coding_exception('build_running_confirm_chain() needs at least 2 waiting-list users.');
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $occupant = $this->getDataGenerator()->create_user();
        $waitlistusers = [];
        for ($i = 0; $i < $waitlistcount; $i++) {
            $waitlistusers[] = $this->getDataGenerator()->create_user();
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($occupant->id, $course->id, 'student');
        foreach ($waitlistusers as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ]);

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'fixtureconfirmchain',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule = $plugingenerator->create_rule($ruledata);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'fixture-confirm-chain';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->confirmationonnotification = $confirmationonnotification;
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

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->setUser($occupant);
        singleton_service::destroy_user($occupant->id);
        booking_bookit::bookit('option', $settings->id, $occupant->id);
        [$id] = $boinfo->is_available($settings->id, $occupant->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            booking_bookit::bookit('option', $settings->id, $occupant->id);
            [$id] = $boinfo->is_available($settings->id, $occupant->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $optionobj->user_submit_response($occupant, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        $this->setAdminUser();

        foreach ($waitlistusers as $u) {
            $this->setUser($u);
            booking_bookit::bookit('option', $settings->id, $u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            [$id] = $boinfo->is_available($settings->id, $u->id, true);
            if ($id !== MOD_BOOKING_BO_COND_ONWAITINGLIST) {
                throw new \coding_exception('Fixture setup failed: waiting-list user did not reach ONWAITINGLIST.');
            }
        }
        $this->setAdminUser();

        // Free the seat -> schedules the chain's first direct confirm task. Deliberately do
        // NOT run it: an untouched, still-open offer is exactly the M2 fixture.
        $this->setUser($occupant);
        $optionobj->user_delete_response($occupant->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        // Note: select_student_in_bo's forced-late-joiner branch also matches the now-DELETED
        // occupant row (see A9/A11 memory) and produces its own harmless task - filter it out
        // by only considering waiting-list users' own tasks.
        $waitlistuserids = array_map(fn($u) => (int) $u->id, $waitlistusers);
        $taskclass = '\mod_booking\task\confirm_bookinganswer_by_rule_adhoc';
        $alltasks = \core\task\manager::get_adhoc_tasks($taskclass);
        $alltasks = array_filter($alltasks, fn($t) => in_array((int) $t->get_custom_data()->userid, $waitlistuserids, true));
        $directtasks = array_filter($alltasks, fn($t) => empty($t->get_custom_data()->repeat));
        if (count($directtasks) !== 1) {
            throw new \coding_exception(
                'Fixture setup failed: expected exactly one open direct confirm task, got ' . count($directtasks) . '.'
            );
        }
        $confirmtask = reset($directtasks);
        $offereduserid = (int) $confirmtask->get_custom_data()->userid;

        $offereduser = null;
        foreach ($waitlistusers as $u) {
            if ((int) $u->id === $offereduserid) {
                $offereduser = $u;
                break;
            }
        }

        return (object) [
            'course' => $course,
            'booking' => $booking,
            'option' => $option,
            'settings' => $settings,
            'boinfo' => $boinfo,
            'optionobj' => $optionobj,
            'rule' => $rule,
            'teacher' => $teacher,
            'occupant' => $occupant,
            'waitlistusers' => $waitlistusers,
            'offereduser' => $offereduser,
            'confirmtask' => $confirmtask,
        ];
    }
}
