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
 * The cartstore class handles the in and out of the cache.
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\mobile;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class cartstore
 *
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobileformbuilder {
    /**
     * Builds form for ionic mobile app
     * @return string
     */
    public static function submission_form_submitted(): string {
        return
          '<ion-card style="background: #ffffff; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border-radius: 8px;">
            <ion-card-header style="color: #10dc60; font-size: 1.2rem;">
              <ion-icon name="checkmark-circle-outline" slot="start" style="color: #10dc60; font-size: 1.5rem;"></ion-icon>
              ' . get_string('mobilenotification', 'mod_booking') . '
            </ion-card-header>
            <ion-card-content>
              <ion-list lines="none">
                <ion-item>
                  <ion-label style="color: #323232;">
                  ' . get_string('mobilesubmittedsuccess', 'mod_booking') . '
                  </ion-label>
                </ion-item>
              </ion-list>
            </ion-card-content>
          </ion-card>';
    }

    /**
     * Builds form for ionic mobile app
     * @param array $dataglobal
     * @return string
     */
    public static function reset_submission_form_btn($dataglobal): string {
        $resetsubmissionform =
        '<ion-button
          expand="block"
          type="submit"
          core-site-plugins-call-ws
          name="mod_booking_get_submission_mobile"
          [params]="{itemid: ' .
            ($dataglobal['id'] ?? 0) .
          ', userid: ' .
          ($dataglobal['userid'] ?? 0) .
          ', sessionkey: 0, data: {}, reset: true}"
          refreshOnSuccess="true"
        >
        ' . get_string('mobileresetsubmission', 'mod_booking') . '
        </ion-button>';
        return $resetsubmissionform;
    }

    /**
     * Builds form for ionic mobile app
     * @param array $dataglobal
     * @param string $ionichtml
     * @param string $resetsubmissionform
     * @return string
     */
    public static function build_submission_form(
        $dataglobal,
        $ionichtml,
        $resetsubmissionform
    ): string {
        $sessionkey = ", sessionkey:'" . sesskey() . "'";
        $ionichtml =
              '<ion-card><ion-list>' .
              $ionichtml .
              '<ion-button
              expand="block"
              type="submit"
              core-site-plugins-call-ws
              name="mod_booking_get_submission_mobile"
              [params]="{itemid: ' .
              ($dataglobal['id'] ?? 0) .
              ', userid: ' .
              ($dataglobal['userid'] ?? 0) .
              $sessionkey .
              ', reset: false, data:
              CoreUtilsProvider.objectToArrayOfObjects(CONTENT_OTHERDATA.data, ' . "'name'" . ', ' . "'value'" . ')}"
              refreshOnSuccess="true"
            >
            ' . get_string('mobilesetsubmission', 'mod_booking') . '
            </ion-button>' . $resetsubmissionform . '</ion-list></ion-card>';
        return $ionichtml;
    }

    /**
     * Builds form for ionic mobile app
     *
     * @param object $formsarray
     * @param array $dataglobal
     * @return string
     */
    public static function build_submission_entitites(object $formsarray, array $dataglobal) {
        global $OUTPUT;
        $ionichtml = '';
        $resetsubmissionform = '';

        foreach ($formsarray as $key => $submission) {
            $data = [
              'myform' => (array)$submission,
            ];
            if ($submission->formtype != 'static') {
                $data['myform']['name'] = 'customform_' . $submission->formtype . '_' . $key;
            }
            if (!isset($submission->error) || $submission->error) {
                switch ($submission->formtype) {
                    case 'advcheckbox':
                        $ionichtml .= $OUTPUT->render_from_template('mod_booking/mobile/ionform/advcheckbox', $data);
                        break;
                    case 'static':
                        $ionichtml .= $OUTPUT->render_from_template('mod_booking/mobile/ionform/static', $data);
                        break;
                    case 'shorttext':
                    case 'mail':
                    case 'url':
                        $ionichtml .= $OUTPUT->render_from_template('mod_booking/mobile/ionform/shorttext', $data);
                        break;
                    case 'select':
                        $data['myform'] = self::get_select_options($data['myform']);
                        $ionichtml .= $OUTPUT->render_from_template('mod_booking/mobile/ionform/select', $data);
                        break;
                }
            } else if (isset($submission->error) && !$submission->error) {
                $resetsubmissionform = self::reset_submission_form_btn($dataglobal);
            }
        }
        if ($ionichtml != '') {
            $ionichtml = self::build_submission_form(
                $dataglobal,
                $ionichtml,
                $resetsubmissionform
            );
        }
        return $ionichtml;
    }

    /**
     * Returns select array
     *
     * @param array $myform
     * @return array
     */
    public static function get_select_options(array $myform) {
        $lines = explode(PHP_EOL, $myform['value']);
        $options = [];
        foreach ($lines as $key => $line) {
            $linearray = explode(' => ', $line);
            if (count($linearray) > 1) {
                $newselect = [
                  'key_select' => $linearray[0],
                  'value_select' => $linearray[1],
                ];
            } else {
                $newselect = [
                  'key_select' => $key,
                  'value_select' => $line,
                ];
            }
            $options[] = $newselect;
        }
        $myform['values'] = $options;
        return $myform;
    }
}
