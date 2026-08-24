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
 * D2 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie D - Confirmation-Feinheiten,
 * Negativ-Test): progression::autobook() (K3) must NEVER call
 * grant_confirmation_if_required() - that method only makes sense for a candidate who stays ON
 * the waiting list awaiting confirmation (K4/offer()).
 *
 * First attempt at this test asserted the autobooked answer's json has NO confirmwaitinglist
 * flag at all - that assumption turned out to be WRONG: booking_option::write_user_answer_to_db()
 * has its own, pre-existing, unrelated branch that sets confirmwaitinglist/confirmationcount
 * whenever an answer moves from waiting-list to booked on an option with waitforconfirmation>0 -
 * completely independent of confirmationonnotification and of our grant_confirmation_if_required()
 * (see its comment: "Ensures we save keys on next confirmations"). So the flag being present after
 * an autobook is expected, pre-existing behaviour, not evidence that OUR grant fired.
 *
 * The actual, precise way to test the real invariant ("our grant never fires on K3") is
 * DIFFERENTIAL: run the exact same autobook scenario on two otherwise-identical options, one
 * with confirmationonnotification=1 (would make grant_confirmation_if_required() write something
 * if it were ever - wrongly - called) and one with confirmationonnotification=0 (would not). If
 * autobook() never calls that method, confirmationonnotification's value must have ZERO effect on
 * the resulting json - both options must produce byte-identical confirmwaitinglist/
 * confirmationcount state, since only the unrelated legacy branch (which does not read
 * confirmationonnotification at all) is what actually sets them.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * D2: confirmationonnotification must have zero effect on an autobooked candidate's json - proof
 * that grant_confirmation_if_required() is never reached from the K3 path.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class d2_no_confirmation_grant_on_autobook_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Runs the same free-price autobook scenario on two option instances that differ ONLY in
     * confirmationonnotification (1 vs 0). The resulting confirmwaitinglist/confirmationcount
     * state must be identical on both - only the unrelated write_user_answer_to_db() branch is
     * responsible for it, and that branch does not consult confirmationonnotification at all.
     */
    public function test_confirmationonnotification_has_no_effect_on_autobook_outcome(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);

        $answerjsonwith = $this->autobook_and_return_json($course, $teacher, $booking, 1);
        $answerjsonwithout = $this->autobook_and_return_json($course, $teacher, $booking, 0);

        // Narrowed to the exact keys our own grant_confirmation_if_required() would touch -
        // avoids comparing confirmwaitinglist_timemodified, which uses real wall-clock time()
        // (not our \core\clock DI) and could spuriously differ by a second between the two calls.
        $relevant = fn (\stdClass $json) => [
            'confirmwaitinglist' => $json->confirmwaitinglist ?? null,
            'confirmationcount' => $json->confirmationcount ?? null,
        ];
        $this->assertEquals(
            $relevant($answerjsonwith),
            $relevant($answerjsonwithout),
            'D2: confirmationonnotification (1 vs 0) must have zero effect on an autobooked ' .
            'candidate\'s resulting confirmwaitinglist/confirmationcount state - proves ' .
            'grant_confirmation_if_required() is never reached from the K3/autobook path.'
        );
    }

    /**
     * Builds a fresh option with the given confirmationonnotification value, autobooks one
     * free-price candidate on it, and returns their resulting answer json.
     *
     * @param \stdClass $course
     * @param \stdClass $teacher
     * @param \stdClass $booking
     * @param int $confirmationonnotification
     * @return \stdClass
     */
    private function autobook_and_return_json(
        \stdClass $course,
        \stdClass $teacher,
        \stdClass $booking,
        int $confirmationonnotification
    ): \stdClass {
        global $DB;

        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);

        $optionrecord = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $json = json_decode($optionrecord->json ?: '{}');
        $json->waitforconfirmation = 1;
        $json->confirmationonnotification = $confirmationonnotification;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $this->create_interval_rule(0); // ALWAYS.

        $candidate = $this->waitlist_user($course, $optionid, 'freecat', 100);

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'test');

        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $answer->waitinglist,
            'D2: the candidate must genuinely be autobooked (K3) in both variants.'
        );
        $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertEquals(6, (int) $offer->status, 'K3: autobooked.');

        return json_decode($answer->json ?: '{}');
    }
}
