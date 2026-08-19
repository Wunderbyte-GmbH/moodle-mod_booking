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
 * B7 (G1, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md / plan Phase 4 point 4): the
 * progression state of an option - which offers are open (with deadlines), who ended up
 * autobooked, who is permanently declined (K7), who is still genuinely unbehandelt - must be
 * fully and correctly queryable through the repository, for a Support-Playbook-style monitoring
 * view. Unlike B1-B6, this test deliberately does NOT need any additional Phase 2 class beyond
 * what B1-B6 already established (`progression_factory`, `db_waitlist_offer_repository`,
 * `offer_status`) - G1 is satisfied structurally by composing the already-specified repository
 * contract (`get_open_offers()`, `get_unbehandelte_waitinglist()`, `is_permanently_declined()`),
 * not by a dedicated new "monitoring" class (WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md's
 * 20-node class graph has no such node) - proving that IS the point of this test.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use mod_booking\bo_availability\bo_info;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Target-behaviour test for G1's queryable progression state view (B7) - the final item of
 * Kategorie B.
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.2
 * (still "Entwurf, noch nicht final abgenommen" at the time this test was written) - same
 * caveats as B1-B6/C1-C5: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::get_open_offers
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::get_unbehandelte_waitinglist
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::is_permanently_declined
 * @runInSeparateProcess
 */
