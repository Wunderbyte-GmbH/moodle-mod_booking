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
 * Booking option type resolver.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option;

use stdClass;

/**
 * Resolves booking option type and synchronizes dependent form flags.
 */
class type_resolver {
    /**
     * Check if type is one of supported option type constants.
     *
     * @param int $type
     * @return bool
     */
    private static function is_supported_type(int $type): bool {
        return in_array($type, [
            MOD_BOOKING_OPTIONTYPE_DEFAULT,
            MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE,
            MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
        ], true);
    }

    /**
     * Resolve type from form data and fallback values.
     *
     * @param stdClass $formdata
     * @param int|null $fallbacktype
     * @return int
     */
    public static function resolve_type(stdClass $formdata, ?int $fallbacktype = null): int {
        if (isset($formdata->optiontype) && is_numeric($formdata->optiontype)) {
            $type = (int)$formdata->optiontype;
            if (self::is_supported_type($type)) {
                return $type;
            }
        }

        if (!empty($formdata->slot_enabled)) {
            return MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;
        }

        if (!empty($formdata->selflearningcourse)) {
            return MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE;
        }

        if ($fallbacktype !== null && self::is_supported_type((int)$fallbacktype)) {
            return (int)$fallbacktype;
        }

        return MOD_BOOKING_OPTIONTYPE_DEFAULT;
    }

    /**
     * Normalize form flags based on selected/derived option type.
     *
     * @param stdClass $formdata
     * @param int|null $fallbacktype
     * @return int
     */
    public static function normalize_formdata(stdClass &$formdata, ?int $fallbacktype = null): int {
        $type = self::resolve_type($formdata, $fallbacktype);

        $formdata->optiontype = $type;
        $formdata->selflearningcourse = $type === MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE ? 1 : 0;
        $formdata->slot_enabled = $type === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING ? 1 : 0;

        return $type;
    }
}
