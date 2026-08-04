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
 * Baseline measurement for the wunderbyte table cache key explosion caused by the
 * SQL availability filter (usesqlfilteravailability).
 *
 * With the setting active, the availability conditions embed user specific data
 * into the options WHERE clause through two independent "noise" channels:
 * - enrolledincourse/-cohorts inline ALL course/cohort ids of the user as
 *   literals - including memberships no filter condition even references.
 * - the profile field condition adds ALL custom profile field values of the
 *   user as params (operator_builder::build_shortname_case iterates
 *   $user->profile) - including fields no condition references.
 * The wunderbyte table cache key is a crc32 over exactly this SQL string plus
 * its params, so every distinct membership/profile fingerprint produces its own
 * set of application cache entries - even when the visible RESULT is identical.
 * This is the root cause of the cache filling up on large sites.
 *
 * Scenario: 102 users in 10 visibility groups (identical filter-relevant
 * memberships within each group, but each user with one unique noise course
 * enrolment and one unique value in a never-referenced noise profile field),
 * including a "twin" pair that is identical in EVERYTHING (shared noise course)
 * except the noise profile value.
 *
 * MEASURED BASELINE (before the trim fix, 2026-08-04): the 102 users produced
 * 102 DISTINCT cache keys although only 10 DISTINCT visible result sets
 * existed; the twins got different keys for identical results, and the noise
 * profile value literally appeared in the key base (get_sql_for_cachekey).
 *
 * PINNED STATE (after the trim fix - user sets are cut down to the site-wide
 * referenced course/cohort ids and profile field shortnames): the same scenario
 * produces exactly ONE key per visibility class (10), all members of a group -
 * including the twins - share a single key, and the noise value is gone from
 * the key base. The assertions marked FLIP carry both readings.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking_generator;
