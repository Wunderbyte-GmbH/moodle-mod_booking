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
 * @package local_shopping_cart
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

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
    public static function submission_form_submitted() :string {
        return
          '<ion-card style="background: #ffffff; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border-radius: 8px;">
            <ion-card-header style="color: #10dc60; font-size: 1.2rem;">
              <ion-icon name="checkmark-circle-outline" slot="start" style="color: #10dc60; font-size: 1.5rem;"></ion-icon>
              ' . get_string('mobile_notification', 'mod_booking') . '
            </ion-card-header>
            <ion-card-content>
              <ion-list lines="none">
                <ion-item>
                  <ion-label style="color: #323232;">
                  ' . get_string('mobile_submitted_success', 'mod_booking') . '
                  </ion-label>
                </ion-item>
              </ion-list>
            </ion-card-content>
          </ion-card>';
    }

    /**
     * Builds form for ionic mobile app
     * @param object $customform
     * @return string
     */
    public static function reset_submission_form_btn($dataglobal) :string {
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
        ' . get_string('mobile_reset_submission', 'mod_booking') . '
        </ion-button>';
        return $resetsubmissionform;
    }

    /**
     * Builds form for ionic mobile app
     * @param object $customform
     * @return string
     */
    public static function build_submission_form(
      $dataglobal,
      $ionichtml,
      $resetsubmissionform
    ) :string {
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
            ' . get_string('mobile_set_submission', 'mod_booking') . '
            </ion-button>' . $resetsubmissionform . '</ion-list></ion-card>';
        return $ionichtml;
  }
}