final class waitlist_target_b7_state_view_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The three planned Phase 2 classes this test needs - the same three already used
     * throughout B1-B6, no additional "monitoring" class required (see class docblock).
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository')
            && interface_exists('\mod_booking\local\waitlist\offer_status');
    }

    /**
     * B7 (G1): for an option in a genuinely mixed state (one open offer, one autobooking, one
     * permanent K7 decline, one further promotion after that decline, one still fully
     * untouched), the repository must give a complete, correct, non-overlapping account of
     * every single candidate's state - nobody falls through the cracks.
     */
    public function test_b7_repository_gives_a_complete_state_view_for_a_mixed_option(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'progression_factory/db_waitlist_offer_repository/offer_status do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $occupants = [];
        for ($i = 0; $i < 3; $i++) {
            $occupants[] = $this->getDataGenerator()->create_user();
        }

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'freecat',
            'identifier' => 'freecat',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 2,
            'name' => 'paidcat',
            'identifier' => 'paidcat',
            'defaultvalue' => 80,
            'pricecatsortorder' => 2,
        ]);

        // FIFO join order, five candidates covering every distinct end state B7 must account
        // for: free -> autobooked; paid -> stays open; paid -> gets offered then declined
        // (permanent K7 lock); paid -> promoted only AFTER the decline frees a slot back up;
        // paid -> never reached at all.
        $wluserautobooked = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'freecat']);
        $wluseropen = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $wluserdeclined = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $wluserpromoted = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $wluseruntouched = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $waitlistusers = [$wluserautobooked, $wluseropen, $wluserdeclined, $wluserpromoted, $wluseruntouched];

        foreach (array_merge($occupants, $waitlistusers) as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
        }
        $this->setAdminUser();

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'b7-state-view';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 3;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
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

        // K11: progression::reconcile() only acts when an active send_mail_interval rule applies
        // - this test predates rule_condition_checker, so a plain ALWAYS rule is added here.
        $plugingenerator->create_rule([
            'name' => 'b7-interval-rule',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']),
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Fill all three seats.
        foreach ($occupants as $occupant) {
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
        }
        $this->setAdminUser();

        // Five waiting-list users, in strict join order.
        foreach ($waitlistusers as $u) {
            $this->setUser($u);
            singleton_service::destroy_user($u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            [$id] = $boinfo->is_available($settings->id, $u->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                'Precondition: every candidate must actually reach ONWAITINGLIST.'
            );
        }
        $this->setAdminUser();

        // Free all three seats at once.
        foreach ($occupants as $occupant) {
            $this->setUser($occupant);
            $optionobj->user_delete_response($occupant->id);
        }
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $progression = $factoryclass::get();
        $repository = new $repositoryclass();

        // Round 1: min(5, 3) = 3 treated - wluserautobooked, wluseropen, wluserdeclined.
        $progression->reconcile((int) $option->id, 'b7_test_round1');

        $round1offers = $repository->get_open_offers((int) $option->id);
        $round1offeruserids = array_map(fn($o) => (int) $o->userid, $round1offers);
        $declinedoffer = null;
        foreach ($round1offers as $o) {
            if ((int) $o->userid === (int) $wluserdeclined->id) {
                $declinedoffer = $o;
                break;
            }
        }
        $this->assertNotNull($declinedoffer, 'Precondition: wluserdeclined must have an offer after round 1.');

        // Decline it (T4 simulation, same pattern as B1) - frees a slot back up.
        $repository->transition($declinedoffer, new \mod_booking\local\waitlist\offer_statuses\declined());
        $progression->reconcile((int) $option->id, 'b7_test_after_decline');

        // Final state view - the actual G1 assertions.
        $openoffers = $repository->get_open_offers((int) $option->id);
        $openofferuserids = array_map(fn($o) => (int) $o->userid, $openoffers);

        $declineduserids = array_filter(
            array_map(fn($u) => (int) $u->id, $waitlistusers),
            fn($uid) => $repository->is_permanently_declined((int) $option->id, $uid)
        );
        $unbehandelt = $repository->get_unbehandelte_waitinglist((int) $option->id, array_values($declineduserids));
        $unbehandeltuserids = array_map(fn($u) => (int) ($u->userid ?? $u->id), $unbehandelt);

        // 1. wluserautobooked: a real, terminal booking - not an open offer.
        [$autobookedid] = $boinfo->is_available($settings->id, $wluserautobooked->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $autobookedid,
            'B7/G1: the autobooked candidate must show up as genuinely, immediately booked.'
        );
        $this->assertNotContains(
            (int) $wluserautobooked->id,
            $openofferuserids,
            'B7/G1: the autobooked candidate must not also appear as an open offer.'
        );

        // 2. wluseropen: still has its original open offer, with a real expiry deadline.
        $this->assertContains((int) $wluseropen->id, $openofferuserids, 'B7/G1: wluseropen must have an open offer.');
        $openoffer = null;
        foreach ($openoffers as $o) {
            if ((int) $o->userid === (int) $wluseropen->id) {
                $openoffer = $o;
                break;
            }
        }
        $this->assertNotNull($openoffer);
        $this->assertNotEmpty(
            $openoffer->expiresat ?? null,
            'B7/G1: an open offer must carry a queryable deadline (expiresat) - the "Fristen" ' .
            'part of "Offers/Status/Fristen pro Option abfragbar".'
        );

        // 3. wluserdeclined: permanently locked, not an open offer, not unbehandelt.
        $this->assertTrue(
            $repository->is_permanently_declined((int) $option->id, (int) $wluserdeclined->id),
            'B7/G1: the declined candidate must show up as permanently declined (K7).'
        );
        $this->assertNotContains(
            (int) $wluserdeclined->id,
            $openofferuserids,
            'B7/G1: the declined candidate must not appear as an open offer.'
        );
        $this->assertNotContains(
            (int) $wluserdeclined->id,
            $unbehandeltuserids,
            'B7/G1: the declined candidate must not appear as unbehandelt either.'
        );

        // 4. wluserpromoted: got the slot freed up by the decline - has an open offer too.
        $this->assertContains(
            (int) $wluserpromoted->id,
            $openofferuserids,
            'B7/G1: the candidate promoted after the decline must have an open offer.'
        );

        // 5. wluseruntouched: genuinely never reached - unbehandelt, no offer, still on the
        // plain waiting list.
        $this->assertContains(
            (int) $wluseruntouched->id,
            $unbehandeltuserids,
            'B7/G1: the never-reached candidate must show up as unbehandelt.'
        );
        $this->assertNotContains(
            (int) $wluseruntouched->id,
            $openofferuserids,
            'B7/G1: the never-reached candidate must not appear as an open offer.'
        );
        [$untouchedid] = $boinfo->is_available($settings->id, $wluseruntouched->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $untouchedid,
            'B7/G1: the never-reached candidate must still just be plainly ONWAITINGLIST.'
        );

        // Completeness: every one of the five candidates is accounted for in EXACTLY one of
        // {open offer, autobooked, permanently declined, unbehandelt} - nobody falls through
        // the cracks, and nobody is double-counted across buckets.
        $autobookeduserids = [(int) $wluserautobooked->id];
        $buckets = [
            'open' => $openofferuserids,
            'autobooked' => $autobookeduserids,
            'declined' => array_values($declineduserids),
            'unbehandelt' => $unbehandeltuserids,
        ];
        $allbucketed = array_merge(...array_values($buckets));
        sort($allbucketed);
        $expectedall = array_map(fn($u) => (int) $u->id, $waitlistusers);
        sort($expectedall);
        $this->assertEquals(
            $expectedall,
            $allbucketed,
            'B7/G1: every single candidate must be accounted for across the four state buckets.'
        );
        $this->assertEquals(
            5,
            count($allbucketed),
            'B7/G1: no candidate may appear in more than one bucket at once.'
        );
    }
}
