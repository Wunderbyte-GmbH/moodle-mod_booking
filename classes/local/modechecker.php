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
        return self::is_ajax_request() || self::is_webservice_request();
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
     * Check if this is a webservice request.
     *
     * @return bool
     *
     */
    private static function is_webservice_request() {
        // Check for web service specific parameters.
        if (!empty(optional_param('wsfunction', '', PARAM_ALPHANUMEXT)) || !empty(optional_param('wstoken', '', PARAM_ALPHANUMEXT))) {
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
