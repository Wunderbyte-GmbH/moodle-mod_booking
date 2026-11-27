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
 * Authorisation of the download scripts.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use context_module;
use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers\booking_answers;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\table\manageusers_table;
use mod_booking\table\optiondates_teachers_table;
use moodle_exception;
use required_capability_exception;

/**
 * Authorisation of the download scripts.
 *
 * A download script gets the reference to a cached table and parameters from the request. The
 * table is the one that is exported, so the rights are checked on the table, and the parameters
 * of the request have to describe the same table.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_access {
    /**
     * The table with the booking options of an instance, for the download of the options.
     *
     * @param string $encodedtable reference to the cached table
     * @param int $cmid course module id of the booking instance
     * @return bookingoptions_wbtable
     */
    public static function options_table_of_instance(string $encodedtable, int $cmid): bookingoptions_wbtable {
        $context = context_module::instance($cmid);
        // The download of the options is offered to users who may edit the booking instance.
        require_capability('mod/booking:updatebooking', $context);

        /** @var bookingoptions_wbtable $table */
        $table = self::load_table($encodedtable, bookingoptions_wbtable::class);

        // A table that was built for a booking instance is only exported for this instance.
        $tablecmid = (int)($table->accesscallbackargs['cmid'] ?? 0);
        if ($tablecmid > 0 && $tablecmid !== $cmid) {
            throw new required_capability_exception($context, 'mod/booking:updatebooking', 'nopermissions', '');
        }
        return $table;
    }

    /**
     * The table with the booked users of a scope, for the download of the Bookings tracker.
     *
     * @param string $encodedtable reference to the cached table
     * @param string $scope
     * @param int $scopeid
     * @return manageusers_table
     */
    public static function users_table_of_scope(string $encodedtable, string $scope, int $scopeid): manageusers_table {
        $scopeclass = (new booking_answers())->return_class_for_scope($scope);

        // Downloading the responses (personal data of the booked users) requires
        // mod/booking:downloadresponses in the context of the scope.
        if (!$scopeclass->has_capability_in_scope($scopeid, 'mod/booking:downloadresponses')) {
            throw new required_capability_exception(
                context_system::instance(),
                'mod/booking:downloadresponses',
                'nopermissions',
                ''
            );
        }

        /** @var manageusers_table $table */
        $table = self::load_table($encodedtable, manageusers_table::class);

        // The capability was checked for the scope of the request, so the table has to be the one of this scope.
        $args = $table->accesscallbackargs ?? [];
        if (
            ($args['scope'] ?? null) !== $scopeclass::return_classname()
            || !array_key_exists('scopeid', $args)
            || (int)$args['scopeid'] !== $scopeid
        ) {
            throw new required_capability_exception(
                context_system::instance(),
                'mod/booking:downloadresponses',
                'nopermissions',
                ''
            );
        }
        return $table;
    }

    /**
     * The table of the teachers report of an option.
     *
     * @param string $encodedtable reference to the cached table
     * @return optiondates_teachers_table
     */
    public static function teachers_report_table(string $encodedtable): optiondates_teachers_table {
        /** @var optiondates_teachers_table $table */
        $table = self::load_table($encodedtable, optiondates_teachers_table::class);
        return $table;
    }

    /**
     * Load a cached table for the current user.
     *
     * @param string $encodedtable reference to the cached table
     * @param string $expectedclass the class of the table the script exports
     * @return wunderbyte_table
     */
    private static function load_table(string $encodedtable, string $expectedclass): wunderbyte_table {
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtable);

        if (!($table instanceof $expectedclass)) {
            // The cache was purged after the page with the link was rendered, or the reference
            // belongs to another kind of table.
            throw new moodle_exception('tablecacheexpired', 'local_wunderbyte_table');
        }
        // The table knows who may load it, see the declarations where the tables are built.
        $table->require_access();

        return $table;
    }
}
