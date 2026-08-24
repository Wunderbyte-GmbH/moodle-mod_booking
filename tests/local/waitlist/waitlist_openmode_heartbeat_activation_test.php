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
 * Typ 2 ("offen nach Durchlauf", waitlistrecycling=2) - new feature (2026-08-24): once a
 * fully-flagged waiting list's freed seat goes unclaimed, waitlist_heartbeat_task activates open
 * mode (db_waitlist_offer_repository::find_open_mode_activation_candidates()/
 * activate_open_mode()), and the real production booking gate (onwaitinglist::is_available())
 * must then open for anyone except K7-permanently-declined - even a K4-expired candidate, who
 * would normally stay excluded forever without a Typ-1 recycle - without ever needing the usual
 * confirmation-count grant.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\bo_availability\conditions\onwaitinglist;
use mod_booking\local\waitlist\offer_statuses\declined;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\singleton_service;
use mod_booking\task\waitlist_heartbeat_task;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * Open mode: the real heartbeat activates it once a fully-flagged list's seat goes unclaimed, and
 * the real booking gate opens for the K4-locked candidate but stays closed for the K7-locked one.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::find_open_mode_activation_candidates
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::activate_open_mode
 * @covers \mod_booking\task\waitlist_heartbeat_task::execute
 * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
 */
final class waitlist_openmode_heartbeat_activation_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * A K4-locked (expired) candidate and a K7-locked (actively declined) candidate together on a
     * fully-flagged, waitlistrecycling=2 waiting list whose one seat was never claimed. The real
     * heartbeat must activate open mode, and the real is_available() gate must then open for the
     * K4 candidate (and a completely unrelated stranger) but stay closed for the K7 candidate -
     * without either of them ever having received a confirmation grant.
     */
    public function test_heartbeat_activates_open_mode_and_gate_respects_k7(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 2, ['id' => $optionid]);
        $this->create_interval_rule(0); // ALWAYS.

        // Confirmation required + auto-grant on notification - so the new open-mode branch is
        // genuinely what opens the gate below, not the pre-existing empty(waitforconfirmation)
        // shortcut.
        $optionrecord = $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
        $json = json_decode($optionrecord->json ?: '{}');
        $json->waitforconfirmation = 1;
        $json->confirmationonnotification = 1;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $expireduser = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $expireduser->id,
            'timecreated' => 100,
            'reason' => (new expired())->get_code(),
        ]);

        $declineduser = $this->waitlist_user($course, $optionid, 'paidcat', 200);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $declineduser->id,
            'timecreated' => 200,
            'reason' => (new declined())->get_code(),
        ]);

        $stranger = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $this->getDataGenerator()->enrol_user($stranger->id, $course->id, 'student');

        $repository = new db_waitlist_offer_repository();

        // Precondition: matches what the real heartbeat's own detection query looks for.
        $this->assertContains(
            $optionid,
            $repository->find_open_mode_activation_candidates(),
            'Precondition: a fully-flagged waitlistrecycling=2 list with its seat still ' .
            'unclaimed must be detected as an open-mode activation candidate.'
        );
        $this->assertFalse(
            $repository->is_open_mode_active($optionid),
            'Precondition: open mode must not be active yet.'
        );

        $this->setAdminUser();
        (new waitlist_heartbeat_task())->execute();

        $this->assertTrue(
            $repository->is_open_mode_active($optionid),
            'The real heartbeat run must have activated open mode for this option.'
        );

        // The real production gate, not just the raw flag.
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new onwaitinglist();

        $this->assertTrue(
            $condition->is_available($settings, (int) $expireduser->id),
            'Open mode: the K4-locked (expired) candidate must now be able to book directly, ' .
            'even without ever having received a confirmation grant.'
        );
        $this->assertTrue(
            $condition->is_available($settings, (int) $stranger->id),
            'Open mode: a completely unrelated user who was never on the waiting list at all ' .
            'must also be able to book (sanity check - already true before this feature).'
        );
        $this->assertFalse(
            $condition->is_available($settings, (int) $declineduser->id),
            'Open mode must NEVER open the gate for a K7-permanently-declined candidate.'
        );
    }
}
