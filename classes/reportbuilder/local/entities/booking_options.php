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
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\custom_fields;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use mod_booking\local\competencies\competencies_handler;
use mod_booking\reportbuilder\local\helpers\certificate_helper;
use stdClass;

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
        global $CFG;
        require_once($CFG->dirroot . '/mod/booking/lib.php');

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

        // Option ID.
        $columns[] = (new column(
            'id',
            new lang_string('optionid', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.id")
            ->set_is_sortable(true);

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

        // ID of the booking instance the option belongs to.
        $columns[] = (new column(
            'bookingid',
            new lang_string('bookingid', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.bookingid")
            ->set_is_sortable(true);

        // Name of the booking instance the option belongs to.
        $bookingalias = $this->get_table_alias('booking');
        $columns[] = (new column(
            'bookinginstance',
            new lang_string('bookinginstance', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join("LEFT JOIN {booking} {$bookingalias} ON {$bookingalias}.id = {$tablealias}.bookingid")
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$bookingalias}.name")
            ->set_is_sortable(true);

        // Competencies acquired by completing the option, stored as comma separated competency IDs.
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
                return implode(', ', self::get_competency_shortnames($value));
            });

        // Booked places: sum of the places of all booked answers (waitinglist = booked).
        $bookedparam = database::generate_param_name();
        $bookedsql = "(SELECT COALESCE(SUM(COALESCE(ba.places, 1)), 0)
                         FROM {booking_answers} ba
                        WHERE ba.optionid = {$tablealias}.id
                          AND ba.waitinglist = :{$bookedparam})";
        $columns[] = (new column(
            'bookedcount',
            new lang_string('bookedcount', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($bookedsql, 'bookedcount', [$bookedparam => MOD_BOOKING_STATUSPARAM_BOOKED])
            ->set_is_sortable(true);

        // Max. number of participants (0 = unlimited).
        $columns[] = (new column(
            'maxanswers',
            new lang_string('maxparticipantsnumber', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.maxanswers")
            ->set_is_sortable(true);

        // Utilisation: booked places in percent of max. participants (empty if unlimited).
        $utilisationparam = database::generate_param_name();
        $utilisationsql = "CASE WHEN {$tablealias}.maxanswers > 0
                                THEN " . str_replace(":{$bookedparam}", ":{$utilisationparam}", $bookedsql) . "
                                     * 100.0 / {$tablealias}.maxanswers
                                ELSE NULL END";
        $columns[] = (new column(
            'utilisation',
            new lang_string('utilisation', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field($utilisationsql, 'utilisation', [$utilisationparam => MOD_BOOKING_STATUSPARAM_BOOKED])
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                if ($value === null || $value === '') {
                    return '';
                }
                return round((float) $value) . ' %';
            });

        // Takes place / cancelled (booking_options.status: 0 = takes place, 1 = cancelled).
        $columns[] = (new column(
            'takesplace',
            new lang_string('takesplace', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.status")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return (int) $value === 1
                    ? get_string('takesplaceno', 'mod_booking')
                    : get_string('takesplaceyes', 'mod_booking');
            });

        // Visibility (booking_options.invisible: 0 = visible, 1 = invisible, 2 = visible with direct link only).
        $columns[] = (new column(
            'invisible',
            new lang_string('optionvisibility', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.invisible")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                $options = self::get_visibility_options();
                return $options[(int) $value] ?? '';
            });

        // Certificate template(s) the option grants: legacy template from the option JSON plus the templates
        // of all active certificate conditions targeting the option or its booking instance.
        $columns[] = (new column(
            'certificate',
            new lang_string('certificate', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$tablealias}.id, {$tablealias}.json, {$tablealias}.bookingid")
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row): string {
                return implode(', ', certificate_helper::get_template_names_for_option(
                    (int) $row->id,
                    $row->json,
                    (int) $row->bookingid
                ));
            });

        // Names of the active certificate conditions applying to the option.
        $columns[] = (new column(
            'certificateconditions',
            new lang_string('certificateconditions', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$tablealias}.id, {$tablealias}.bookingid")
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row): string {
                return implode(', ', certificate_helper::get_condition_names_for_option(
                    (int) $row->id,
                    (int) $row->bookingid
                ));
            });

        // Number of certificates issued for the option. The link between an issue and the option is the
        // "bookingoptionid" key of the issue's JSON data, so this needs tool_certificate and JSON SQL support.
        // The issues table is aggregated once in a derived table instead of a correlated subquery per row,
        // because the JSON extraction cannot use an index.
        $issuedjoin = self::get_issued_certificates_join($tablealias);
        if ($issuedjoin !== null) {
            [$issuedalias, $joinsql] = $issuedjoin;
            $columns[] = (new column(
                'certificatesissued',
                new lang_string('certificatesissued', 'mod_booking'),
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->add_join($joinsql)
                ->set_type(column::TYPE_INTEGER)
                ->add_field("COALESCE({$issuedalias}.issuedcount, 0)", 'certificatesissued')
                ->set_is_sortable(true);
        }

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

        // Visibility select filter.
        $filters[] = (new filter(
            select::class,
            'invisible',
            new lang_string('optionvisibility', 'mod_booking'),
            $this->get_entity_name(),
            "{$tablealias}.invisible"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback([self::class, 'get_visibility_options']);

        return $filters;
    }

    /**
     * LEFT JOIN of a derived table with the number of issued certificates per booking option.
     *
     * Returns [alias, join SQL]; the derived table exposes optionid and issuedcount. Null when tool_certificate
     * is not installed or the DB family has no JSON support (same restriction as
     * {@see \mod_booking\local\certificateclass::get_certificates_for_user_option()}).
     *
     * @param string $tablealias Alias of the booking_options table
     * @return array|null
     */
    private static function get_issued_certificates_join(string $tablealias): ?array {
        global $DB;

        if (!class_exists('tool_certificate\certificate')) {
            return null;
        }

        switch ($DB->get_dbfamily()) {
            case 'postgres':
                $optionidsql = "(data::jsonb ->> 'bookingoptionid')::int";
                $where = "(data::jsonb ->> 'bookingoptionid') ~ '^[0-9]+$'";
                break;
            case 'mysql':
                $optionidsql = "CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.bookingoptionid')) AS UNSIGNED)";
                $where = "JSON_EXTRACT(data, '$.bookingoptionid') IS NOT NULL";
                break;
            default:
                return null;
        }

        $alias = database::generate_alias();
        $join = "LEFT JOIN (SELECT {$optionidsql} AS optionid, COUNT(*) AS issuedcount
                              FROM {tool_certificate_issues}
                             WHERE {$where}
                          GROUP BY {$optionidsql}) {$alias}
                       ON {$alias}.optionid = {$tablealias}.id";

        return [$alias, $join];
    }

    /**
     * Visibility values of a booking option mapped to their readable labels.
     *
     * @return string[] indexed by the value of {booking_options}.invisible
     */
    public static function get_visibility_options(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/booking/lib.php');

        return [
            MOD_BOOKING_OPTION_VISIBLE => get_string('optionvisible', 'mod_booking'),
            MOD_BOOKING_OPTION_INVISIBLE => get_string('optioninvisible', 'mod_booking'),
            MOD_BOOKING_OPTION_VISIBLEWITHLINK => get_string('optionvisibledirectlink', 'mod_booking'),
        ];
    }

    /**
     * Resolve a stored comma separated list of competency IDs to competency shortnames.
     *
     * Unknown competencies are skipped.
     *
     * @param mixed $value Raw field value
     * @return string[]
     */
    public static function get_competency_shortnames($value): array {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $shortnames = [];
        foreach (explode(',', $value) as $competencyid) {
            $competencyid = (int) trim($competencyid);
            if ($competencyid <= 0) {
                continue;
            }
            $shortname = competencies_handler::get_competency_shortname_by_id($competencyid);
            if ($shortname !== '') {
                $shortnames[] = $shortname;
            }
        }
        return $shortnames;
    }
}
