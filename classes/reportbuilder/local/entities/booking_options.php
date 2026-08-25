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

namespace mod_booking\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\custom_fields;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use mod_booking\local\competencies\competencies_handler;
use mod_booking\reportbuilder\local\filters\competency_selector;

/**
 * Booking option entity for Report Builder.
 *
 * Defines columns and filters from the {booking_options} table, including
 * booking option custom fields (component=mod_booking, area=booking).
 *
 * @package    mod_booking
 * @copyright  Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_options extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'booking_options',
            'booking',
        ];
    }

    /**
     * Join from booking_options to its parent booking instance.
     *
     * @return string
     */
    protected function get_booking_join(): string {
        $optionalias = $this->get_table_alias('booking_options');
        $bookingalias = $this->get_table_alias('booking');

        return "LEFT JOIN {booking} {$bookingalias} ON {$bookingalias}.id = {$optionalias}.bookingid";
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entitybookingoption', 'mod_booking');
    }

    /**
     * Initialise the entity — register all columns, filters and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        $optionalias = $this->get_table_alias('booking_options');

        // Core booking-option custom fields (mod_booking / booking).
        $customfields = (new custom_fields(
            "{$optionalias}.id",
            $this->get_entity_name(),
            'mod_booking',
            'booking',
        ))
            ->add_joins($this->get_joins());

        // Merge custom field columns, filters and conditions with ours.
        $columns = array_merge($this->get_all_columns(), $customfields->get_columns());
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        $filters = array_merge($this->get_all_filters(), $customfields->get_filters());
        foreach ($filters as $filter) {
            $this->add_filter($filter);
            $this->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $tablealias = $this->get_table_alias('booking_options');
        $columns = [];

        // Option name / title.
        $columns[] = (new column(
            'text',
            new lang_string('bookingoption', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.text")
            ->set_is_sortable(true);

        // Title prefix.
        $columns[] = (new column(
            'titleprefix',
            new lang_string('titleprefix', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.titleprefix")
            ->set_is_sortable(true);

        // Location.
        $columns[] = (new column(
            'location',
            new lang_string('location', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.location")
            ->set_is_sortable(true);

        // Institution.
        $columns[] = (new column(
            'institution',
            new lang_string('institution', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.institution")
            ->set_is_sortable(true);

        // Course start time.
        $columns[] = (new column(
            'coursestarttime',
            new lang_string('coursestarttime', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.coursestarttime")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Course end time.
        $columns[] = (new column(
            'courseendtime',
            new lang_string('courseendtime', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.courseendtime")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Identifier (unique external identifier).
        $columns[] = (new column(
            'identifier',
            new lang_string('identifier', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.identifier")
            ->set_is_sortable(true);

        // Description.
        $columns[] = (new column(
            'description',
            new lang_string('description'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_fields("{$tablealias}.description, {$tablealias}.descriptionformat")
            ->set_is_sortable(false);

        // Maximum number of participants.
        $columns[] = (new column(
            'maxanswers',
            new lang_string('maxanswers', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.maxanswers")
            ->set_is_sortable(true);

        // Minimum number of participants.
        $columns[] = (new column(
            'minanswers',
            new lang_string('minanswers', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.minanswers")
            ->set_is_sortable(true);

        // Booking opening time (bookable from).
        $columns[] = (new column(
            'bookingopeningtime',
            new lang_string('bookingopeningtime', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.bookingopeningtime")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Booking closing time (bookable until).
        $columns[] = (new column(
            'bookingclosingtime',
            new lang_string('bookingclosingtime', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.bookingclosingtime")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Competencies acquired with this booking option (stored as comma-separated ids).
        $columns[] = (new column(
            'competencies',
            new lang_string('competencies', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.competencies")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                if (empty($value)) {
                    return '';
                }
                $names = [];
                foreach (explode(',', (string)$value) as $competencyid) {
                    $competencyid = (int)trim($competencyid);
                    if (!$competencyid) {
                        continue;
                    }
                    $shortname = competencies_handler::get_competency_shortname_by_id($competencyid);
                    if ($shortname !== '') {
                        $names[] = $shortname;
                    }
                }
                return implode(', ', $names);
            });

        // Name of the parent booking instance.
        $columns[] = (new column(
            'bookinginstance',
            new lang_string('bookinginstance', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_booking_join())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$this->get_table_alias('booking')}.name", 'bookinginstance')
            ->set_is_sortable(true);

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $tablealias = $this->get_table_alias('booking_options');
        $filters = [];

        // Option name text filter.
        $filters[] = (new filter(
            text::class,
            'text',
            new lang_string('bookingoption', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.text"
        ))
            ->add_joins($this->get_joins());

        // Location text filter.
        $filters[] = (new filter(
            text::class,
            'location',
            new lang_string('location', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.location"
        ))
            ->add_joins($this->get_joins());

        // Course start time date filter.
        $filters[] = (new filter(
            date::class,
            'coursestarttime',
            new lang_string('coursestarttime', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.coursestarttime"
        ))
            ->add_joins($this->get_joins());

        // Course end time date filter.
        $filters[] = (new filter(
            date::class,
            'courseendtime',
            new lang_string('courseendtime', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.courseendtime"
        ))
            ->add_joins($this->get_joins());

        // Maximum number of participants number filter.
        $filters[] = (new filter(
            number::class,
            'maxanswers',
            new lang_string('maxanswers', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.maxanswers"
        ))
            ->add_joins($this->get_joins());

        // Minimum number of participants number filter.
        $filters[] = (new filter(
            number::class,
            'minanswers',
            new lang_string('minanswers', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.minanswers"
        ))
            ->add_joins($this->get_joins());

        // Booking opening time date filter.
        $filters[] = (new filter(
            date::class,
            'bookingopeningtime',
            new lang_string('bookingopeningtime', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.bookingopeningtime"
        ))
            ->add_joins($this->get_joins());

        // Booking closing time date filter.
        $filters[] = (new filter(
            date::class,
            'bookingclosingtime',
            new lang_string('bookingclosingtime', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.bookingclosingtime"
        ))
            ->add_joins($this->get_joins());

        // Competencies select filter (matches an id inside the stored comma-separated list).
        $filters[] = (new filter(
            competency_selector::class,
            'competencies',
            new lang_string('competencies', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.competencies"
        ))
            ->add_joins($this->get_joins());

        // Booking instance name text filter.
        $filters[] = (new filter(
            text::class,
            'bookinginstance',
            new lang_string('bookinginstance', 'mod_booking'),
            $this->get_entity_name(),
            "{$this->get_table_alias('booking')}.name"
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_booking_join());

        return $filters;
    }
}
