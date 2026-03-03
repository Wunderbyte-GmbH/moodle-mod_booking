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

namespace mod_booking\reportbuilder\datasource;

use core_course\reportbuilder\local\entities\course_category;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\course;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\filter;
use lang_string;
use mod_booking\reportbuilder\local\entities\booking_answers;
use mod_booking\reportbuilder\local\entities\booking_options;
use mod_booking\reportbuilder\local\filters\profile_field_current_user;

/**
 * Booking options datasource for Report Builder.
 *
 * Shows completed booking options together with booking-option custom fields
 * (e.g. "strahlenschutzfortbildungspunkte") and user data.
 *
 * Two audience conditions are provided (select one when configuring the report):
 * - "Participant is current user" — each recipient sees only their own completions.
 * - "Supervisor is current user" — each recipient (supervisor) sees completions
 *   for all users whose "supervisor" profile field contains that supervisor's ID.
 *
 * @package    mod_booking
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_options_datasource extends datasource {
    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:bookingoptions', 'mod_booking');
    }

    /**
     * Initialise the datasource, define entities, joins and base conditions.
     */
    protected function initialise(): void {
        global $DB;

        // Booking options entity with customfields.
        $optionentity = new booking_options();
        $bo = $optionentity->get_table_alias('booking_options');
        $this->add_entity($optionentity);

        // Course entity (core) — the Moodle course that owns the booking instance.
        $courseentity = new course();
        $c = $courseentity->get_table_alias('course');

        // We need the booking instance table to bridge options → course.
        $bkalias = database::generate_alias();
        $this->add_entity($courseentity
            ->add_join("JOIN {booking} {$bkalias}
                          ON {$bkalias}.id = {$bo}.bookingid")
            ->add_join("JOIN {course} {$c}
                          ON {$c}.id = {$bkalias}.course"));

        // Course category entity (core).
        $coursecatentity = new course_category();
        $cc = $coursecatentity->get_table_alias('course_categories');
        $this->add_entity($coursecatentity
            ->add_joins($courseentity->get_joins())
            ->add_join("JOIN {course_categories} {$cc}
                          ON {$cc}.id = {$c}.category"));

        // Expose all columns, filters and conditions from every entity.
        $this->add_all_from_entities();
    }

    /**
     * Default columns shown when a new report is created from this datasource.
     *
     * @return array
     */
    public function get_default_columns(): array {
        return [
            'booking_options:text',
        ];
    }

    /**
     * Default column sorting.
     *
     * @return array
     */
    public function get_default_column_sorting(): array {
        return [];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return array
     */
    public function get_default_filters(): array {
        return [];
    }

    /**
     * Default conditions (always-applied admin conditions).
     *
     * @return array
     */
    public function get_default_conditions(): array {
        return [];
    }
}
