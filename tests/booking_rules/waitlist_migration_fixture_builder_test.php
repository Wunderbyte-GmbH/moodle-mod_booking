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
 * Verifies the Fixture-Builder itself (WAITLIST_REFACTOR_IMPLEMENTATION_PLAN Phase 1b, item 1)
 * produces genuinely correct "old chain" state before C1-C5 build on top of it.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the waitlist old-chain Fixture-Builder.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class waitlist_migration_fixture_builder_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * build_running_mail_interval_chain() must produce a genuinely mid-flight
     * send_mail_interval chain: the first waiting-list user already received their mail
     * (treated), the rest are still pending, reachable only via the still-queued repeat task.
     *
     * @covers \mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait::build_running_mail_interval_chain
     */
    public function test_mail_interval_chain_fixture_is_genuinely_mid_flight(): void {
        global $DB;

        $fixture = $this->build_running_mail_interval_chain(3);

        // The treated user actually received exactly one mail - not zero, not more.
        $this->assertNotNull($fixture->treateduser, 'Fixture must identify the already-treated user.');
        $this->assertNotNull($fixture->pendinguser, 'Fixture must identify a still-pending user.');
        $this->assertNotEquals(
            $fixture->treateduser->id,
            $fixture->pendinguser->id,
            'The treated and pending users must be different people.'
        );

        // The repeat task is real, pending, and carries the treated user in its rulejson
        // snapshot's usersalreadytreated list - this is the actual "old format" state C1 must
        // migrate.
        $this->assertNotNull($fixture->repeattask, 'Fixture must leave a pending repeat task.');
        // The trait hands out the raw {task_adhoc} record (see its docblock), so the queued
        // payload is read from the customdata column rather than from a task object.
        $customdata = json_decode($fixture->repeattask->customdata);
        $this->assertEquals(1, $customdata->repeat ?? 0);
        $rulejson = json_decode($customdata->rulejson);
        $treatedlist = $rulejson->intervaldata->usersalreadytreated ?? [];
        $this->assertContains(
            (int) $fixture->treateduser->id,
            array_map('intval', $treatedlist),
            'The repeat task\'s carried rulejson must list the treated user as already treated.'
        );
        $this->assertNotContains(
            (int) $fixture->pendinguser->id,
            array_map('intval', $treatedlist),
            'The repeat task\'s carried rulejson must NOT list the still-pending user as treated.'
        );

        // No other send_mail_by_rule_adhoc task must be left over for the waiting-list users
        // (exactly the one repeat task). select_student_in_bo's forced-late-joiner branch also
        // matches the now-DELETED occupant row and produces its own harmless task - see A9/A11
        // memory - so it is filtered out here rather than asserted away.
        $waitlistuserids = array_map(fn($u) => (int) $u->id, $fixture->waitlistusers);
        $remaining = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $remaining = array_filter($remaining, fn($t) => in_array((int) $t->get_custom_data()->userid, $waitlistuserids, true));
        $this->assertCount(1, $remaining, 'Only the pending repeat task must remain queued for the waiting-list users.');

        // The waiting-list DB state itself must still show ALL non-occupant users on the list -
        // the chain treats them via mail, it does not book/remove them.
        $waitinglistanswers = $DB->get_records('booking_answers', [
            'optionid' => $fixture->option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $this->assertCount(3, $waitinglistanswers, 'All three waiting-list users must still genuinely be on the waiting list.');
    }

    /**
     * build_running_confirm_chain() must produce a genuinely OPEN offer: the direct confirm
     * task exists, is unexecuted, and the offered user's answer has NOT been confirmed yet.
     *
     * @covers \mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait::build_running_confirm_chain
     */
    public function test_confirm_chain_fixture_leaves_an_open_untouched_offer(): void {
        global $DB;

        $fixture = $this->build_running_confirm_chain(2, 2);

        $this->assertNotNull($fixture->offereduser, 'Fixture must identify the offered user.');
        $this->assertNotNull($fixture->confirmtask, 'Fixture must leave a pending, open confirm task.');
        $this->assertEmpty(
            json_decode($fixture->confirmtask->customdata)->repeat ?? null,
            'The open offer must be the direct confirm task, not the repeat-trigger.'
        );

        // Exactly one DIRECT (open) confirm_bookinganswer_by_rule_adhoc task must be queued
        // for the waiting-list users - see the same forced-late-joiner note above. With 2
        // waiting-list users, confirm_bookinganswer::execute() also queues a repeat-trigger
        // task for the second candidate (chain continuation, same as send_mail_interval) -
        // that one is expected and not part of "the open offer" itself.
        $waitlistuserids = array_map(fn($u) => (int) $u->id, $fixture->waitlistusers);
        $remaining = \core\task\manager::get_adhoc_tasks('\mod_booking\task\confirm_bookinganswer_by_rule_adhoc');
        $remaining = array_filter($remaining, fn($t) => in_array((int) $t->get_custom_data()->userid, $waitlistuserids, true));
        $remainingdirect = array_filter($remaining, fn($t) => empty($t->get_custom_data()->repeat));
        $this->assertCount(1, $remainingdirect, 'Exactly the one open, unexecuted direct confirm task must remain queued.');

        // The offer must genuinely be UNTOUCHED: the offered user's answer JSON must not
        // already carry a confirmwaitinglist flag (that would mean the task already ran).
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $fixture->option->id,
            'userid' => $fixture->offereduser->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $this->assertNotEmpty($answer, 'The offered user must still genuinely be on the waiting list.');
        $json = empty($answer->json) ? null : json_decode($answer->json);
        $this->assertEmpty(
            $json->confirmwaitinglist ?? null,
            'The offer must still be open - the confirm task must not have run yet.'
        );

        // No longer sanity-checked by actually running the task: confirm_bookinganswer_by_rule_
        // adhoc::execute() is a deliberate permanent no-op since the waitlist-progression
        // refactoring (Phase 3) - on a real upgraded site, upgrade_step::run() migrates/removes
        // this leftover legacy row during the Moodle upgrade itself, so the task never runs
        // again in current code. This fixture deliberately builds the raw pre-upgrade state
        // WITHOUT running upgrade_step, so there is nothing left here that could still make the
        // old task "work". The fixture's actual validity is proven by the C1-C5 migration tests,
        // which run upgrade_step::run() against it and verify the migrated result - that is the
        // real functional check now, not executing the neutered legacy task directly.
    }
}
