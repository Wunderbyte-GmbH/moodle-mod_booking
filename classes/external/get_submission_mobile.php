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

use cache;
use external_multiple_structure;
use core_form\external\dynamic_form;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use external_warnings;
use mod_booking\output\mobile;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for getting instance template.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_submission_mobile extends external_api {

    /**
     * Describes the parameters for update_bookingnotes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
          'itemid'  => new external_value(PARAM_INT, 'coursecategoryid', VALUE_DEFAULT, 0),
          'userid'  => new external_value(PARAM_INT, 'coursecategoryid', VALUE_DEFAULT, 0),
          'sessionkey'  => new external_value(PARAM_RAW, 'coursecategoryid', VALUE_DEFAULT, ''),
          'reset'  => new external_value(PARAM_BOOL, 'reset flag', VALUE_DEFAULT, 'false'),
          'data' => new external_multiple_structure(
              new external_single_structure(
                  [
                      'name' => new external_value(PARAM_RAW, 'data name'),
                      'value' => new external_value(PARAM_RAW, 'data value'),
                  ]
              ),
              'The data to be saved', VALUE_DEFAULT, []
            ),
        ]);
    }

    /**
     * Webservice for update the notes in booking_answers table.
     *
     * @param int $itemid
     * @param int $userid
     * @param string $sessionkey
     * @param bool $reset
     * @param array $data
     *
     * @return array
     */
    public static function execute($itemid, $userid, $sessionkey, $reset, $data): array {
        global $DB;
        $params = external_api::validate_parameters(self::execute_parameters(), [
          'itemid' => $itemid,
          'userid' => $userid,
          'sessionkey' => $sessionkey,
          'reset' => $reset,
          'data' => $data,
        ]);
        $cache = cache::make('mod_booking', 'customformuserdata');
        $cachekey = $userid . "_" . $itemid . '_customform';
        if ($reset) {
            $cache->delete($cachekey);
        } else {
            $data = self::merge_data($cache->get($cachekey), $data, $itemid, $userid);
            $cache->set($cachekey, (object)$data);
        }
        return $data;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'submitted' => new external_value(PARAM_INT, '1 for success', VALUE_DEFAULT, 0),
            'message' => new external_value(PARAM_RAW, 'Message if any', VALUE_DEFAULT, ''),
            'template' => new external_value(PARAM_TEXT, 'Button template', VALUE_DEFAULT, ''),
            'json' => new external_value(PARAM_RAW, 'Data as json', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Returns description of method result value.
     * @param array $cacheddata
     * @param array $data
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public static function merge_data($cacheddata, $data, $itemid, $userid): array {
        $datacache = [
          'id' => $itemid,
          'userid' => $userid,
        ];
        foreach ($data as $newvalues) {
            $datacache[$newvalues['name']] = $newvalues['value'];
        }
        foreach ($cacheddata as $key => $olddata) {
            if ($key != 'id' && $key != 'userid' && !isset($datacache[$key])) {
                $datacache[$key] = $olddata;
            }
        }
        return $datacache;
    }

    /**
     * Returns description of method result value.
     * @param string $itemid
     * @param string $userid
     * @param string $sesskey
     * @param array $data
     * @return string
     */
    public static function build_formdata_string($itemid, $userid, $sesskey, $data): string {
        $formdatastr =
          'id=' . $itemid .
          '&userid=' .  $userid .
          '&sesskey=' . $sesskey .
          '&_qf__mod_booking_form_condition_customform_form=1';
        foreach ($data as $subvalue) {
            if (strpos($subvalue['name'], 'shorttext') !== false) {
                $formdatastr .= '&' . $subvalue['name'] . '=' . str_replace(' ', '+', $subvalue['value']);
            } else if (strpos($subvalue['name'], 'select') !== false) {
                $formdatastr .= '&' . $subvalue['name'] . '=' . $subvalue['value'];
            } else {
                $formdatastr .= '&' . $subvalue['name'] . '=' . $subvalue['value'];
            }
        }
        return $formdatastr;
    }
}
