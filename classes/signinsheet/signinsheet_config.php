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
 * Resolution and persistence of sign-in sheet download settings.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\signinsheet;

use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

/**
 * Resolution and persistence of sign-in sheet download settings.
 *
 * The settings a user chooses in the sign-in sheet modal on report2.php are
 * persisted in the JSON of the booking option. The resolution chain is:
 *
 * 1. Settings persisted in the booking option JSON (key "signinsheetconfig").
 * 2. Otherwise the settings of the booking instance (key "signinsheetconfig"
 *    in the instance JSON) - unless the instance says "use plugin config".
 * 3. Otherwise (or if the instance opted into the plugin config) the global
 *    plugin settings (booking/signinsheet* configs), with the hardcoded
 *    defaults of the old form on report.php as last fallback.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signinsheet_config {
    /** @var string JSON key used in both the option JSON and the instance JSON. */
    public const JSONKEY = 'signinsheetconfig';

    /**
     * Whether the sign-in sheet is generated from the configured HTML template.
     *
     * @return bool
     */
    public static function is_htmlmode(): bool {
        return get_config('booking', 'signinsheetmode') === 'htmltemplate';
    }

    /**
     * The choices for the "Display date(s) in the header" (pdfsessions) setting.
     *
     * In HTML template mode "Add date manually" (-1) is omitted: the generator
     * treats it exactly like "Hide dates" there (the fill-in line is never
     * rendered), so offering it would be misleading.
     *
     * @param array $sessionsdatetime optional session choices (id => datestring)
     * @return array
     */
    public static function pdfsessions_choices(array $sessionsdatetime = []): array {
        $choices = [0 => get_string('all')];
        if (!self::is_htmlmode()) {
            $choices[-1] = get_string('signinadddatemanually', 'mod_booking');
        }
        $choices[-2] = get_string('signinhidedate', 'mod_booking');
        return $choices + $sessionsdatetime;
    }

    /**
     * The sign-in sheet settings from the global plugin configuration,
     * falling back to the defaults of the old form on report.php.
     *
     * @return array
     */
    public static function defaults(): array {
        $configs = [
            'orientation' => ['signinsheetorientation', 'P', false],
            'orderby' => ['signinsheetorderby', 'lastname', false],
            'addemptyrows' => ['signinsheetaddemptyrows', 0, true],
            'pdftitle' => ['signinsheetpdftitle', 1, true],
            'pdfsessions' => ['signinsheetpdfsessions', -2, true],
            'signinextrasessioncols' => ['signinsheetextrasessioncols', 0, true],
            'includeteachers' => ['signinsheetincludeteachers', 0, true],
            'saveasformat' => ['signinsheetsaveasformat', 'pdf', false],
        ];

        $defaults = [];
        foreach ($configs as $key => [$configname, $fallback, $isint]) {
            $value = get_config('booking', $configname);
            if ($value === false || $value === '') {
                $value = $fallback;
            }
            $defaults[$key] = $isint ? (int)$value : $value;
        }
        return $defaults;
    }

    /**
     * The sign-in sheet settings of a booking instance.
     *
     * If the instance has no settings stored yet or has "use plugin config"
     * checked, the global plugin settings are returned.
     *
     * @param int $bookingid
     * @return array
     */
    public static function for_instance(int $bookingid): array {
        $defaults = self::defaults();
        if (empty($bookingid)) {
            return $defaults;
        }

        $instanceconfig = booking::get_value_of_json_by_key($bookingid, self::JSONKEY);
        if (empty($instanceconfig) || !empty($instanceconfig->usepluginconfig)) {
            return $defaults;
        }

        $instanceconfig = (array)$instanceconfig;
        unset($instanceconfig['usepluginconfig']);
        // Only known keys, so stale JSON cannot inject anything.
        return array_intersect_key($instanceconfig, $defaults) + $defaults;
    }

    /**
     * The effective sign-in sheet settings for a booking option.
     *
     * Settings persisted in the option JSON win, anything not (yet) stored
     * there falls back to the instance settings (see for_instance).
     *
     * @param int $optionid
     * @return array
     */
    public static function for_option(int $optionid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $base = self::for_instance((int)$settings->bookingid);

        $optionconfig = booking_option::get_value_of_json_by_key($optionid, self::JSONKEY);
        if (empty($optionconfig)) {
            return $base;
        }

        return array_intersect_key((array)$optionconfig, $base) + $base;
    }

    /**
     * Persist sign-in sheet settings in the JSON of a booking option.
     *
     * @param int $optionid
     * @param array $config
     * @return void
     */
    public static function save_for_option(int $optionid, array $config): void {
        global $DB;

        // Only persist known keys.
        $config = array_intersect_key($config, self::defaults());

        $record = $DB->get_record('booking_options', ['id' => $optionid], 'id, json', MUST_EXIST);
        booking_option::add_data_to_json($record, self::JSONKEY, (object)$config);
        $DB->update_record('booking_options', $record);

        booking_option::purge_cache_for_option($optionid);
    }

    /**
     * Build the download URL for the sign-in sheet endpoint.
     *
     * The settings are NOT part of the URL: they are persisted in the option
     * JSON (see save_for_option) and resolved server-side by the endpoint via
     * for_option(). The mode (legacy PDF vs. HTML template) is a global
     * setting, so the endpoint resolves it too.
     *
     * @param int $cmid
     * @param int $optionid
     * @return moodle_url
     */
    public static function download_url(int $cmid, int $optionid): moodle_url {
        return new moodle_url('/mod/booking/download_signinsheet.php', [
            'cmid' => $cmid,
            'optionid' => $optionid,
        ]);
    }

    /**
     * Map a settings array to the pdfoptions object the signinsheet_generator
     * constructor expects.
     *
     * The generator uses partly different property names than the config keys
     * (pdfsessions -> sessions, pdftitle -> title,
     * signinextrasessioncols -> extrasessioncols), so this mapping is kept in
     * one place.
     *
     * @param array $config settings array as returned by for_option()
     * @return stdClass
     */
    public static function pdfoptions_from_config(array $config): stdClass {
        $config = array_intersect_key($config, self::defaults()) + self::defaults();

        $pdfoptions = new stdClass();
        $pdfoptions->orientation = $config['orientation'];
        $pdfoptions->orderby = $config['orderby'];
        $pdfoptions->title = (int)$config['pdftitle'];
        $pdfoptions->sessions = (int)$config['pdfsessions'];
        $pdfoptions->extrasessioncols = (int)$config['signinextrasessioncols'];
        $pdfoptions->addemptyrows = (int)$config['addemptyrows'];
        $pdfoptions->includeteachers = empty($config['includeteachers']) ? 0 : 1;
        $pdfoptions->saveasformat = $config['saveasformat'];

        return $pdfoptions;
    }
}
