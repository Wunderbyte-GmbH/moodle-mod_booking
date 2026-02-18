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
 * This class contains a webservice function related to the Booking Module by Wunderbyte.
 *
 * @package    mod_booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use cache_helper;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_booking\local\performance\performance_renderer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for editing measurement points.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    jacob Viertel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_measurement extends external_api {
    /**
     * Describes the parameters for save_measurement.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'measurementid' => new external_value(PARAM_INT, 'Measurement ID'),
        ]);
    }

    /**
     * Webservice for update the measurements in booking_performance_measurements table.
     *
     * @param string $measurementid
     *
     * @return array
     */
    public static function execute($measurementid) {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            compact('measurementid', 'note')
        );

        $context = \context_system::instance();
        // Make sure only users with the capability to edit performance can update the note.
        require_capability('mod/booking:editperformance', $context);

        $measurement = $DB->get_record(
            performance_renderer::TABLE,
            ['id' => $params['measurementid']],
            '*',
            MUST_EXIST
        );

        // Check if this is an "Entire time" measurement.
        if ($measurement->measurementname === 'Entire time') {
            // Delete all measurements that fall within the same time range.
            $DB->delete_records_select(
                performance_renderer::TABLE,
                'shortcodename = :shortcodename
                AND starttime >= :starttime
                AND endtime <= :endtime',
                [
                    'shortcodename' => $measurement->shortcodename,
                    'starttime' => $measurement->starttime,
                    'endtime' => $measurement->endtime,
                ]
            );
        } else {
            // Just delete the one selected measurement.
            $DB->delete_records(
                performance_renderer::TABLE,
                ['id' => $measurement->id]
            );
        }
        cache_helper::purge_all();

        return [
            'success' => true,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
        ]);
    }
}
