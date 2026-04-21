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
 * Helper for prefilling booking customform cache from optionview query params.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use mod_booking\booking_option_settings;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\mobile\customformstore;
use stdClass;

/**
 * Maps optionview query parameters to customform cache values.
 */
class customform_prefill {
    /**
     * Prefix used for optionview prefill request params.
     */
    private const PARAM_PREFIX = 'prefill_';

    /**
     * Plugin setting key to enable URL prefill support.
     */
    private const SETTING_ENABLED = 'customformprefillenabled';

    /**
     * Returns whether customform prefill is enabled globally.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return !empty(get_config('booking', self::SETTING_ENABLED));
    }

    /**
     * Prefill customform cache entries from request params.
     *
     * Supported params use the prefill_ prefix. The suffix may either match a
     * field identifier like customform_url_1 or a normalized field label.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    public static function prefill_from_request(booking_option_settings $settings, int $userid): bool {
        if (!self::is_enabled() || empty($settings->id) || empty($userid)) {
            return false;
        }

        $prefillparams = self::get_prefill_params_from_request($settings);
        if (empty($prefillparams)) {
            return false;
        }

        $prefilldata = self::build_prefill_data($settings, $prefillparams);
        if (empty((array)$prefilldata)) {
            return false;
        }

        $store = new customformstore($userid, $settings->id);
        $existingdata = $store->get_customform_data();
        $data = $existingdata ? clone $existingdata : new stdClass();

        $data->id = $settings->id;
        $data->userid = $userid;

        foreach ((array)$prefilldata as $key => $value) {
            $data->{$key} = $value;
        }

        $store->set_customform_data($data);
        return true;
    }

    /**
     * Build customform cache payload from raw prefill params.
     *
     * @param booking_option_settings $settings
     * @param array $prefillparams
     * @return stdClass
     */
    public static function build_prefill_data(booking_option_settings $settings, array $prefillparams): stdClass {
        $data = new stdClass();
        $formelements = customform::return_formelements($settings);

        foreach ((array)$formelements as $key => $formelement) {
            if (empty($formelement->formtype) || $formelement->formtype === 'static') {
                continue;
            }

            $identifier = self::get_identifier_for_formelement($formelement, (string)$key);
            $prefillkey = self::find_prefill_key_for_formelement(
                $prefillparams,
                $identifier,
                (string)($formelement->label ?? '')
            );
            if ($prefillkey === null) {
                continue;
            }

            $value = self::sanitize_prefill_value($formelement, $prefillparams[$prefillkey]);
            if ($value === null) {
                continue;
            }

            $data->{$identifier} = $value;
        }

        return $data;
    }

    /**
     * Collect prefill params from the current request using Moodle optional params.
     *
     * @return array
     */
    private static function get_prefill_params_from_request(booking_option_settings $settings): array {
        $prefillparams = [];

        $formelements = customform::return_formelements($settings);
        foreach ((array)$formelements as $key => $formelement) {
            if (empty($formelement->formtype) || $formelement->formtype === 'static') {
                continue;
            }

            $identifier = self::get_identifier_for_formelement($formelement, (string)$key);
            $paramtype = self::get_optional_param_type($formelement);
            $candidates = [
                self::PARAM_PREFIX . $identifier,
            ];

            $label = trim((string)($formelement->label ?? ''));
            if ($label !== '') {
                $candidates[] = self::PARAM_PREFIX . self::normalize_prefill_key($label);
            }

            foreach ($candidates as $candidate) {
                $value = optional_param($candidate, null, $paramtype);
                if ($value === null) {
                    continue;
                }

                $prefillparams[self::normalize_prefill_key(substr($candidate, strlen(self::PARAM_PREFIX)))] = (string)$value;
            }
        }

        return $prefillparams;
    }

    /**
     * Return the runtime identifier used by customform_form.
     *
     * @param stdClass $formelement
     * @param string $key
     * @return string
     */
    private static function get_identifier_for_formelement(stdClass $formelement, string $key): string {
        if ($formelement->formtype === 'deleteinfoscheckboxuser') {
            return 'customform_deleteinfoscheckboxuser';
        }

        return 'customform_' . $formelement->formtype . '_' . $key;
    }

    /**
     * Resolve the matching prefill key for a form element.
     *
     * @param array $prefillparams
     * @param string $identifier
     * @param string $label
     * @return string|null
     */
    private static function find_prefill_key_for_formelement(array $prefillparams, string $identifier, string $label): ?string {
        $candidates = [
            self::normalize_prefill_key($identifier),
        ];

        if ($label !== '') {
            $candidates[] = self::normalize_prefill_key($label);
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $prefillparams)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Sanitize a prefill value according to the configured field type.
     *
     * @param stdClass $formelement
     * @param string $value
     * @return int|string|null
     */
    private static function sanitize_prefill_value(stdClass $formelement, string $value) {
        switch ($formelement->formtype) {
            case 'advcheckbox':
            case 'deleteinfoscheckboxuser':
                return clean_param($value, PARAM_BOOL);
            case 'shorttext':
                $cleaned = clean_param($value, PARAM_TEXT);
                return $cleaned === '' ? null : $cleaned;
            case 'url':
                $cleaned = clean_param($value, PARAM_URL);
                return $cleaned === '' ? null : $cleaned;
            case 'mail':
                $cleaned = clean_param($value, PARAM_EMAIL);
                return $cleaned === '' ? null : $cleaned;
            case 'enrolusersaction':
                $cleaned = clean_param($value, PARAM_INT);
                return $cleaned > 0 ? $cleaned : null;
            case 'select':
                return self::sanitize_select_prefill_value((string)($formelement->value ?? ''), $value);
            default:
                $cleaned = clean_param($value, PARAM_TEXT);
                return $cleaned === '' ? null : $cleaned;
        }
    }

    /**
     * Return the Moodle param type used to read a prefill request parameter.
     *
     * @param stdClass $formelement
     * @return string
     */
    private static function get_optional_param_type(stdClass $formelement): string {
        switch ($formelement->formtype) {
            case 'advcheckbox':
            case 'deleteinfoscheckboxuser':
                return PARAM_BOOL;
            case 'shorttext':
                return PARAM_TEXT;
            case 'url':
                return PARAM_URL;
            case 'mail':
                return PARAM_EMAIL;
            case 'enrolusersaction':
                return PARAM_INT;
            case 'select':
                return PARAM_RAW_TRIMMED;
            default:
                return PARAM_TEXT;
        }
    }

    /**
     * Validate and normalize select prefill values.
     *
     * @param string $rawoptions
     * @param string $value
     * @return string|null
     */
    private static function sanitize_select_prefill_value(string $rawoptions, string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $rawoptions);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $linearray = array_map('trim', explode(' => ', $line));
            $optionkey = $linearray[0] ?? '';
            $optionlabel = $linearray[1] ?? $optionkey;

            if ($value === $optionkey || $value === $optionlabel) {
                return $optionkey;
            }
        }

        return null;
    }

    /**
     * Normalize a prefill key for comparison.
     *
     * @param string $key
     * @return string
     */
    private static function normalize_prefill_key(string $key): string {
        $key = \core_text::strtolower(trim($key));
        $key = preg_replace('/[^[:alnum:]]+/u', '_', $key);
        return trim((string)$key, '_');
    }
}
