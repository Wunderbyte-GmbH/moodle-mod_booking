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
 * Tests for campaign_customfield::get_campaign_limit().
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking_generator;
use context_system;
use mod_booking\booking_option;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for campaign_customfield::get_campaign_limit() covering limitfactor,
 * extendlimitforoverbooked, and the timebooked regression fix.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class campaign_customfield_limit_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Creates the BookCustomCat1 custom field category and the spt1 text field.
     *
     * @return void
     */
    private function create_spt1_customfield(): void {
        $categorydata = new stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Sport1';
        $fielddata->shortname = 'spt1';
        $fielddata->type = 'text';
        $fielddata->configdata = '{"required":"0","uniquevalues":"0","locked":"0","visibility":"2",' .
            '"defaultvalue":"","displaysize":30,"maxlength":50,"ispassword":"0","link":"","linktarget":""}';
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();
    }

    /**
     * Creates a campaign matching booking options with spt1=tennis.
     *
     * @param mod_booking_generator $plugingenerator
     * @param string $name Campaign name.
     * @param int $starttime Campaign start timestamp.
     * @param int $endtime Campaign end timestamp.
     * @param float $limitfactor Limit multiplication factor.
     * @param int $extendlimitforoverbooked Whether to extend limit for overbooked options.
     * @param float $pricefactor Price multiplication factor.
     * @return void
     */
    private function create_tennis_campaign(
        mod_booking_generator $plugingenerator,
        string $name,
        int $starttime,
        int $endtime,
        float $limitfactor,
        int $extendlimitforoverbooked,
        float $pricefactor = 1.0
    ): void {
        $campaigndata = (object) [
            'bofieldname' => 'spt1',
            'fieldvalue' => 'tennis',
            'campaignfieldnameoperator' => '=',
            'cpfield' => null,
            'cpoperator' => null,
            'cpvalue' => null,
        ];
        $plugingenerator->create_campaign([
            'name' => $name,
            'type' => 0,
            'starttime' => $starttime,
            'endtime' => $endtime,
            'pricefactor' => $pricefactor,
            'limitfactor' => $limitfactor,
            'extendlimitforoverbooked' => $extendlimitforoverbooked,
            'json' => json_encode($campaigndata),
        ]);
    }

    /**
     * Inserts a booking answer record directly into the DB with explicit timecreated/timebooked values.
     *
     * Direct DB inserts are necessary for the regression test because write_user_answer_to_db()
     * always sets timebooked = $timecreated (copying the original timecreated for transitions),
     * making it impossible to create records with timecreated != timebooked via the normal API.
     *
     * @param int $bookingid
     * @param int $optionid
     * @param int $userid
     * @param int $timecreated
     * @param int|null $timebooked
     * @return void
     */
    private function insert_booking_answer(
        int $bookingid,
        int $optionid,
        int $userid,
        int $timecreated,
        ?int $timebooked
    ): void {
        global $DB;

        $answer = new stdClass();
        $answer->bookingid = $bookingid;
        $answer->userid = $userid;
        $answer->optionid = $optionid;
        $answer->timecreated = $timecreated;
        $answer->timemodified = $timecreated;
        $answer->timebooked = $timebooked;
        $answer->waitinglist = MOD_BOOKING_STATUSPARAM_BOOKED;
        $answer->frombookingid = 0;
        $answer->completed = 0;
        $answer->status = 0;
        $answer->places = 1;
        $answer->syncruleid = 0;
        $answer->numrec = 0;
        $DB->insert_record('booking_answers', $answer);
    }

    /**
     * Purges all relevant caches and returns fresh booking option settings.
     *
     * @param int $optionid
     * @return \mod_booking\booking_option_settings
     */
    private function reload_settings(int $optionid): \mod_booking\booking_option_settings {
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
        singleton_service::reset_campaigns();
        return singleton_service::get_instance_of_booking_option_settings($optionid);
    }

    /**
     * Test that a campaign with limitfactor > 1 correctly scales maxanswers.
     *
     * Scenario:
     * - Booking option with maxanswers=4, spt1=tennis.
     * - 3 users booked before the campaign starts (timecreated = timebooked = T_before).
     * - Campaign: starttime = T_campaign, limitfactor=1.5, extendlimitforoverbooked=0.
     * - Expected: maxanswers = ceil(4 * 1.5) = 6.
     *
     * @covers \mod_booking\booking_campaigns\campaigns\campaign_customfield::get_campaign_limit
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_campaigns_settings_provider
     */
    public function test_campaign_customfield_limit_factor_basic(array $bdata): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        $this->create_spt1_customfield();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Campaign limit factor basic';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->maxoverbooking = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $record->teachersforoption = $teacher->username;
        $record->customfield_spt1 = 'tennis';
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Insert 3 booked users before the campaign start.
        $tbefore = strtotime('-2 hours');
        for ($i = 1; $i <= 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->insert_booking_answer($option1->bookingid, $option1->id, $user->id, $tbefore, $tbefore);
        }

        // Campaign: starttime = 1 hour ago, limitfactor = 1.5.
        $this->create_tennis_campaign(
            $plugingenerator,
            'basic_limit_factor',
            strtotime('-1 hour'),
            strtotime('+1 month'),
            1.5,
            0
        );

        $settings = $this->reload_settings($option1->id);

        // ceil(4 * 1.5) = 6.
        $this->assertEquals(6, $settings->maxanswers);

        // DB value must remain unchanged.
        $dbmaxanswers = (int) $DB->get_field('booking_options', 'maxanswers', ['id' => $option1->id]);
        $this->assertEquals(4, $dbmaxanswers);
    }

    /**
     * Test that extendlimitforoverbooked correctly compensates for pre-campaign overbooking.
     *
     * Scenario:
     * - Booking option with maxanswers=4, spt1=tennis.
     * - 5 users booked before the campaign (timebooked = T_before < campaign_starttime).
     * - Campaign: limitfactor=1.1, extendlimitforoverbooked=1.
     * - Formula: nrofbookedusers=5 > limit=4
     *   → campaignlimit = ceil(4 * 1.1 − 4 + 5) = ceil(5.4) = 6.
     * - Expected: maxanswers = 6.
     *
     * @covers \mod_booking\booking_campaigns\campaigns\campaign_customfield::get_campaign_limit
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_campaigns_settings_provider
     */
    public function test_campaign_customfield_extend_limit_for_overbooked(array $bdata): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        $this->create_spt1_customfield();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Campaign extend limit overbooked';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->maxoverbooking = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $record->teachersforoption = $teacher->username;
        $record->customfield_spt1 = 'tennis';
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $tbefore = strtotime('-3 hours');
        $tcampaignstart = strtotime('-1 hour');

        // Insert 5 booked users, all before the campaign start.
        for ($i = 1; $i <= 5; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->insert_booking_answer($option1->bookingid, $option1->id, $user->id, $tbefore, $tbefore);
        }

        // Campaign: limitfactor=1.1, extendlimitforoverbooked=1.
        $this->create_tennis_campaign(
            $plugingenerator,
            'extend_limit_overbooked',
            $tcampaignstart,
            strtotime('+1 month'),
            1.1,
            1
        );

        $settings = $this->reload_settings($option1->id);

        // nrofbookedusers=5 > limit=4 → ceil(4*1.1 − 4 + 5) = ceil(5.4) = 6.
        $this->assertEquals(6, $settings->maxanswers);

        // DB value must remain unchanged.
        $dbmaxanswers = (int) $DB->get_field('booking_options', 'maxanswers', ['id' => $option1->id]);
        $this->assertEquals(4, $dbmaxanswers);
    }

    /**
     * Regression test: get_campaign_limit() must use timebooked, not timecreated.
     *
     * Background: booking answers for users who were on the notify/waiting list before
     * the campaign started have timecreated = T_before. When the bug was present, these
     * users were counted as "pre-campaign bookings", incorrectly inflating the limit via
     * extendlimitforoverbooked. The fix uses timebooked instead.
     *
     * This test creates two groups of 4 users each:
     *   - Group A: timecreated = timebooked = T_before  (booked before campaign)
     *   - Group B: timecreated = T_before, timebooked = T_after  (simulate users whose
     *              notify/waiting-list record was created before the campaign, but whose
     *              actual BOOKED transition happened after the campaign started)
     *
     * With the fix (timebooked):
     *   - Only Group A counts as pre-campaign → nrofbookedusers = 4
     *   - 4 is NOT > limit=4 → no overbooking extension
     *   - campaignlimit = ceil(4 * 1.1) = 5  ← expected
     *
     * With the old bug (timecreated):
     *   - Both groups count → nrofbookedusers = 8
     *   - 8 > 4 → campaignlimit = ceil(4*1.1 − 4 + 8) = ceil(8.4) = 9  ← wrong
     *
     * Note: Direct DB inserts are required because write_user_answer_to_db() always sets
     * timebooked = timecreated for transitions, making it impossible to produce
     * timecreated != timebooked via the normal booking API.
     *
     * @covers \mod_booking\booking_campaigns\campaigns\campaign_customfield::get_campaign_limit
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_campaigns_settings_provider
     */
    public function test_campaign_customfield_timebooked_regression(array $bdata): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        $this->create_spt1_customfield();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Campaign timebooked regression';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->maxoverbooking = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $record->teachersforoption = $teacher->username;
        $record->customfield_spt1 = 'tennis';
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $tbefore = strtotime('-3 hours');
        $tcampaignstart = strtotime('-1 hour');
        $tafter = strtotime('now');

        // Group A: 4 users genuinely booked before the campaign.
        // timecreated = timebooked = T_before → counted by both old and new code.
        for ($i = 1; $i <= 4; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->insert_booking_answer($option1->bookingid, $option1->id, $user->id, $tbefore, $tbefore);
        }

        // Campaign starts 1 hour ago with extendlimitforoverbooked enabled.
        $this->create_tennis_campaign(
            $plugingenerator,
            'timebooked_regression',
            $tcampaignstart,
            strtotime('+1 month'),
            1.1,
            1
        );

        // Group B: 4 users with timecreated = T_before but timebooked = T_after.
        // These simulate users whose notify/waiting-list record existed before the campaign,
        // but who were actually booked (timebooked) after the campaign started.
        // With the fix (timebooked):  T_after >= tcampaignstart → NOT counted.
        // With the old bug (timecreated): T_before < tcampaignstart → incorrectly counted.
        for ($i = 1; $i <= 4; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->insert_booking_answer($option1->bookingid, $option1->id, $user->id, $tbefore, $tafter);
        }

        $settings = $this->reload_settings($option1->id);

        // With fix (timebooked): nrofbookedusers=4, 4 NOT > 4 → ceil(4 * 1.1) = 5.
        $this->assertEquals(5, $settings->maxanswers);

        // DB value must remain unchanged.
        $dbmaxanswers = (int) $DB->get_field('booking_options', 'maxanswers', ['id' => $option1->id]);
        $this->assertEquals(4, $dbmaxanswers);
    }

    /**
     * Data provider for campaign_customfield limit tests.
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_campaigns_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking Campaign Customfield Limit',
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
