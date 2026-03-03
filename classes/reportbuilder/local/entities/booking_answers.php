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
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use mod_booking\booking;

/**
 * Booking answers (user bookings) entity for Report Builder.
 *
 * Defines columns and filters from the {booking_answers} table — the pivot
 * between users and booking options (bookings, completions, waiting-list).
 *
 * @package    mod_booking
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_answers extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'booking_answers',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entitybookinganswer', 'mod_booking');
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        $columns = $this->get_all_columns();
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        $filters = $this->get_all_filters();
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
        $ba = $this->get_table_alias('booking_answers');
        $columns = [];

        // Completed.
        $columns[] = (new column(
            'completed',
            new lang_string('completed', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$ba}.completed")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return empty($value) ? get_string('no') : get_string('yes');
            });

        // Completion date.
        $columns[] = (new column(
            'completeddate',
            new lang_string('completeddate', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$ba}.completeddate")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Time booked.
        $columns[] = (new column(
            'timebooked',
            new lang_string('timebooked', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$ba}.timebooked")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Time created.
        $columns[] = (new column(
            'timecreated',
            new lang_string('timecreated', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$ba}.timecreated")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Waiting list status.
        $columns[] = (new column(
            'waitinglist',
            new lang_string('waitinglist', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$ba}.waitinglist")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                switch ($value) {
                    case MOD_BOOKING_STATUSPARAM_BOOKED:
                        return get_string('booked', 'mod_booking');
                    case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                        return get_string('waitinglist', 'mod_booking');
                    case MOD_BOOKING_STATUSPARAM_RESERVED:
                        return get_string('vuebookingstatsreserved', 'mod_booking');
                    case MOD_BOOKING_STATUSPARAM_DELETED:
                        return get_string('deleted', 'mod_booking');
                    case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                        return get_string('bocondnotifymelist', 'mod_booking');
                    case MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED:
                        return get_string('bookingstatuspreviouslybooked', 'mod_booking');
                    default:
                        return '';
                }
            });

        // Status.
        $columns[] = (new column(
            'status',
            new lang_string('status', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ba}.status")
            ->add_callback(static function (?int $value): string {
                $statusarray = booking::get_array_of_possible_presence_statuses();
                return isset($statusarray[$value]) ? $statusarray[$value] : '';
            })
            ->set_is_sortable(true);

        // Pricecategory.
        $columns[] = (new column(
            'pricecategory',
            new lang_string('bookingpricecategory', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$ba}.pricecategory")
            ->set_is_sortable(true);
        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $ba = $this->get_table_alias('booking_answers');
        $filters = [];

        $filters[] = (new filter(
            boolean_select::class,
            'completed',
            new lang_string('completed', 'mod_booking'),
            $this->get_entity_name(),
            "{$ba}.completed"
        ))
            ->add_joins($this->get_joins());

        // Completed date filter.
        $filters[] = (new filter(
            date::class,
            'completeddate',
            new lang_string('completeddate', 'mod_booking'),
            $this->get_entity_name(),
            "{$ba}.completeddate"
        ))
            ->add_joins($this->get_joins());

        // Time booked date filter.
        $filters[] = (new filter(
            date::class,
            'timebooked',
            new lang_string('timebooked', 'mod_booking'),
            $this->get_entity_name(),
            "{$ba}.timebooked"
        ))
            ->add_joins($this->get_joins());

        // Status filter.
        $filters[] = (new filter(
            number::class,
            'status',
            new lang_string('status'),
            $this->get_entity_name(),
            "{$ba}.status"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
