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

namespace mod_booking\local;
use Exception;


/**
 * Modechecker allows to check for ajax or webservice requests.
 */
class modechecker {

    /**
     * Checks webservice or ajax request.
     * @return bool
     *
     */
    public static function is_ajax_or_webservice_request() {
        return self::is_ajax_request() || self::is_webservice_request() || PHPUNIT_TEST;
    }

    /**
     * We need to check if we are currently within an ajax request.
     *
     * @return bool
     *
     */
    private static function is_ajax_request() {
        // Check for the X-Requested-With header.
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }

        // Check for Moodle AJAX-related parameters or constants.
        if (!empty($_REQUEST['ajax']) || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)) {
            return true;
        }

        return false;
    }

    /**
     * It's hard to know on which page we are when using a webserivce.
     * This function determines if we should show the link to the details page or render the buttons right away.
     * It returns true when there should not be any special treatment.
     *
     * @return bool
     *
     */
    public static function use_special_details_page_treatment() {
        global $PAGE;

        // Get the current URL without the query string.
        if (!self::is_ajax_or_webservice_request()) {
            $currenturl = $PAGE->url->out_omit_querystring();
        } else {
            $currenturl = ''; // Usually should happens during unittests.
        }
        // Define the target URL path you want to check.
        $targetpath = '/mod/booking/optionview.php';
        // On the Cashier page of shopping cart, we never want to have book on detail.
        $cashierpath = '/local/shopping_cart/cashier.php';

        // Check if the current URL does not matches the target path.
        if (
            !(
                strpos($currenturl, $targetpath) !== false
                || (strpos($currenturl, $cashierpath) !== false)
            )
        ) {
            // The book only on details page avoid js and allows booking only on the details page.
            if (
                get_config('booking', 'bookonlyondetailspage')
                && (
                    !self::is_ajax_or_webservice_request()
                    || !(self::is_mod_booking_bookit()
                        || self::is_load_pre_booking_page()
                    )
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if we run the booking bookit webservice.
     *
     * @return [type]
     *
     */
    public static function is_mod_booking_bookit() {

        if (
            optional_param('info', '', PARAM_ALPHANUMEXT) === 'mod_booking_bookit'
            || optional_param('wsfunction', '', PARAM_ALPHANUMEXT) === 'mod_booking_bookit') {
                return true;
        }
        return false;
    }

    /**
     * Check if we run the booking bookit webservice.
     *
     * @return [type]
     *
     */
    public static function is_load_pre_booking_page() {
        if (
            optional_param('info', '', PARAM_ALPHANUMEXT) === 'mod_booking_load_pre_booking_page'
            || optional_param('wsfunction', '', PARAM_ALPHANUMEXT) === 'mod_booking_load_pre_booking_page') {
                return true;
        }
        return false;
    }

    /**
     * Check if this is a webservice request.
     *
     * @return bool
     *
     */
    private static function is_webservice_request() {
        // Check for web service specific parameters.
        if (
            !empty(optional_param('wsfunction', '', PARAM_ALPHANUMEXT))
            || !empty(optional_param('wstoken', '', PARAM_ALPHANUMEXT))
        ) {
            return true;
        }

        // Check for the WS_SERVER constant.
        if (defined('WS_SERVER') && WS_SERVER) {
            return true;
        }

        // Check for specific request headers.
        if (!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MoodleMobile') !== false) {
            return true;
        }

        return false;
    }
}
