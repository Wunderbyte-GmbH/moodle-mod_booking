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
 * Tests for the booking cache report service.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\bo_info;
use mod_booking\local\cachereport\cachereport_service;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the booking cache report service.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cachereport_service_test extends booking_advanced_testcase {
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
     * Seed a booking instance with one cohort filtered option and return it.
     *
     * @param array $bdata booking instance data
     * @param stdClass $cohort the cohort the option requires
     * @return stdClass the booking instance
     */
    private function seed_filtered_instance(array $bdata, stdClass $cohort): stdClass {
        set_config('usesqlfilteravailability', 1, 'booking');
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Requires the cohort';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;
        $plugingenerator->create_option($record);

        return $booking1;
    }

    /**
     * The report side stem (conditions_sql_parts without bypass) must be exactly
     * the WHERE + params the general view produces - THE anchor that the report
     * measures reality.
     *
     * @covers \mod_booking\local\cachereport\cachereport_service::stem_for_user
     * @covers \mod_booking\bo_availability\bo_info::conditions_sql_parts
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_stem_matches_general_view_sql(array $bdata): void {
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Report cohort', 'idnumber' => 'RC1']);
        $this->seed_filtered_instance($bdata, $cohort);

        $student = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $student->id);

        // The general view: userid null semantics, conditions read the session user.
        $this->setUser($student);
        [, , , $viewparams, $viewwhere] = bo_info::return_sql_from_conditions(0);
        $this->assertNotSame('', $viewwhere, 'precondition: the filter must produce a WHERE');

        $service = new cachereport_service();
        $stem = $service->stem_for_user((int) $student->id);

        $this->assertSame(
            $viewwhere . json_encode($viewparams),
            $stem,
            'the report stem must equal the general view WHERE plus encoded params'
        );
    }

    /**
     * The stem sample groups identically privileged users onto one key class
     * although each of them carries unique noise memberships.
     *
     * @covers \mod_booking\local\cachereport\cachereport_service::stem_sample
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_stem_sample_counts_visibility_classes(array $bdata): void {
        global $DB;

        $cohorta = $this->getDataGenerator()->create_cohort(['name' => 'Cohort A', 'idnumber' => 'CA']);
        $cohortb = $this->getDataGenerator()->create_cohort(['name' => 'Cohort B', 'idnumber' => 'CB']);
        $this->seed_filtered_instance($bdata, $cohorta);

        // Six recently active users: three in cohort A, three in cohort B, each
        // with a unique noise course enrolment.
        $now = time();
        foreach ([$cohorta, $cohorta, $cohorta, $cohortb, $cohortb, $cohortb] as $i => $cohort) {
            $user = $this->getDataGenerator()->create_user();
            cohort_add_member($cohort->id, $user->id);
            $noisecourse = $this->getDataGenerator()->create_course();
            $this->getDataGenerator()->enrol_user($user->id, $noisecourse->id);
            $DB->set_field('user', 'lastaccess', $now - $i, ['id' => $user->id]);
        }

        $service = new cachereport_service();
        $sample = $service->stem_sample(6);

        $this->assertTrue($sample['active']);
        $this->assertSame(6, $sample['samplesize'], 'exactly the six active users must be sampled');
        $this->assertSame(
            2,
            $sample['distinctstems'],
            'the six users fall into two visibility classes although each has unique noise memberships'
        );
        $this->assertSame([3, 3], $sample['topclasses']);
    }

    /**
     * Snapshots are persisted with the metric fields and the retention keeps the
     * table bounded.
     *
     * @covers \mod_booking\local\cachereport\cachereport_service::create_snapshot
     * @covers \mod_booking\task\create_cachereport_snapshot
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_snapshot_and_retention(array $bdata): void {
        global $DB;

        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Report cohort', 'idnumber' => 'RC1']);
        $this->seed_filtered_instance($bdata, $cohort);

        // Pre-fill more rows than the retention keeps.
        $now = time();
        for ($i = 0; $i < cachereport_service::SNAPSHOTRETENTION + 5; $i++) {
            $DB->insert_record('booking_cachereport_snapshots', (object) [
                'timecreated' => $now - 100000 + $i,
                'samplesize' => 1,
                'distinctstems' => 1,
                'sqlfilteroptions' => 0,
            ]);
        }

        $service = new cachereport_service();
        $id = $service->create_snapshot(5);

        $snapshot = $DB->get_record('booking_cachereport_snapshots', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $snapshot->sqlfilteroptions, 'the seeded filtered option must be counted');
        $this->assertNotEmpty($snapshot->extrasjson);
        $extras = json_decode($snapshot->extrasjson);
        $this->assertTrue($extras->active);

        $this->assertLessThanOrEqual(
            cachereport_service::SNAPSHOTRETENTION,
            $DB->count_records('booking_cachereport_snapshots'),
            'the retention must keep the snapshot table bounded'
        );
        $this->assertTrue(
            $DB->record_exists('booking_cachereport_snapshots', ['id' => $id]),
            'the newest snapshot must survive the retention'
        );

        // The scheduled task wraps the same service call.
        $task = new \mod_booking\task\create_cachereport_snapshot();
        $this->assertNotEmpty($task->get_name());
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertLessThanOrEqual(
            cachereport_service::SNAPSHOTRETENTION,
            $DB->count_records('booking_cachereport_snapshots')
        );
    }

    /**
     * The query timing section reports plausible values without writing caches.
     *
     * @covers \mod_booking\local\cachereport\cachereport_service::query_timing
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_query_timing(array $bdata): void {
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Report cohort', 'idnumber' => 'RC1']);
        $this->seed_filtered_instance($bdata, $cohort);

        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $service = new cachereport_service();
        $timing = $service->query_timing();

        $this->assertTrue($timing['available']);
        $this->assertSame(1, $timing['optioncount']);
        $this->assertIsInt($timing['plainms']);
        $this->assertIsInt($timing['filteredms']);
        $this->assertSame(0, $timing['filteredcount'], 'the student is not in the cohort, the option is hidden');
        $this->assertSame(1, $timing['plaincount']);
    }

    /**
     * The traffic light evaluation maps the metrics onto the documented
     * thresholds and carries action recommendations for every non-ok finding.
     *
     * @covers \mod_booking\local\cachereport\cachereport_service::evaluate
     */
    public function test_evaluate_traffic_light(): void {
        $service = new cachereport_service();

        // A healthy site: keys shared, small entries, fast query, rollover fresh.
        $findings = $service->evaluate(
            [
                'markercounts' => [MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME => 3],
                'daybucket' => strtotime('today 00:00'),
                'pendingpurgetasks' => 0,
            ],
            ['active' => true, 'samplesize' => 100, 'distinctstems' => 8],
            ['available' => true, 'filteredms' => 50],
            [
                ['supported' => true, 'meansize' => 4096, 'totalsize' => 4096 * 100],
            ]
        );
        $bycode = array_column($findings, 'status', 'code');
        $this->assertSame(
            ['keysharing' => 'ok', 'entrysize' => 'ok', 'totalsize' => 'ok', 'querytime' => 'ok', 'rollover' => 'ok'],
            $bycode
        );
        foreach ($findings as $finding) {
            $this->assertNull($finding['action'], 'ok findings must not carry an action');
        }

        // A problematic site: per-user keys, huge entries, large total, slow
        // query and a lagging rollover.
        $findings = $service->evaluate(
            [
                'markercounts' => [MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME => 3],
                'daybucket' => strtotime('now - 5 days'),
                'pendingpurgetasks' => 10,
            ],
            ['active' => true, 'samplesize' => 100, 'distinctstems' => 95],
            ['available' => true, 'filteredms' => 1500],
            [
                [
                    'supported' => true,
                    'meansize' => cachereport_service::ENTRYSIZECRITICALBYTES + 1,
                    'totalsize' => cachereport_service::TOTALSIZEWARNBYTES + 1,
                ],
            ]
        );
        $bycode = array_column($findings, 'status', 'code');
        $this->assertSame('critical', $bycode['keysharing']);
        $this->assertSame('critical', $bycode['entrysize']);
        $this->assertSame('warning', $bycode['totalsize']);
        $this->assertSame('critical', $bycode['querytime']);
        $this->assertSame('warning', $bycode['rollover']);
        foreach ($findings as $finding) {
            $this->assertNotNull($finding['action'], 'every non-ok finding must carry an action recommendation');
            $this->assertNotSame('', $finding['message']);
        }

        // Small samples must not produce a key sharing verdict, and without the
        // booking time marker there is no rollover finding.
        $findings = $service->evaluate(
            ['markercounts' => [1 => 5], 'daybucket' => 0, 'pendingpurgetasks' => 0],
            ['active' => true, 'samplesize' => 5, 'distinctstems' => 5],
            ['available' => false, 'filteredms' => null],
            []
        );
        $codes = array_column($findings, 'code');
        $this->assertNotContains('keysharing', $codes);
        $this->assertNotContains('rollover', $codes);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Cache report test',
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