use mod_booking\output\view;
use mod_booking\table\bookingoptions_wbtable;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Baseline measurement for the SQL filter cache key explosion.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sqlfilter_cachekey_baseline_test extends booking_advanced_testcase {
    /** @var int number of visibility groups */
    private const GROUPS = 10;

    /** @var int users per visibility group */
    private const USERSPERGROUP = 10;

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
     * Build a fresh instance-wide options table with the SQL the general view
     * produces (userid null - exactly like view.php) for the CURRENT $USER.
     *
     * @param stdClass $booking1
     * @param bool $setupnow set up the flexible table right away (needed when only
     *     the cache key is computed; printtable() does its own setup)
     * @return bookingoptions_wbtable
     */
    private function build_view_table(stdClass $booking1, bool $setupnow = false): bookingoptions_wbtable {
        $bookingsettings = singleton_service::get_instance_of_booking_by_cmid((int) $booking1->cmid);
        $view = new view((int) $booking1->cmid, 'showall');
        $table = new bookingoptions_wbtable('sqlfilterbaselinetable');
        $view->wbtable_initialize_layout($table, false, false, false);
        if ($setupnow) {
            $table->define_baseurl(new \moodle_url('/mod/booking/view.php', ['id' => (int) $booking1->cmid]));
            $table->setup();
        }
        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $bookingsettings->context,
            [],
            ['bookingid' => (int) $bookingsettings->id],
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            $table
        );
        $table->set_filter_sql($fields, $from, $where, $filter, $params);
        return $table;
    }

    /**
     * Set a custom profile field value for a user.
     *
     * @param stdClass $user
     * @param stdClass $profilefield
     * @param string $value
     * @return void
     */
    private function set_profile_value(stdClass $user, stdClass $profilefield, string $value): void {
        global $DB;
        $DB->insert_record('user_info_data', [
            'userid' => $user->id,
            'fieldid' => $profilefield->id,
            'data' => $value,
        ]);
    }

    /**
     * 102 users in 10 visibility groups: the current SQL filter produces one
     * cache key PER USER although only 10 distinct visible results exist.
     *
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     * @covers \mod_booking\bo_availability\conditions\enrolledincourse::return_sql
     * @covers \mod_booking\bo_availability\conditions\enrolledincohorts::return_sql
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_cachekeys_per_user_although_few_visibility_classes(array $bdata): void {
        global $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $targetcourse = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // One cohort per visibility group.
        $cohorts = [];
        for ($g = 0; $g < self::GROUPS; $g++) {
            $cohorts[$g] = $this->getDataGenerator()->create_cohort([
                'name' => "Group cohort $g",
                'idnumber' => "GC$g",
            ]);
        }

        // Two custom profile fields: department is referenced by a filter
        // condition (value follows the group), noisetoken is referenced by
        // NOTHING and unique per user.
        $departmentfield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'department',
            'name' => 'Department',
        ]);
        $noisefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'noisetoken',
            'name' => 'Noise token',
        ]);

        // 100 users: group membership and department decide visibility; the
        // unique noise course enrolment and the unique noisetoken value are
        // irrelevant for every filter but poison SQL string and params.
        $users = [];
        for ($g = 0; $g < self::GROUPS; $g++) {
            for ($i = 0; $i < self::USERSPERGROUP; $i++) {
                $user = $this->getDataGenerator()->create_user();
                cohort_add_member($cohorts[$g]->id, $user->id);
                if ($g < 5) {
                    $this->getDataGenerator()->enrol_user($user->id, $targetcourse->id);
                }
                $noisecourse = $this->getDataGenerator()->create_course();
                $this->getDataGenerator()->enrol_user($user->id, $noisecourse->id);
                $this->getDataGenerator()->enrol_user($user->id, $course1->id);
                $this->set_profile_value($user, $departmentfield, ($g % 2 === 0) ? 'sales' : 'support');
                $this->set_profile_value($user, $noisefield, "unique-noise-{$g}-{$i}");
                $users[$g][$i] = $user;
            }
        }

        // The twin pair: identical in EVERYTHING (group 0 memberships, the SAME
        // shared noise course, same department) except the noisetoken value.
        $twinnoisecourse = $this->getDataGenerator()->create_course();
        $twins = [];
        foreach (['twin-a', 'twin-b'] as $t => $token) {
            $twin = $this->getDataGenerator()->create_user();
            cohort_add_member($cohorts[0]->id, $twin->id);
            $this->getDataGenerator()->enrol_user($twin->id, $targetcourse->id);
            $this->getDataGenerator()->enrol_user($twin->id, $twinnoisecourse->id);
            $this->getDataGenerator()->enrol_user($twin->id, $course1->id);
            $this->set_profile_value($twin, $departmentfield, 'sales');
            $this->set_profile_value($twin, $noisefield, "unique-noise-$token");
            $twins[$t] = $twin;
        }

        // 20 options: 10 cohort-filtered (one per group), 1 course-filtered
        // (groups 0-4 pass), 9 unfiltered.
        $baserecord = function (string $text) use ($booking1, $course1): stdClass {
            $record = new stdClass();
            $record->bookingid = $booking1->id;
            $record->text = $text;
            $record->chooseorcreatecourse = 1;
            $record->courseid = $course1->id;
            return $record;
        };
        for ($g = 0; $g < self::GROUPS; $g++) {
            $record = $baserecord("Requires group cohort $g");
            $record->bo_cond_enrolledincohorts_restrict = 1;
            $record->bo_cond_enrolledincohorts_cohortids = [$cohorts[$g]->id];
            $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
            $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
            $plugingenerator->create_option($record);
        }
        $record = $baserecord('Requires the target course');
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$targetcourse->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;
        $plugingenerator->create_option($record);
        $record = $baserecord('Requires department sales');
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'department';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'sales';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;
        $plugingenerator->create_option($record);
        for ($u = 0; $u < 9; $u++) {
            $plugingenerator->create_option($baserecord("Unfiltered option $u"));
        }

        singleton_service::destroy_instance();

        // Measurement 1: the cache key of the general view SQL for all 100 users.
        $keys = [];
        $keysbygroup = [];
        foreach ($users as $g => $groupusers) {
            foreach ($groupusers as $user) {
                $this->setUser($user);
                $table = $this->build_view_table($booking1, true);
                $key = $table->create_cachekey();
                $keys[] = $key;
                $keysbygroup[$g][] = $key;
            }
        }
        // Twin measurement: identical memberships, identical shared noise course,
        // identical department - ONLY the noisetoken value differs.
        $twinkeys = [];
        $twinsql = [];
        foreach ($twins as $t => $twin) {
            $this->setUser($twin);
            $table = $this->build_view_table($booking1, true);
            $twinkeys[$t] = $table->create_cachekey();
            $twinsql[$t] = $table->get_sql_for_cachekey();
            $keys[] = $twinkeys[$t];
        }
        $distinctkeys = array_unique($keys);

        // FLIP marker - before the trim fix the never-referenced noise value
        // literally appeared in the key base (assertStringContainsString held);
        // after the fix it must be gone for both twins.
        $this->assertStringNotContainsString(
            'unique-noise-twin-a',
            $twinsql[0],
            'the never-referenced noisetoken value must not appear in the cache key base anymore'
        );
        $this->assertStringNotContainsString(
            'unique-noise-twin-b',
            $twinsql[1],
            'the never-referenced noisetoken value must not appear in the cache key base anymore'
        );

        // FLIP marker - before the trim fix the twins got DIFFERENT keys
        // (assertNotSame held) although they differ only in the never-referenced
        // noise value. After the fix they must share one key.
        $this->assertSame(
            $twinkeys[0],
            $twinkeys[1],
            'users differing only in never-referenced data must share one cache key'
        );

        // Measurement 2: the actually visible result set for two users per group,
        // through the real (cached) table pipeline. The booking view tables cache
        // into mod_booking/bookingoptionstable (MODE_APPLICATION).
        $resultsets = [];
        foreach ($users as $g => $groupusers) {
            $groupsets = [];
            foreach ([0, 1] as $i) {
                $this->setUser($groupusers[$i]);
                $table = $this->build_view_table($booking1);
                $table->printtable(30, true);
                $rows = $table->rawdata ?? [];
                $ids = array_map(static fn($r): int => (int) $r->id, $rows);
                sort($ids);
                $groupsets[$i] = implode(',', $ids);

                // Each run leaves its own entry in the application cache the
                // table is configured for.
                $cache = cache::make($table->cachecomponent, $table->rawcachename);
                $this->assertNotFalse(
                    $cache->get($table->create_cachekey()),
                    'each rendered table run must leave a rawdata cache entry under its own key'
                );
            }
            $this->assertSame(
                $groupsets[0],
                $groupsets[1],
                "users of group $g have identical filter-relevant memberships and must see the same options"
            );
            $resultsets[] = $groupsets[0];
        }
        $distinctresults = array_unique($resultsets);

        // The twins see exactly the same options - different keys, same result.
        $twinsets = [];
        foreach ($twins as $t => $twin) {
            $this->setUser($twin);
            $table = $this->build_view_table($booking1);
            $table->printtable(30, true);
            $ids = array_map(static fn($r): int => (int) $r->id, $table->rawdata ?? []);
            sort($ids);
            $twinsets[$t] = implode(',', $ids);
        }
        $this->assertSame(
            $twinsets[0],
            $twinsets[1],
            'the twins must see identical options - their keys differ for no observable reason'
        );

        // The scenario really produces 10 different visible result sets ...
        $this->assertCount(
            self::GROUPS,
            $distinctresults,
            'the scenario must produce exactly one distinct visible result set per visibility group'
        );

        // ... and each of them contains the 9 unfiltered options plus the group
        // specific ones (10 or 11 visible options).
        foreach ($distinctresults as $set) {
            $this->assertGreaterThanOrEqual(10, count(explode(',', $set)));
        }

        // FLIP marker - before the trim fix every single user (incl. the twins)
        // got an own cache key (102 distinct keys, assertCount(102) held),
        // because irrelevant noise enrolments and never-referenced profile
        // values were embedded into SQL and params. After the fix the keys
        // collapse onto the visibility classes: exactly one key per group.
        $this->assertCount(
            self::GROUPS,
            $distinctkeys,
            'the cache keys must collapse onto the visibility classes (one key per group)'
        );

        // FLIP marker - before the trim fix even the identically privileged
        // members of one visibility group got per-user keys
        // (assertCount(USERSPERGROUP) held). After the fix they share one key.
        foreach ($keysbygroup as $g => $groupkeys) {
            $this->assertCount(
                1,
                array_unique($groupkeys),
                "all identically privileged users of group $g must share a single cache key"
            );
        }
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Baseline cache key measurement',
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
