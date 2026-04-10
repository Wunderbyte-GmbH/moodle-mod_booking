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
 * External service: list candidate booking options for disambiguation.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use mod_booking\agent\authorization_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Return the list of booking options available in the given instance.
 *
 * Used by the UI to let users pick a specific option when the AI is
 * ambiguous about which one should be updated.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_list_candidate_options extends external_api {

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course-module id.'),
            'search' => new external_value(PARAM_TEXT, 'Optional search string to filter options.', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Return booking options.
     *
     * @param int    $cmid
     * @param string $search
     * @return array
     */
    public static function execute(int $cmid, string $search = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'search' => $search]);

        $authz = new authorization_service();
        $authz->require_valid_context($params['cmid']);
        $authz->require_use_capability($USER->id, $params['cmid']);

        $cm = get_coursemodule_from_id('booking', $params['cmid'], 0, false, MUST_EXIST);

        $searchparam = '%' . $DB->sql_like_escape(trim($params['search'])) . '%';
        $sql = 'SELECT id, text, location, maxanswers, coursestarttime, courseendtime
                  FROM {booking_options}
                 WHERE bookingid = :bookingid';
        $sqlparams = ['bookingid' => $cm->instance];

        if (!empty($params['search'])) {
            $sql .= ' AND ' . $DB->sql_like('text', ':search', false);
            $sqlparams['search'] = $searchparam;
        }

        $sql .= ' ORDER BY text ASC';

        $options = $DB->get_records_sql($sql, $sqlparams, 0, 50);
        $result  = [];

        foreach ($options as $opt) {
            $result[] = [
                'id'              => (int)$opt->id,
                'text'            => $opt->text ?? '',
                'location'        => $opt->location ?? '',
                'maxanswers'      => (int)($opt->maxanswers ?? 0),
                'coursestarttime' => (int)($opt->coursestarttime ?? 0),
                'courseendtime'   => (int)($opt->courseendtime ?? 0),
            ];
        }

        return ['options' => $result];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'options' => new external_multiple_structure(
                new external_single_structure([
                    'id'              => new external_value(PARAM_INT, 'Booking option id.'),
                    'text'            => new external_value(PARAM_TEXT, 'Booking option title.'),
                    'location'        => new external_value(PARAM_TEXT, 'Location.', VALUE_OPTIONAL),
                    'maxanswers'      => new external_value(PARAM_INT, 'Max participants.', VALUE_OPTIONAL),
                    'coursestarttime' => new external_value(PARAM_INT, 'Start timestamp.', VALUE_OPTIONAL),
                    'courseendtime'   => new external_value(PARAM_INT, 'End timestamp.', VALUE_OPTIONAL),
                ])
            ),
        ]);
    }
}
