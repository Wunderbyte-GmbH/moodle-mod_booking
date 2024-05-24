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
 * Already booked condition (item has been booked).
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_actions\action_types;

use mod_booking\bo_actions\booking_action;
use mod_booking\singleton_service;
use mod_lti\local\ltiservice\response;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generateprolicense extends booking_action {

    /**
     * Apply action.
     * @param stdClass $actiondata
     * @param ?int $userid
     * @return int // Status. 0 is do nothing, 1 aborts after application right away.
     */
    public function apply_action(stdClass $actiondata, int $userid = 0) {

        global $DB, $USER;

        $option = singleton_service::get_instance_of_booking_option($actiondata->cmid, $actiondata->optionid);

        if (empty($userid)) {
            $userid = $USER->id;
        }

        $params = [
            'optionid' => $actiondata->optionid,
            'userid' => $userid,
        ];

        $sql = "SELECT * FROM {booking_answers}
                WHERE optionid = :optionid
                AND userid = :userid
                ORDER BY timecreated DESC
                LIMIT 1";

        $bookinganswer = $DB->get_record_sql($sql, $params);

        if ($bookinganswer) {
            $getprolicense = self::get_pro_licnense($bookinganswer, $actiondata);
        }

        $option->user_delete_response($userid);

        return 1; // We want to abort all other after actions.
    }

    /**
     * Add action to mform
     *
     * @param object $bookinganswer
     * @param array $actiondata
     *
     * @return array
     *
     */
    public static function get_pro_licnense($bookinganswer, $actiondata) {
        $response = [];

        $url = 'http://10.111.0.2:8000/wb_license/generate_license.php';
        $params = json_decode($bookinganswer->json);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $response = curl_exec($ch);

        return $response;
    }


    /**
     * Add action to mform
     *
     * @param mixed $mform
     *
     * @return void
     *
     */
    public static function add_action_to_mform(&$mform) {

        $mform->addElement('text', 'boactionname', get_string('boactionname', 'mod_booking'));

        $mform->addElement('advcheckbox',
            'customformparameter',
            get_string('customformparams_value', 'mod_booking'),
            get_string('customformparams_desc', 'mod_booking'));
        $mform->setDefault('customformparameter', 1);

        $mform->addElement('advcheckbox',
            'adminparameter',
            get_string('adminparameter_value', 'mod_booking'),
            get_string('adminparameter_desc', 'mod_booking'));

        $mform->addElement('advcheckbox',
            'userparameter',
            get_string('userparameter_value', 'mod_booking'),
            get_string('userparameter_desc', 'mod_booking'));
    }

    /**
     * Add action to mform
     *
     * @param array $data
     *
     * @return array
     *
     */
    public static function validate_action_form($data) {
        $errors = [];
        $onevalid = false;
        foreach ($data as $key => $value) {
            if (str_contains($key, 'parameter')) {
                if ($value == '1') {
                    $onevalid = true;
                } else {
                    $errors[$key] = 'One must be valid';
                }
            }
        }
        if ($onevalid) {
            $errors = [];
        }
        if (
            $data['customformparameter'] == '1' &&
            $data['userparameter'] == '1'
        ) {
            $errors['customformparameter'] = 'Is not compatible with user parameter';
            $errors['userparameter'] = 'Is not compatible with customform parameter';
        }
        return $errors;
    }
}
