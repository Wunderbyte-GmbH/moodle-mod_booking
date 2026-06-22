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
use mod_booking\event\rest_script_success;
use mod_booking\placeholders\placeholders\baid;
use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;
use context_module;
use mod_booking\event\rest_script_failed;
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
class executerestscript extends booking_action {
    /**
     * Apply action.
     * @param stdClass $actiondata
     * @param ?int $userid
     * @return int // Status. 0 is do nothing, 1 aborts after application right away.
     */
    public function apply_action(stdClass $actiondata, int $userid = 0) {

        global $USER, $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($actiondata->optionid);
        if (empty($userid)) {
            $userid = $USER->id;
        }

        // Prefer the specific booking answer addressed by its baid. This works on
        // cancellation too, where the answer is soft-deleted and would no longer
        // appear in get_usersonlist() (which only returns active answers).
        $bajson = null;
        if (!empty($actiondata->baid)) {
            $bajson = $DB->get_record('booking_answers', ['id' => $actiondata->baid]) ?: null;
        }
        if (empty($bajson)) {
            // Legacy fallback: the active answer currently on the list.
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $usersonlist = $ba->get_usersonlist();
            $bajson = $usersonlist[$userid] ?? null;
        }

        if (!empty($bajson)) {
            $restscriptresponse = self::get_script_response($bajson, $actiondata);
            if ($restscriptresponse) {
                $event = rest_script_success::create([
                    'objectid' => $actiondata->optionid,
                    'context' => context_module::instance($actiondata->cmid),
                    'userid' => $userid, // The user triggered the action.
                    'other' => [
                        'json' => json_encode($bajson),
                        'action' => json_encode($actiondata),
                        'restscriptresponse' => $restscriptresponse,
                    ],
                ]);
            } else {
                $event = rest_script_failed::create([
                    'objectid' => $actiondata->optionid,
                    'context' => context_module::instance($actiondata->cmid),
                    'userid' => $userid,
                    'other' => [
                        'json' => json_encode($bajson),
                        'action' => json_encode($actiondata),
                    ],
                ]);
            }
            $event->trigger(); // This will trigger the observer function.
        }
        return 0; // We want to abort all other after actions.
    }

