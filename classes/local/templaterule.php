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
 * The templaterule class handles the interactions with template rules.
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use core_component;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class templaterule
 *
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templaterule {
    /**
     * Validates each submission entry.
     * @return array
     */
    public static function get_template_rules() {
        global $DB;
        $selectoptions = [
            '0' => get_string('bookingdefaulttemplate', 'mod_booking'),
            ];
        if (get_config('booking', 'bookingruletemplatesactive')) {
            $templates = core_component::get_component_classes_in_namespace(
                "mod_booking",
                'booking_rules\\rules\\templates'
            );

            foreach ($templates as $classname => $namespace) {
                $class = new $classname();
                $id = - $classname::$templateid;
                $selectoptions[$id] = $class->get_name();
            }
        }
            $records = $DB->get_records_sql(
                "SELECT boru.id, boru.rulejson
          FROM {booking_rules} boru
          WHERE boru.useastemplate = 1"
            );

        foreach ($records as $record) {
            $record->rulejson = json_decode($record->rulejson);
            $selectoptions[$record->id] = $record->rulejson->name;
        }

        return $selectoptions;
    }

    /**
     * Returns the template record by id.
     *
     * @param int $id
     *
     * @return object
     *
     */
    public static function get_template_record_by_id(int $id) {
        $templateid = - $id;
        $templates = core_component::get_component_classes_in_namespace(
            "mod_booking",
            'booking_rules\\rules\\templates'
        );
        foreach ($templates as $classname => $namespace) {
            if ($classname::$templateid == $templateid) {
                $class = new $classname();
                $record = $class->return_template();
            }
        }
        return $record;
    }
}
