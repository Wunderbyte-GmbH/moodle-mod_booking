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
use lang_string;
use mod_booking\reportbuilder\local\entities\booking_answers;
use mod_booking\reportbuilder\local\entities\booking_options;
use mod_booking\reportbuilder\local\entities\booking_teachers;

/**
 * Booking teachers datasource for Report Builder.
 *
 * One row per teacher assignment ({booking_teachers}), joined with the booking
 * option, the teacher's user record, the course, and (optionally, LEFT JOIN)
 * the booking answers of that option together with the participant's user
 * record.
 *
 * Note: when answer/participant columns are used, rows multiply — every
 * teacher of an option is combined with every answer of that option. This is
 * inherent to reporting participants per teacher.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_teachers_datasource extends datasource {
    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:bookingteachers', 'mod_booking');
    }

    /**
     * Initialise the datasource, define entities and joins.
     */
    protected function initialise(): void {
        // Main entity: booking_teachers (the teacher-option pivot).
        $teacherlinkentity = new booking_teachers();
        $this->add_entity($teacherlinkentity);

        $bt = $teacherlinkentity->get_table_alias('booking_teachers');
        $this->set_main_table('booking_teachers', $bt);

        // Booking options entity with customfields.
        $optionentity = new booking_options();
        $bo = $optionentity->get_table_alias('booking_options');
        $this->add_entity($optionentity
            ->add_join("JOIN {booking_options} {$bo}
                          ON {$bo}.id = {$bt}.optionid"));

        // Teacher user entity (core user entity, renamed).
        $teacherentity = new user();
        $tu = $teacherentity->get_table_alias('user');
        $this->add_entity($teacherentity
            ->set_entity_name('teacher')
            ->set_entity_title(new lang_string('teacher', 'mod_booking'))
            ->add_join("JOIN {user} {$tu}
                          ON {$tu}.id = {$bt}.userid
                         AND {$tu}.deleted = 0"));

        // Booking answers entity — LEFT JOIN so teachers of options without
        // bookings are kept.
        $answerentity = new booking_answers();
        $ba = $answerentity->get_table_alias('booking_answers');
        $this->add_entity($answerentity
            ->add_join("LEFT JOIN {booking_answers} {$ba}
                               ON {$ba}.optionid = {$bt}.optionid"));

        // Participant user entity (core, default name — consistent with the
        // booking answers datasource where "user" means the participant).
        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_joins($answerentity->get_joins())
            ->add_join("LEFT JOIN {user} {$u}
                               ON {$u}.id = {$ba}.userid"));

        // Course entity (core) — the Moodle course that owns the booking instance.
        $courseentity = new course();
        $c = $courseentity->get_table_alias('course');

        // Bridge booking options to course via booking instance.
        $bkalias = database::generate_alias();
        $this->add_entity($courseentity
            ->add_joins($optionentity->get_joins())
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
            'teacher:fullname',
            'booking_options:text',
            'booking_teachers:completed',
        ];
    }

    /**
     * Default column sorting.
     *
     * @return array
     */
    public function get_default_column_sorting(): array {
        return [
            'teacher:fullname' => SORT_ASC,
        ];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return array
     */
    public function get_default_filters(): array {
        return [
            'booking_options:text',
            'teacher:fullname',
        ];
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
