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
 * C4 (M4, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): the migration must be idempotent
 * (running it twice must not duplicate anything) and a no-op for options without any running
 * old-format chains (no side effects, no new rows, no mail).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Migration test for idempotency and no-op behaviour (M4).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §6
 * (still "Entwurf, noch nicht final abgenommen" at the time this test was written) - same
 * caveats as C1-C3: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them. The assertions' INTENT is the stable part.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\upgrade_step::run
 * @runInSeparateProcess
 */
final class waitlist_migration_c4_idempotency_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The three planned Phase 2 classes this test needs. All three not existing yet is the
     * expected state throughout Phase 1 - the test stays skipped until Phase 2 lands them.
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\migration\upgrade_step')
            && class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository');
    }

    /**
     * C4a (M4, idempotency): running upgrade_step::run() twice must produce the exact same
     * end state as running it once - no duplicated offers, no double-counted rows.
     */
    public function test_c4a_running_migration_twice_produces_identical_state(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'upgrade_step/progression_factory/db_waitlist_offer_repository do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

        $fixture = $this->build_running_mail_interval_chain(3);
        $upgradestepclass = '\mod_booking\local\waitlist\migration\upgrade_step';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $repository = new $repositoryclass();

        // First run.
        $upgradestepclass::run();
        $openoffersafterfirst = $repository->get_open_offers((int) $fixture->option->id);
        $unbehandeltafterfirst = $repository->get_unbehandelte_waitinglist((int) $fixture->option->id, []);

        // Second run - must not throw, and must not change the outcome at all.
        $exceptionthrown = null;
        try {
            $upgradestepclass::run();
        } catch (\Throwable $e) {
            $exceptionthrown = $e;
        }
        $this->assertNull(
            $exceptionthrown,
            'C4/M4: running the migration a second time must not throw. Got: '
                . ($exceptionthrown ? get_class($exceptionthrown) . ': ' . $exceptionthrown->getMessage() : '')
        );

        $openoffersaftersecond = $repository->get_open_offers((int) $fixture->option->id);
        $unbehandeltaftersecond = $repository->get_unbehandelte_waitinglist((int) $fixture->option->id, []);

        $idsafterfirst = array_map(fn($o) => (int) $o->id, $openoffersafterfirst);
        sort($idsafterfirst);
        $idsaftersecond = array_map(fn($o) => (int) $o->id, $openoffersaftersecond);
        sort($idsaftersecond);
        $this->assertEquals(
            $idsafterfirst,
            $idsaftersecond,
            'C4/M4: the second migration run must produce the exact same set of open offer rows ' .
            '- no duplicates, none dropped.'
        );

        $unbehandeltuseridsfirst = array_map(fn($u) => (int) ($u->userid ?? $u->id), $unbehandeltafterfirst);
        sort($unbehandeltuseridsfirst);
        $unbehandeltuseridssecond = array_map(fn($u) => (int) ($u->userid ?? $u->id), $unbehandeltaftersecond);
        sort($unbehandeltuseridssecond);
        $this->assertEquals(
            $unbehandeltuseridsfirst,
            $unbehandeltuseridssecond,
            'C4/M4: the set of still-unhandled waiting-list users must be identical after both runs.'
        );

        // No user may have accumulated more than one open offer across the two runs.
        $alluserids = array_map(fn($o) => (int) $o->userid, $openoffersaftersecond);
        $this->assertEquals(
            count(array_unique($alluserids)),
            count($alluserids),
            'C4/M4: no user may end up with more than one open offer after running the migration twice.'
        );
    }

    /**
     * C4b (M4, no-op): an option with no running old-format chain at all must be completely
     * unaffected by the migration - no new offer rows, no mail, no exception.
     */
    public function test_c4b_option_without_running_chain_is_a_pure_noop(): void {
        global $DB;

        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'upgrade_step/progression_factory/db_waitlist_offer_repository do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // A plain option, no waiting list ever formed (only one student, plenty of seats,
        // no rule configured at all) - genuinely nothing for the migration to do.
        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'no-op-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->maxoverbooking = 5;
        $record->waitforconfirmation = 0;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new \mod_booking\bo_availability\bo_info($settings);

        $this->setUser($student);
        singleton_service::destroy_user($student->id);
        \mod_booking\booking_bookit::bookit('option', $settings->id, $student->id);
        [$id] = $boinfo->is_available($settings->id, $student->id, false);
        if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
            \mod_booking\booking_bookit::bookit('option', $settings->id, $student->id);
            [$id] = $boinfo->is_available($settings->id, $student->id, true);
        }
        if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
            $this->setAdminUser();
            $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
            $optionobj->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);
            [$id] = $boinfo->is_available($settings->id, $student->id, true);
        }
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'Precondition: the student must be plainly booked, never on a waiting list at all.'
        );
        $this->setAdminUser();

        $answercountbefore = $DB->count_records('booking_answers', ['optionid' => $option->id]);

        $sink = $this->redirectMessages();
        ob_start();
        $exceptionthrown = null;
        try {
            $upgradestepclass = '\mod_booking\local\waitlist\migration\upgrade_step';
            $upgradestepclass::run();
        } catch (\Throwable $e) {
            $exceptionthrown = $e;
        }
        ob_get_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertNull(
            $exceptionthrown,
            'C4/M4: the migration must not throw for an option with no old-format chain at all.'
        );
        $this->assertCount(0, $messages, 'C4/M4: a no-op option must not cause any mail to be sent.');

        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $repository = new $repositoryclass();
        $openoffers = $repository->get_open_offers((int) $option->id);
        $this->assertCount(
            0,
            $openoffers,
            'C4/M4: an option with no running old-format chain must end up with zero offer rows.'
        );

        // The booking_answers table itself must be completely untouched by the migration.
        $answercountafter = $DB->count_records('booking_answers', ['optionid' => $option->id]);
        $this->assertEquals(
            $answercountbefore,
            $answercountafter,
            'C4/M4: a no-op option\'s booking_answers rows must be untouched by the migration.'
        );
    }
}
