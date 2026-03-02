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

declare(strict_types=1);

namespace mod_booking\reportbuilder\datasource;

use core_course\reportbuilder\local\entities\course_category;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\course;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use mod_booking\reportbuilder\local\entities\booking_answers;
use mod_booking\reportbuilder\local\entities\booking_options;

/**
 * Booking completions datasource for Report Builder.
 *
 * Shows each participant their own completed booking options together with
 * booking-option custom fields (e.g. "strahlenschutzpunkte") and user data.
 *
 * Designed for use with **Schedule Recipient** delivery — the datasource
 * restricts rows to the current $USER so each recipient sees only their own
 * completed bookings.
 *
 * @package    mod_booking
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_completions extends datasource {
    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:bookingcompletions', 'mod_booking');
    }

    /**
     * Initialise the datasource, define entities, joins and base conditions.
     */
    protected function initialise(): void {
        global $USER;

        // Main entity: booking_answers (the user-option pivot).
        $answerentity = new booking_answers();
        $this->add_entity($answerentity);

        $ba = $answerentity->get_table_alias('booking_answers');
        $this->set_main_table('booking_answers', $ba);

        // Only completed bookings.
        $this->add_base_condition_simple("{$ba}.completed", 1);

        // Restrict to the current recipient (injected by Scheduled Reports).
        $paramuserid = database::generate_param_name();
        $this->add_base_condition_sql(
            "{$ba}.userid = :{$paramuserid}",
            [$paramuserid => (int) $USER->id]
        );

        // Booking options entity (includes custom fields like
        // "strahlenschutzpunkte" via core custom_fields helper).
        $optionentity = new booking_options();
        $bo = $optionentity->get_table_alias('booking_options');
        $this->add_entity($optionentity
            ->add_join("JOIN {booking_options} {$bo}
                          ON {$bo}.id = {$ba}.optionid"));

        // User entity (core) — the participant.
        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_join("JOIN {user} {$u}
                          ON {$u}.id = {$ba}.userid
                         AND {$u}.deleted = 0"));

        // Course entity (core) — the Moodle course that owns the
        // booking instance.
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
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'user:fullname',
            'booking_options:text',
            'booking_answers:completeddate',
        ];
    }

    /**
     * Default column sorting.
     *
     * @return array
     */
    public function get_default_column_sorting(): array {
        return [
            'booking_answers:completeddate' => SORT_DESC,
        ];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'booking_answers:completeddate',
            'booking_options:text',
        ];
    }

    /**
     * Default conditions (always-applied admin conditions).
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'booking_answers:completed',
            'booking_answers:completeddate',
        ];
    }
}
