<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_booking;

/**
 * Concurrency benchmark / overbooking probe for booking_option capacity under load.
 *
 * WHAT IT DOES
 *   NPROCS separate CLI processes each book ONE distinct user onto a single
 *   MAXANSWERS-seat option. They synchronise on a barrier (everyone bootstrapped) and
 *   then all fire at the same instant (window = 0 = maximum contention). We record:
 *     - booked: how many ended up booked. Without a capacity lock this exceeds
 *       MAXANSWERS (the real overbooking race); with the lock it is exactly MAXANSWERS.
 *     - span_ms: wall-clock of the booking phase = the serialized cost the lock adds.
 *   The same configuration is run 8 times; the MEDIAN of the span is the metric and the
 *   spread is the noise floor. DISCARD THE FIRST RUN (cold cache/DB warmup outlier).
 *   Only a change that moves the median by more than the noise floor is a real gain.
 *
 * HOW THE PROCESSES SHARE STATE
 *   The spawned processes are normal CLI processes that adopt the PHPUnit environment
 *   via PHPUNIT_UTIL (see tests/fixtures/race_book_one_user.php). They share the test
 *   DB (committed via preventResetByRollback) and the file MUC cache. This only works
 *   where application caches use a cross-process-coherent store (e.g. cachestore_file);
 *   note bookingoptionsanswers is intentionally staticacceleration=false for exactly
 *   this reason.
 *
 * HOW TO RUN
 *   It is SKIPPED unless MOD_BOOKING_RACE_BENCH=1 is set, because it spawns many parallel
 *   processes, is timing dependent and relies on relative vendor/bootstrap paths — i.e.
 *   it must never run in normal CI. Example:
 *     MOD_BOOKING_RACE_BENCH=1 vendor/bin/phpunit \
 *       --filter test_bench mod/booking/tests/waitinglist/race_baseline_test.php
 *   Per-run summaries are written to /tmp/racebench/run{N}.json.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 * @runInSeparateProcess
 */
final class race_baseline_test extends \advanced_testcase {
    /** @var int parallel booking processes. */
    private const NPROCS = 50;

    /** @var int seats on the option. */
    private const MAXANSWERS = 5;

    /** @var int jitter window in ms. 0 = all fire at once (max contention = the serialized cost). */
    private const WINDOW_MS = 0;

    public function setUp(): void {
        parent::setUp();

        // This benchmark spawns many parallel CLI processes against the shared file cache
        // and is timing dependent. It must NOT run in normal CI: it is heavy, environment
        // sensitive (relative vendor/bootstrap paths, a cross-process-coherent cache) and
        // non-deterministic. Opt in explicitly to run it.
        if (!getenv('MOD_BOOKING_RACE_BENCH')) {
            $this->markTestSkipped(
                'Concurrency benchmark. Set MOD_BOOKING_RACE_BENCH=1 to run locally '
                . '(requires a shared file cache and the ability to spawn PHP processes).'
            );
        }

        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Data provider: one entry per benchmark run.
     *
     * @return array<string, array{0:int}>
     */
    public static function run_provider(): array {
        $runs = [];
        for ($i = 1; $i <= 8; $i++) {
            $runs["run $i"] = [$i];
        }
        return $runs;
    }

    /**
     * Benchmark a single concurrency run.
     *
     * @dataProvider run_provider
     * @param int $run
     */
    public function test_bench(int $run): void {
        global $DB, $CFG;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Race bench',
        ]);

        $userids = [];
        for ($i = 0; $i < self::NPROCS; $i++) {
            $u = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
            $userids[] = (int) $u->id;
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Race bench option',
            'description' => 'Race bench option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 2 days'),
            'courseendtime_0' => strtotime('now + 5 days'),
            'limitanswers' => 1,
            'maxanswers' => self::MAXANSWERS,
            'maxoverbooking' => self::NPROCS,
            'waitforconfirmation' => 0,
        ]);

        $this->preventResetByRollback();

        $fixture = $CFG->dirroot . '/mod/booking/tests/fixtures/race_book_one_user.php';
        $this->assertFileExists($fixture);
        $tmpdir = make_request_directory();
        $gofile = $tmpdir . '/GO';

        $markers = [];
        for ($i = 0; $i < self::NPROCS; $i++) {
            $marker = $tmpdir . "/m_$i.json";
            $markers[] = $marker;
            $cmd = 'php ' . escapeshellarg($fixture)
                . ' ' . escapeshellarg($marker)
                . ' ' . escapeshellarg((string) $userids[$i])
                . ' ' . escapeshellarg($gofile)
                . ' ' . escapeshellarg((string) self::WINDOW_MS)
                . ' > /dev/null 2>&1 &';
            exec($cmd);
        }

        // Barrier: wait until all processes have bootstrapped.
        $readyfiles = array_map(static fn($m) => $m . '.ready', $markers);
        $bootdeadline = time() + 150;
        $ready = 0;
        do {
            usleep(300000);
            $ready = count(array_filter($readyfiles, 'file_exists'));
        } while ($ready < self::NPROCS && time() < $bootdeadline);

        // Starting gun.
        file_put_contents($gofile, sprintf('%.4f', microtime(true)));

        $deadline = time() + 120;
        $done = 0;
        do {
            usleep(200000);
            $done = count(array_filter($markers, 'file_exists'));
        } while ($done < self::NPROCS && time() < $deadline);

        $starts = [];
        $ends = [];
        $errors = 0;
        foreach ($markers as $marker) {
            if (!file_exists($marker)) {
                continue;
            }
            $r = json_decode(file_get_contents($marker), true);
            if (!empty($r['error'])) {
                $errors++;
                continue;
            }
            if (isset($r['start'], $r['end'])) {
                $starts[] = (float) $r['start'];
                $ends[] = (float) $r['end'];
            }
        }

        $booked = $DB->count_records_select(
            'booking_answers',
            'optionid = :oid AND waitinglist IN (0, 2)',
            ['oid' => $option->id]
        );
        $spanms = (!empty($starts) && !empty($ends)) ? round((max($ends) - min($starts)) * 1000, 1) : -1;

        $summary = [
            'run' => $run,
            'window_ms' => self::WINDOW_MS,
            'nprocs' => self::NPROCS,
            'booked' => $booked,
            'overbooked' => $booked - self::MAXANSWERS,
            'span_ms' => $spanms,
            'done' => $done,
            'ready' => $ready,
            'errors' => $errors,
        ];

        if (!is_dir('/tmp/racebench')) {
            @mkdir('/tmp/racebench', 0777, true);
        }
        file_put_contents('/tmp/racebench/run' . $run . '.json', json_encode($summary));

        $this->assertGreaterThan(0, $booked, 'no bookings happened: ' . json_encode($summary));
    }
}
