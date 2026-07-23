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
use core_external\external_multiple_structure;
use core_form\external\dynamic_form;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use mod_booking\output\mobile;
use stdClass;

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
     * Describes the parameters for get_submission_mobile.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
          'itemid'  => new external_value(PARAM_INT, 'The booking option id to submit form data for', VALUE_DEFAULT, 0),
          'userid'  => new external_value(PARAM_INT, 'The user id submitting the form data', VALUE_DEFAULT, 0),
          'sessionkey'  => new external_value(PARAM_RAW, 'Session key for security verification', VALUE_DEFAULT, ''),
          'reset'  => new external_value(PARAM_BOOL, 'Whether to reset cached form data', VALUE_DEFAULT, false),
          'data' => new external_multiple_structure(
              new external_single_structure(
                  [
                      'name' => new external_value(PARAM_RAW, 'Field name'),
                      'value' => new external_value(PARAM_RAW, 'Field value'),
                  ]
              ),
              'The form field data to be saved',
              VALUE_DEFAULT,
              []
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

        try {
            $params = external_api::validate_parameters(self::execute_parameters(), [
              'itemid' => $itemid,
              'userid' => $userid,
              'sessionkey' => $sessionkey,
              'reset' => $reset,
              'data' => $data,
            ]);
        } catch (\Exception $e) {
            return [
                'submitted' => 0,
                'message' => 'Invalid parameters: ' . $e->getMessage(),
                'template' => '',
                'json' => '',
            ];
        }

        // The user needs access to the booking instance the option belongs to.
        $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings($params['itemid']);
        self::validate_context(
            empty($settings->cmid)
                ? \context_system::instance()
                : \context_module::instance($settings->cmid)
        );
        // Submitting form data for another user needs the book for others (or cashier) rights.
        \mod_booking\form\condition\customform_form::require_userid_access((int)$params['userid'], (int)$params['itemid']);

        try {
            $cache = cache::make('mod_booking', 'customformuserdata');
            $cachekey = $userid . "_" . $itemid . '_customform';

            if ($reset) {
                $cache->delete($cachekey);
                return [
                    'submitted' => 1,
                    'message' => 'Form data cleared for user ' . $userid . ' option ' . $itemid,
                    'template' => '',
                    'json' => json_encode([]),
                ];
            } else {
                $cacheddata = $cache->get($cachekey);
                $mergeddata = self::merge_data($cacheddata, $data, $itemid, $userid);
                $cache->set($cachekey, (object)$mergeddata);
                return [
                    'submitted' => 1,
                    'message' => 'Form data saved: ' . count($mergeddata) . ' fields merged for user ' . $userid,
                    'template' => '',
                    'json' => json_encode($mergeddata),
                ];
            }
        } catch (\Exception $e) {
            return [
                'submitted' => 0,
                'message' => 'Error saving form data: ' . $e->getMessage() . ' (itemid: ' . $itemid . ', userid: ' . $userid . ')',
                'template' => '',
                'json' => '',
            ];
        }
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
            ]);
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

        // Ensure $data is an array.
        if (!is_array($data)) {
            $data = [];
        }

        foreach ($data as $newvalues) {
            if (!isset($newvalues['name']) || !isset($newvalues['value'])) {
                continue;
            }
            $datacache[$newvalues['name']] = $newvalues['value'];
        }

        // Ensure $cacheddata is iterable before using foreach.
        if (is_array($cacheddata)) {
            foreach ($cacheddata as $key => $olddata) {
                if ($key != 'id' && $key != 'userid' && !isset($datacache[$key])) {
                    $datacache[$key] = $olddata;
                }
            }
        } else if (is_object($cacheddata)) {
            foreach ((array)$cacheddata as $key => $olddata) {
                if ($key != 'id' && $key != 'userid' && !isset($datacache[$key])) {
                    $datacache[$key] = $olddata;
                }
            }
        }

        return $datacache;
    }

    /**
     * Build form data string for submission.
     *
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
