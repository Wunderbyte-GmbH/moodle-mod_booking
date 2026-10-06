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

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Option search must rank title matches above other-field matches and never truncate them away.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support
 */
final class wizard_option_search_ranking_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Booking id of the test instance. */
    private int $bookingid = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB, $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Ranking Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->bookingid = (int)$booking->id;

        // The search spans these fields; teacher is the F38 noise vector.
        $DB->set_field('booking', 'optionsfields', 'text,description,location,teacher,booknow', ['id' => $this->bookingid]);
        singleton_service::destroy_booking_singleton_by_cmid($this->cmid);
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * Create one option with explicit start time; optionally assign a teacher.
     *
     * @param string $title
     * @param int $start
     * @param int|null $teacherid
     * @return int optionid
     */
    private function seed_option(string $title, int $start, ?int $teacherid = null): int {
        global $DB;
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => $this->bookingid,
            'text' => $title,
            'maxanswers' => 10,
            'type' => 0,
            'coursestarttime' => $start,
            'courseendtime' => $start + HOURSECS,
        ]);
        if ($teacherid !== null) {
            $DB->insert_record('booking_teachers', (object)[
                'bookingid' => $this->bookingid,
                'optionid' => (int)$option->id,
                'userid' => $teacherid,
            ]);
        }

        return (int)$option->id;
    }

    /**
     * The F38 replica: five early options match "Yoga" only via their TRAINER's surname,
     * one late option matches by TITLE. The title match must survive into the candidate
     * list and the message must state the true match total.
     */
    public function test_title_match_survives_teacher_noise(): void {
        $trainer = $this->getDataGenerator()->create_user(['firstname' => 'Maxima', 'lastname' => 'Yoga']);
        $base = time() + DAYSECS;
        foreach (['Code Swap', 'Abendveranstaltung', 'Dummy Option', 'Buchung1', 'von Billy'] as $i => $noise) {
            $this->seed_option($noise, $base + $i * HOURSECS, (int)$trainer->id);
        }
        $this->seed_option('Yoga im Park', $base + (10 * DAYSECS));

        $result = booking_skill_support::resolve_single_option($this->cmid, 'Yoga', '');

        $this->assertSame('ambiguity', (string)($result['status'] ?? ''), json_encode($result));
        $message = (string)($result['message'] ?? '');
        $this->assertStringContainsString('Yoga im Park', $message, 'the TITLE match must be offered to the user');
        $this->assertStringContainsString('6', $message, 'the true match total must be stated');
    }

    /**
     * The latent window bug: two options titled exactly alike, sorted BEHIND 21 fuzzy
     * matches, must still be detected as duplicates (create_option's duplicate gate
     * depends on this classification).
     */
    public function test_exact_title_duplicates_survive_fuzzy_crowd(): void {
        $base = time() + DAYSECS;
        for ($i = 1; $i <= 21; $i++) {
            $this->seed_option("Weekly Meeting {$i}", $base + $i * HOURSECS);
        }
        $this->seed_option('Weekly Meeting', $base + (30 * DAYSECS));
        $this->seed_option('Weekly Meeting', $base + (31 * DAYSECS));

        $result = booking_skill_support::find_existing_options_by_exact_title($this->cmid, 'Weekly Meeting');

        $this->assertSame(
            'multiple',
            (string)($result['status'] ?? ''),
            'exact-title duplicates must be detected even behind a crowd of fuzzy matches: ' . json_encode($result)
        );
    }
}
