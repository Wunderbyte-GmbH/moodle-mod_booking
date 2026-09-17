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
 * Tests for regenerating the date series of an existing booking option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2026 Georg Maisser
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use MoodleQuickForm;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");
require_once("$CFG->libdir/formslib.php");

/**
 * Tests for regenerating the date series of an existing booking option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2026 Georg Maisser
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class date_series_regeneration_test extends booking_advanced_testcase {
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
     * A timestamp in the shape a date_time_selector posts it.
     *
     * @param int $timestamp
     * @return array
     */
    private static function date_selector_value(int $timestamp): array {
        $date = usergetdate($timestamp);
        return [
            'day' => $date['mday'],
            'month' => $date['mon'],
            'year' => $date['year'],
            'hour' => $date['hours'],
            'minute' => $date['minutes'],
        ];
    }

    /**
     * Regenerating the series after the semester start moved forward must not reuse an id twice.
     *
     * The existing dates then no longer sit at the beginning of the series. The form already holds
     * an optiondateid for every slot it rendered before, and those submitted values must not win
     * over the mapping set_data() just calculated - otherwise the ids end up on the wrong slots,
     * some of them twice, and saving throws savingoptiondatewentwrong.
     *
     * @covers \mod_booking\dates::definition_after_data
     * @covers \mod_booking\dates::set_data
     * @covers \mod_booking\dates::save_optiondates_from_form
     *
     * @return void
     */
    public function test_regenerated_series_keeps_optiondateids_unique(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'name' => 'Test booking',
            'eventtype' => 'Test event',
        ]);
        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // The option starts five weeks after the semester will later begin.
        $semester = $plugingenerator->create_semester((object)[
            'identifier' => 'regen',
            'name' => 'Regeneration semester',
            'startdate' => strtotime('2026-11-01 00:00:00'),
            'enddate' => strtotime('2027-01-31 23:59:59'),
        ]);
        $DB->set_field('booking', 'semesterid', $semester->id, ['id' => $booking->id]);

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Regeneration option';
        $record->description = 'Test description';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->dayofweektime = 'Thursday, 08:00 - 09:20';
        $record->semesterid = $semester->id;
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $originalids = array_keys($settings->sessions);
        $this->assertGreaterThan(1, count($originalids));

        // Now the semester starts earlier, so the series grows at the front.
        $DB->set_field('booking_semesters', 'startdate', strtotime('2026-10-01 00:00:00'), ['id' => $semester->id]);
        \cache::make('mod_booking', 'cachedsemesters')->delete($semester->id);
        singleton_service::destroy_instance();
        booking_option::purge_cache_for_option($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $newseries = option\dates_handler::get_optiondate_series($semester->id, $record->dayofweektime);
        $this->assertGreaterThan(count($originalids), count($newseries['dates']));

        // What the browser posts back when "create date series" is clicked: one optiondateid per
        // slot that was rendered before, in the order they were rendered.
        $post = [
            'id' => $option->id,
            'optionid' => $option->id,
            'cmid' => $settings->cmid,
            'bookingid' => $booking->id,
            'semesterid' => $semester->id,
            'dayofweektime' => $record->dayofweektime,
            'datesmarker' => 1,
            'datescounter' => count($originalids),
            'addoptiondateseries' => 'Create date series',
        ];
        $idx = 0;
        foreach ($settings->sessions as $session) {
            $idx++;
            $post['optiondateid_' . $idx] = $session->id;
            $post['coursestarttime_' . $idx] = self::date_selector_value($session->coursestarttime);
            $post['courseendtime_' . $idx] = self::date_selector_value($session->courseendtime);
            $post['daystonotify_' . $idx] = 0;
        }

        // Replay what option_form does: set_data() first, then definition_after_data().
        $defaultvalues = (object)$post;
        dates::set_data($defaultvalues);

        $mform = new MoodleQuickForm('optionform', 'post', '');
        $mform->updateSubmission($post, []);
        $mform->_defaultValues = (array)$defaultvalues;
        dates::definition_after_data($mform, ['id' => $option->id, 'bookingid' => $booking->id]);

        // The element value is what the re-rendered form sends back to the browser, and it is
        // what exportValues() reports too, because a constant beats a submitted value.
        $slots = [];
        foreach ($mform->_elements as $element) {
            $name = $element->getName();
            if ($name !== null && strpos($name, MOD_BOOKING_FORM_OPTIONDATEID) === 0) {
                $value = $mform->getElementValue($name);
                $value = is_array($value) ? reset($value) : $value;
                $slots[(int)substr($name, strlen(MOD_BOOKING_FORM_OPTIONDATEID))] = (int)$value;
            }
        }
        ksort($slots);

        $this->assertCount(count($newseries['dates']), $slots, 'The form must render one slot per series date.');

        $usedids = array_values(array_filter($slots));
        $this->assertSame(
            array_values(array_unique($usedids)),
            $usedids,
            'No optiondateid may be handed out to more than one slot.'
        );
        foreach ($originalids as $originalid) {
            $this->assertContains($originalid, $usedids, 'Every existing date must keep its id.');
        }

        // Saving the form must go through and must not lose any of the existing dates.
        $formdata = new stdClass();
        $formdata->id = $option->id;
        $formdata->cmid = $settings->cmid;
        foreach ($slots as $slotidx => $optiondateid) {
            $date = $newseries['dates'][$slotidx - 1];
            $formdata->{MOD_BOOKING_FORM_OPTIONDATEID . $slotidx} = $optiondateid;
            $formdata->{MOD_BOOKING_FORM_COURSESTARTTIME . $slotidx} = $date->starttimestamp;
            $formdata->{MOD_BOOKING_FORM_COURSEENDTIME . $slotidx} = $date->endtimestamp;
            $formdata->{MOD_BOOKING_FORM_DAYSTONOTIFY . $slotidx} = 0;
        }
        $optionrecord = $DB->get_record('booking_options', ['id' => $option->id]);
        dates::save_optiondates_from_form($formdata, $optionrecord);

        $saved = $DB->get_records('booking_optiondates', ['optionid' => $option->id]);
        $this->assertCount(count($newseries['dates']), $saved);
        foreach ($originalids as $originalid) {
            $this->assertArrayHasKey($originalid, $saved, 'An existing date was deleted instead of kept.');
        }
    }
}
