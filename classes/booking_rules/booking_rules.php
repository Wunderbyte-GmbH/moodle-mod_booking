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
 * Base class for booking rules information.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules;

use coding_exception;
use context;
use dml_exception;
use mod_booking\output\ruleslist;

/**
 * Class to handle display and management of rules.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_rules {

    /** @var array $rules */
    public $rules = [];

    /**
     * Returns the rendered html for a list of rules.
     *
     * @param int $contextid
     * @return string
     */
    public static function return_rendered_list_of_saved_rules($contextid = 1) {
        global $PAGE;

        $rules = self::get_list_of_saved_rules($contextid);

        $data = new ruleslist($rules, $contextid);
        $output = $PAGE->get_renderer('booking');
        return $output->render_ruleslist($data);
    }

    /**
     * Returns the saved rules for the right context.
     * @param int $contextid
     * @return mixed
     * @throws coding_exception
     * @throws dml_exception
     */
    private static function get_list_of_saved_rules(int $contextid = 1) {

        global $DB;


        return $DB->get_records('booking_rules', ['contextid' => $contextid]);

        // Everything below is not necessary for fetching the rules, but for the affected booking options.
        // TODO: Delete it.

        // We dont know where exactly the config is in the context path.
        // There might be a config higher up, eg. for the course category.
        // Therefore, we look for all the contextids in the path, sorted by context_level.
        // We use the highest, ie most specific context_level.
        $context = context::instance_by_id($contextid);
        $path = $context->path;

        $patharray = explode('/', $path);

        $patharray = array_map(fn($a) => (int)$a, $patharray);

        list($inorequal, $params) = $DB->get_in_or_equal($patharray, SQL_PARAMS_NAMED);

        $sql = "SELECT br.*
                FROM {booking_rules} br
                JOIN {context} c ON br.contextid=c.id
                WHERE br.contextid $inorequal
                ORDER BY c.contextlevel DESC";

        return $DB->get_records_sql($sql, $params, IGNORE_MULTIPLE);
    }
}