    /**
     * Add action to mform
     * @param object $bookinganswer
     * @param object $actiondata
     *
     * @return string
     */
    public static function get_script_response($bookinganswer, $actiondata) {
        $params = json_decode($bookinganswer->json);
        $params = (array)$params->condition_customform;

        foreach ($params as $customkey => $custominput) {
            if (strpos($customkey, 'customform_url') !== false) {
                $params['wwwroot'] = $custominput;
                break;
            }
        }
        if (!empty($actiondata->userparameter == '1')) {
            $user = singleton_service::get_instance_of_user($bookinganswer->userid);
            $params['firstname'] = $user->firstname;
            $params['lastname'] = $user->lastname;
            $params['email'] = $user->email;
            $params['username'] = $user->username;
        }
        $params['numberofdays'] = $actiondata->numberofdays ?? 365;
        $params['token'] = $actiondata->secrettoken ?? '';
        $params['submit'] = true;

        // Headers: keep the legacy cookie, then append any configured "Key: Value" lines.
        $headers = ['Cookie: XDEBUG_SESSION=VSCODE'];
        $customheaders = trim((string)($actiondata->customheaders ?? ''));
        if ($customheaders !== '') {
            foreach (preg_split('/\R/', $customheaders) as $line) {
                $line = trim($line);
                if ($line !== '' && strpos($line, ':') !== false) {
                    $headers[] = $line;
                }
            }
        }

        // Body: when a JSON template is configured, substitute {placeholders} and send
        // JSON; otherwise keep the legacy form-field POST (backward compatible).
        $jsonbody = trim((string)($actiondata->jsonbody ?? ''));
        $usejson = ($jsonbody !== '');
        if ($usejson) {
            // Make the booking-answer id available to the {baid} placeholder for this
            // render pass. baid is the only per-purchase-unique id ("book again" lets
            // the same user buy the same option more than once).
            baid::$baid = (int)($actiondata->baid ?? 0);

            $phcmid = (int)($actiondata->cmid ?? 0);
            $phoptionid = (int)($actiondata->optionid ?? 0);
            $phuserid = (int)($bookinganswer->userid ?? 0);

            // Resolve each {placeholder} token INDIVIDUALLY. We must NOT hand the whole
            // JSON body to render_text: its matcher ( /{(.*?)}/ ) is not JSON-aware, so the
            // object's own opening "{" pairs with the first inner "}" and swallows the first
            // placeholder. The pattern below matches ONLY real placeholder tokens ({word});
            // the JSON structural braces are followed by a quote, not a word char, so they
            // are never touched. Each value is JSON-escaped so quotes/backslashes are safe.
            $jsonbody = preg_replace_callback(
                '/\{(\w+)\}/',
                function (array $m) use ($params, $phcmid, $phoptionid, $phuserid): string {
                    $token = $m[1];
                    // Custom-form field values are action-specific, not framework placeholders.
                    if (array_key_exists($token, $params) && is_scalar($params[$token])) {
                        $value = (string)$params[$token];
                    } else {
                        $value = placeholders_info::render_text(
                            '{' . $token . '}',
                            $phcmid,
                            $phoptionid,
                            $phuserid
                        );
                        // Unknown placeholder: render_text returns the token unchanged.
                        // Emit empty rather than leaving a stray brace inside the JSON.
                        if ($value === '{' . $token . '}') {
                            $value = '';
                        }
                    }
                    return trim(json_encode($value), '"');
                },
                $jsonbody
            );

            $hascontenttype = false;
            foreach ($headers as $hline) {
                if (stripos($hline, 'content-type:') === 0) {
                    $hascontenttype = true;
                    break;
                }
            }
            if (!$hascontenttype) {
                $headers[] = 'Content-Type: application/json';
            }
        }

        $verify = !empty($actiondata->sslverify);

        $curl = curl_init();

        curl_setopt_array($curl, [
          CURLOPT_URL => $actiondata->rest_script ?? null,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => $usejson ? $jsonbody : $params,
          CURLOPT_SSL_VERIFYPEER => $verify,
          CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
          CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($curl);

        $info = curl_getinfo($curl);
        $error = curl_error($curl);

        curl_close($curl);

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

        $mform->addElement('text', 'rest_script', get_string('bopathtoscript', 'mod_booking'));
        $mform->setType('rest_script', PARAM_URL);

        $mform->addElement('text', 'numberofdays', get_string('bonumberofdays', 'mod_booking'));
        $mform->setType('numberofdays', PARAM_INT);

        $mform->addElement('text', 'secrettoken', get_string('bosecrettoken', 'mod_booking'));
        $mform->setType('secrettoken', PARAM_TEXT);

        $mform->addElement(
            'advcheckbox',
            'customformparameter',
            get_string('customformparamsvalue', 'mod_booking'),
            get_string('customformparams_desc', 'mod_booking')
        );
        $mform->setDefault('customformparameter', 1);

        $mform->addElement(
            'advcheckbox',
            'adminparameter',
            get_string('adminparametervalue', 'mod_booking'),
            get_string('adminparameter_desc', 'mod_booking')
        );

        $mform->addElement(
            'advcheckbox',
            'userparameter',
            get_string('userparametervalue', 'mod_booking'),
            get_string('userparameter_desc', 'mod_booking')
        );

        // Optional: additional HTTP headers, one "Key: Value" per line (e.g. an
        // Authorization bearer). Empty = current behaviour (only the cookie header).
        $mform->addElement('textarea', 'customheaders', get_string('bocustomheaders', 'mod_booking'), ['rows' => 3]);
        $mform->setType('customheaders', PARAM_TEXT);
        $mform->addElement('static', 'customheaders_desc', '', get_string('bocustomheaders_desc', 'mod_booking'));

        // Optional: a JSON body template. When set, the request is sent as JSON
        // (instead of form fields), with mod_booking {placeholders} substituted via
        // the placeholder framework - {baid} (per-purchase order id), {email},
        // {firstname}, {lastname}, {username}, {userid}, {optionid}, ... Empty = form POST.
        $mform->addElement('textarea', 'jsonbody', get_string('bojsonbody', 'mod_booking'), ['rows' => 4]);
        $mform->setType('jsonbody', PARAM_RAW);
        $mform->addElement('static', 'jsonbody_desc', '', get_string('bojsonbody_desc', 'mod_booking'));

        // Optional: verify the TLS certificate of the target. Off by default to keep
        // the existing behaviour; turn on for real external HTTPS endpoints.
        $mform->addElement('advcheckbox', 'sslverify', get_string('bosslverify', 'mod_booking'),
            get_string('bosslverify_desc', 'mod_booking'));
        $mform->setType('sslverify', PARAM_INT);
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
        foreach ($data as $key => $value) {
            if (
                $key == 'numberofdays'
                && !is_null($value)
                && !is_number($value)
            ) {
                $errors[$key] = get_string('bocondcustomformnumberserror', 'mod_booking');
            }
        }
        return $errors;
    }
}
