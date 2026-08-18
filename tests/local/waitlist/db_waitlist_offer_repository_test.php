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
 * Tests for db_waitlist_offer_repository (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.2)
 * against the real booking_waitlist_offers/booking_waitlist_declines tables. No booking_bookit()
 * choreography is needed here - this is a repository-layer test, not an integration test (that
 * already happened extensively in the A/B/C suites against the OLD engine, and will happen again
 * once progression is built). booking_waitlist_offers.optionid/booking_answers.userid are NOT
 * DB-enforced foreign keys (verified via psql \d against the real schema), so plain arbitrary
 * ids are used freely.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\local\waitlist\offer_statuses\pending;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\local\waitlist\offer_statuses\declined;
use mod_booking\local\waitlist\offer_statuses\expired;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * DB-backed tests for db_waitlist_offer_repository.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository
 */
final class db_waitlist_offer_repository_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Inserts a minimal booking_answers row for a user on the waiting list of an option.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $timemodified
     * @return int the inserted booking_answers.id
     */
    private function insert_waitinglist_answer(int $optionid, int $userid, int $timemodified): int {
        global $DB;
        return $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $userid,
            'optionid' => $optionid,
            'timemodified' => $timemodified,
            'timecreated' => $timemodified,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'status' => 0,
        ]);
    }

    /**
     * create_offer() must persist all fields correctly, resolve baid from booking_answers, and
     * derive its timestamps from the injected clock rather than a bare time() call.
     */
    public function test_create_offer_persists_and_resolves_baid_and_uses_clock(): void {
        global $DB;

        $this->mock_clock_with_frozen(1000000000);
        $user = $this->getDataGenerator()->create_user();
        $optionid = 5001;
        $baid = $this->insert_waitinglist_answer($optionid, $user->id, 999999000);

        $repository = new db_waitlist_offer_repository();
        $status = new pending();
        $offer = $repository->create_offer($optionid, (int) $user->id, 7, 42, $status, 1000000600);

        $this->assertGreaterThan(0, $offer->id);
        $this->assertEquals($optionid, $offer->optionid);
        $this->assertEquals((int) $user->id, $offer->userid);
        $this->assertEquals($baid, $offer->baid, 'baid must be resolved from booking_answers, not left empty.');
        $this->assertEquals(7, $offer->roundid);
        $this->assertInstanceOf(pending::class, $offer->status);
        $this->assertEquals(42, $offer->sortorder);
        $this->assertEquals(1000000000, $offer->offeredat, 'offeredat must come from the injected clock.');
        $this->assertEquals(1000000600, $offer->expiresat);
        $this->assertEquals(1, $offer->version);
        $this->assertEquals(1000000000, $offer->timecreated);
        $this->assertEquals(1000000000, $offer->timemodified);

        // Persisted row itself: status must be the raw numeric code, not a serialised object.
        $raw = $DB->get_record('booking_waitlist_offers', ['id' => $offer->id], '*', MUST_EXIST);
        $this->assertEquals(0, (int) $raw->status, 'pending must persist as status code 0.');
    }

    /**
     * create_offer() with no matching booking_answers row must resolve baid to 0, not throw.
     */
    public function test_create_offer_without_matching_answer_resolves_baid_to_zero(): void {
        $repository = new db_waitlist_offer_repository();
        $offer = $repository->create_offer(5002, 999999, 1, 1, new pending());
        $this->assertEquals(0, $offer->baid);
    }

    /**
     * get_open_offers() must return only non-terminal (pending/offered) rows, ordered by
     * sortorder ASC, and must NOT include terminal rows.
     */
    public function test_get_open_offers_returns_only_non_terminal_ordered_by_sortorder(): void {
        $repository = new db_waitlist_offer_repository();
        $optionid = 5010;
        $userpending = $this->getDataGenerator()->create_user();
        $useroffered = $this->getDataGenerator()->create_user();
        $userdeclined = $this->getDataGenerator()->create_user();

        $repository->create_offer($optionid, (int) $useroffered->id, 1, 20, new offered());
        $repository->create_offer($optionid, (int) $userpending->id, 1, 10, new pending());
        $repository->create_offer($optionid, (int) $userdeclined->id, 1, 5, new declined());

        $openoffers = $repository->get_open_offers($optionid);

        $this->assertCount(2, $openoffers, 'Only the two non-terminal rows must be open.');
        $this->assertEquals((int) $userpending->id, $openoffers[0]->userid, 'sortorder=10 must come first.');
        $this->assertEquals((int) $useroffered->id, $openoffers[1]->userid, 'sortorder=20 must come second.');
        $openofferuserids = array_map(fn($o) => $o->userid, $openoffers);
        $this->assertNotContains((int) $userdeclined->id, $openofferuserids, 'declined must not be open.');
    }

    /**
     * get_unbehandelte_waitinglist() must exclude users with an open offer AND users in the
     * explicit exclude list, but must INCLUDE a user whose only row is terminal-but-not-declined
     * (e.g. expired) - re-eligibility for a later round, per expired.php's documented intent.
     * Must be ordered by original join time, then id as tie-break (O1/O2).
     */
    public function test_get_unbehandelte_waitinglist_scoping_and_ordering(): void {
        $repository = new db_waitlist_offer_repository();
        $optionid = 5020;

        $useropen = $this->getDataGenerator()->create_user();
        $userexcluded = $this->getDataGenerator()->create_user();
        $userexpired = $this->getDataGenerator()->create_user();
        $userlater = $this->getDataGenerator()->create_user();
        $usertiebreaklosing = $this->getDataGenerator()->create_user();
        $usertiebreakwinning = $this->getDataGenerator()->create_user();

        // Note: useropen has an OPEN offer - must be excluded.
        $this->insert_waitinglist_answer($optionid, (int) $useropen->id, 100);
        $repository->create_offer($optionid, (int) $useropen->id, 1, 1, new offered());

        // Note: userexcluded has no offer, but is passed explicitly in $excludeuserids - must be excluded.
        $this->insert_waitinglist_answer($optionid, (int) $userexcluded->id, 200);

        // Note: userexpired has only a TERMINAL, non-declined row - must still be included (K4, not K7).
        $baidexpired = $this->insert_waitinglist_answer($optionid, (int) $userexpired->id, 300);
        $repository->create_offer($optionid, (int) $userexpired->id, 1, 1, new expired());

        // Note: userlater was never touched at all, joined later than userexpired.
        $baidlater = $this->insert_waitinglist_answer($optionid, (int) $userlater->id, 400);

        // Two users with the IDENTICAL join time - the lower booking_answers id must win the
        // tie-break (O2), regardless of insertion/user-id order.
        $baidtiewinning = $this->insert_waitinglist_answer($optionid, (int) $usertiebreakwinning->id, 500);
        $baidtielosing = $this->insert_waitinglist_answer($optionid, (int) $usertiebreaklosing->id, 500);

        $unbehandelt = $repository->get_unbehandelte_waitinglist($optionid, [(int) $userexcluded->id]);
        $useridsinorder = array_map(fn($u) => (int) $u->userid, $unbehandelt);

        $this->assertEquals(
            [(int) $userexpired->id, (int) $userlater->id, (int) $usertiebreakwinning->id, (int) $usertiebreaklosing->id],
            $useridsinorder,
            'Expected: expired-but-eligible user first (earliest join), then userlater, then the ' .
            'tie-break pair ordered by baid ascending - and useropen/userexcluded absent entirely.'
        );

        $baidsbyuserid = [];
        foreach ($unbehandelt as $u) {
            $baidsbyuserid[(int) $u->userid] = (int) $u->baid;
        }
        $this->assertEquals($baidexpired, $baidsbyuserid[(int) $userexpired->id]);
        $this->assertEquals($baidlater, $baidsbyuserid[(int) $userlater->id]);
        $this->assertEquals($baidtiewinning, $baidsbyuserid[(int) $usertiebreakwinning->id]);
        $this->assertEquals($baidtielosing, $baidsbyuserid[(int) $usertiebreaklosing->id]);
        $this->assertLessThan($baidtielosing, $baidtiewinning, 'Precondition: winning baid must genuinely be lower.');
    }

    /**
     * A valid transition must update the status and bump the version, using the injected clock
     * for timemodified.
     */
    public function test_transition_valid_updates_status_and_version(): void {
        global $DB;

        $clock = $this->mock_clock_with_frozen(2000000000);
        $repository = new db_waitlist_offer_repository();
        $optionid = 5030;
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        $offer = $repository->create_offer($optionid, $userid, 1, 1, new pending());
        $clock->bump(500);
        $repository->transition($offer, new offered());

        $raw = $DB->get_record('booking_waitlist_offers', ['id' => $offer->id], '*', MUST_EXIST);
        $this->assertEquals(1, (int) $raw->status, 'Status must now be offered (code 1).');
        $this->assertEquals(2, (int) $raw->version, 'Version must have incremented by exactly 1.');
        $this->assertEquals(2000000500, (int) $raw->timemodified, 'timemodified must come from the injected clock.');
    }

    /**
     * A transition not allowed by offer_status::can_transition_to() must throw and must not
     * silently modify the row.
     */
    public function test_transition_invalid_throws_and_does_not_modify_the_row(): void {
        global $DB;

        $repository = new db_waitlist_offer_repository();
        $optionid = 5040;
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        $offer = $repository->create_offer($optionid, $userid, 1, 1, new declined());

        $this->expectException(\coding_exception::class);
        try {
            $repository->transition($offer, new offered());
        } finally {
            $raw = $DB->get_record('booking_waitlist_offers', ['id' => $offer->id], '*', MUST_EXIST);
            $this->assertEquals(3, (int) $raw->status, 'declined -> offered must never be persisted (K7).');
            $this->assertEquals(1, (int) $raw->version, 'A rejected transition must not bump the version.');
        }
    }

    /**
     * Transitioning to declined must create exactly one permanent K7 lock row, even across
     * repeated declines for the same option/user (different offers/rounds) - no duplicates.
     */
    public function test_transition_to_declined_creates_idempotent_permanent_lock(): void {
        global $DB;

        $repository = new db_waitlist_offer_repository();
        $optionid = 5050;
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        $this->assertFalse($repository->is_permanently_declined($optionid, $userid));

        $offer1 = $repository->create_offer($optionid, $userid, 1, 1, new offered());
        $repository->transition($offer1, new declined());
        $this->assertTrue($repository->is_permanently_declined($optionid, $userid));
        $this->assertEquals(
            1,
            $DB->count_records('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $userid])
        );

        // A second, independent offer (different round) for the same user, also declined - must
        // not create a second lock row.
        $offer2 = $repository->create_offer($optionid, $userid, 2, 1, new offered());
        $repository->transition($offer2, new declined());
        $this->assertEquals(
            1,
            $DB->count_records('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $userid]),
            'A second decline for the same user/option must not create a duplicate lock row.'
        );
    }

    /**
     * A stale optimistic-lock version (the in-memory $offer no longer matches the DB row) must
     * be rejected before any write happens.
     */
    public function test_transition_optimistic_lock_conflict_throws(): void {
        global $DB;

        $repository = new db_waitlist_offer_repository();
        $optionid = 5060;
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        $offer = $repository->create_offer($optionid, $userid, 1, 1, new pending());

        // Simulate a concurrent modification: bump the DB row's version out from under the
        // in-memory $offer object, without going through transition().
        $DB->set_field('booking_waitlist_offers', 'version', 99, ['id' => $offer->id]);

        $this->expectException(\coding_exception::class);
        try {
            $repository->transition($offer, new offered());
        } finally {
            $raw = $DB->get_record('booking_waitlist_offers', ['id' => $offer->id], '*', MUST_EXIST);
            $this->assertEquals(0, (int) $raw->status, 'A stale-version transition must not modify the status.');
            $this->assertEquals(99, (int) $raw->version, 'A stale-version transition must not touch the version either.');
        }
    }

    /**
     * is_permanently_declined() must be false for an option/user with no lock row.
     */
    public function test_is_permanently_declined_false_when_no_lock_exists(): void {
        $repository = new db_waitlist_offer_repository();
        $this->assertFalse($repository->is_permanently_declined(5070, 999999));
    }

    /**
     * create_offer() must persist the given ruleid, not the old hardcoded 0.
     */
    public function test_create_offer_persists_ruleid(): void {
        $repository = new db_waitlist_offer_repository();
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        $offer = $repository->create_offer(5080, $userid, 1, 1, new pending(), 0, 777);
        $this->assertEquals(777, $offer->ruleid);

        // Default (no ruleid passed) must still be 0, for backwards compatibility.
        $offerdefault = $repository->create_offer(5080, $userid, 2, 1, new pending());
        $this->assertEquals(0, $offerdefault->ruleid);
    }

    /**
     * get_permanently_declined_userids() must return exactly the K7-locked user ids for an
     * option, and nothing from a different option.
     */
    public function test_get_permanently_declined_userids(): void {
        $repository = new db_waitlist_offer_repository();
        $optionid = 5090;
        $otheroptionid = 5091;
        $userdeclined1 = (int) $this->getDataGenerator()->create_user()->id;
        $userdeclined2 = (int) $this->getDataGenerator()->create_user()->id;
        $useropenonly = (int) $this->getDataGenerator()->create_user()->id;

        $offer1 = $repository->create_offer($optionid, $userdeclined1, 1, 1, new offered());
        $repository->transition($offer1, new declined());
        $offer2 = $repository->create_offer($optionid, $userdeclined2, 1, 1, new offered());
        $repository->transition($offer2, new declined());
        $repository->create_offer($optionid, $useropenonly, 1, 1, new offered());

        // A decline on a DIFFERENT option must not leak into this option's list.
        $offerother = $repository->create_offer($otheroptionid, $userdeclined1, 1, 1, new offered());
        $repository->transition($offerother, new declined());

        $declinedids = $repository->get_permanently_declined_userids($optionid);

        $this->assertEqualsCanonicalizing([$userdeclined1, $userdeclined2], $declinedids);
        $this->assertNotContains($useropenonly, $declinedids);
    }

    /**
     * get_permanently_declined_userids() must return an empty array when nothing is locked.
     */
    public function test_get_permanently_declined_userids_empty_when_none_locked(): void {
        $repository = new db_waitlist_offer_repository();
        $this->assertEquals([], $repository->get_permanently_declined_userids(5092));
    }

    /**
     * is_still_on_waitinglist() must be true while a waitinglist booking_answers row exists, and
     * false once it no longer does (e.g. the user booked or cancelled).
     */
    public function test_is_still_on_waitinglist(): void {
        global $DB;

        $repository = new db_waitlist_offer_repository();
        $optionid = 5100;
        $userid = (int) $this->getDataGenerator()->create_user()->id;

        $this->assertFalse($repository->is_still_on_waitinglist($optionid, $userid));

        $baid = $this->insert_waitinglist_answer($optionid, $userid, 100);
        $this->assertTrue($repository->is_still_on_waitinglist($optionid, $userid));

        $DB->set_field('booking_answers', 'waitinglist', MOD_BOOKING_STATUSPARAM_BOOKED, ['id' => $baid]);
        $this->assertFalse(
            $repository->is_still_on_waitinglist($optionid, $userid),
            'Once the answer flips to booked, the user must no longer count as still on the waiting list.'
        );
    }
}
