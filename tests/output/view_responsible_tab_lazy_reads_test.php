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

use mod_booking\output\view;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Read-count guard for the lazy responsible-contact tab.
 *
 * The lazy branch of view::get_rendered_table_for_responsible_contact() used
 * to run a full printtable() - including the wunderbyte_table filter build
 * with its per-column GROUP BY value counts over the whole instance - only to
 * find out whether the tab has any rows (issue #2212). A cheap existence
 * check answers that too, so the lazy path must not scale with the number of
 * options of the instance.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\output\view::get_rendered_table_for_responsible_contact
 */
final class view_responsible_tab_lazy_reads_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Seed a booking instance with $n options, all with the current user as
     * responsible contact, and return a view object for its cmid.
     *
     * @param int $n
     * @return view
     */
    private function seed_view(int $n): view {
        global $DB, $PAGE, $USER;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Responsible tab booking',
        ]);
        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $booking->cmid]));

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        for ($i = 0; $i < $n; $i++) {
            $option = $gen->create_option((object) [
                'bookingid' => $booking->id,
                'courseid' => $course->id,
                'text' => 'Option ' . $i,
                'description' => 'Option ' . $i,
                'chooseorcreatecourse' => 0,
                'coursestarttime_0' => strtotime('now + 1 day'),
                'courseendtime_0' => strtotime('now + 2 day'),
                'maxanswers' => 5,
                'maxoverbooking' => 0,
            ]);
            $DB->set_field('booking_options', 'responsiblecontact', $USER->id, ['id' => $option->id]);
        }

        // Build the view object without running its (heavy) constructor.
        $reflection = new \ReflectionClass(view::class);
        $viewobject = $reflection->newInstanceWithoutConstructor();
        $cmidproperty = $reflection->getProperty('cmid');
        $cmidproperty->setAccessible(true);
        $cmidproperty->setValue($viewobject, (int) $booking->cmid);

        return $viewobject;
    }

    /**
     * The lazy render of the responsible-contact tab must not build the whole
     * table: its DB reads have to stay flat when the instance grows.
     */
    public function test_lazy_responsible_tab_reads_do_not_scale_with_options(): void {
        global $DB;

        $viewobject = $this->seed_view(20);

        // Warm-up, then drop request singletons AND the MUC caches: the measured
        // call runs against a cold cache like the first page hit after a purge.
        // The old code rebuilt the whole table there (rawdata query, per-row
        // settings, filter value counts); the existence check stays flat.
        $viewobject->get_rendered_table_for_responsible_contact(true, true, true, true);
        singleton_service::destroy_instance();
        \cache_helper::purge_all();

        $before = $DB->perf_get_reads();
        $out = $viewobject->get_rendered_table_for_responsible_contact(true, true, true, true);
        $delta = $DB->perf_get_reads() - $before;

        $this->assertNotNull($out, 'the tab must be rendered when the user is responsible contact');
        // Measured: 7 reads for the existence check + layout metadata; the previous
        // full printtable() run cost 33 reads for 20 options and grows with the
        // instance (rawdata query, per-row settings, filter value counts).
        $this->assertLessThan(
            15,
            $delta,
            "The lazy responsible-contact tab issued {$delta} DB reads; it must answer the "
                . "empty check with one existence query instead of building the whole table (issue #2212)."
        );
    }

    /**
     * Without any option assigned to the user the tab stays hidden (null), the
     * cheap existence check must answer that as well.
     */
    public function test_lazy_responsible_tab_hidden_when_empty(): void {
        global $DB, $PAGE;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Responsible tab booking empty',
        ]);
        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $booking->cmid]));

        $reflection = new \ReflectionClass(view::class);
        $viewobject = $reflection->newInstanceWithoutConstructor();
        $cmidproperty = $reflection->getProperty('cmid');
        $cmidproperty->setAccessible(true);
        $cmidproperty->setValue($viewobject, (int) $booking->cmid);

        $this->assertNull($viewobject->get_rendered_table_for_responsible_contact(true, true, true, true));
    }
}
