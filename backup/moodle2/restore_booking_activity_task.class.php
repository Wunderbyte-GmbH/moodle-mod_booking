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
 * Restore task that provides all the settings and steps to perform one complete restore of the booking instance.
 *
 * @package mod_booking
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/backup/moodle2/restore_booking_stepslib.php');

/**
 * booking restore task that provides all the settings and steps to perform one complete restore of the activity
 */
class restore_booking_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Booking only has one structure step.
        $this->add_step(
                new restore_booking_activity_structure_step('booking_structure', 'booking.xml'));
    }

    /**
     * Define the contents in the activity that must be processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('booking', ['intro'], 'booking');
        $contents[] = new restore_decode_content('booking_options', ['description'],
                'booking_option');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('BOOKINGVIEWBYID', '/mod/booking/view.php?id=$1',
                'course_module');
        $rules[] = new restore_decode_rule('BOOKINGINDEX', '/mod/booking/index.php?id=$1', 'course');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied by the {@see restore_logs_processor} when
     * restoring booking logs.
     * It must return one array
     * of {@see restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule('booking', 'add', 'view.php?id={course_module}', '{booking}');
        $rules[] = new restore_log_rule('booking', 'update', 'view.php?id={course_module}',
                '{booking}');
        $rules[] = new restore_log_rule('booking', 'view', 'view.php?id={course_module}', '{booking}');
        $rules[] = new restore_log_rule('booking', 'choose', 'view.php?id={course_module}',
                '{booking}');
        $rules[] = new restore_log_rule('booking', 'choose again', 'view.php?id={course_module}',
                '{booking}');
        $rules[] = new restore_log_rule('booking', 'report', 'report.php?id={course_module}',
                '{booking}');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied by the {@see restore_logs_processor} when
     * restoring course logs.
     * It must return one array of
     * {@see restore_log_rule} objects
     * Note this rules are applied when restoring course logs by the restore final task, but are
     * defined here at activity level. All them are rules
     * not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = [];

        // Fix old wrong uses (missing extension).
        $rules[] = new restore_log_rule('booking', 'view all', 'index?id={course}', null, null, null,
                'index.php?id={course}');
        $rules[] = new restore_log_rule('booking', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
