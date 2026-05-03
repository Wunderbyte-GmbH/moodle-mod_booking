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

namespace mod_booking\local\wbagent\booking\tasks;

use core_text;

/**
 * Helpers for post-apply verification of persisted option values.
 *
 * @package mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_input_verification {
    /**
     * Verify commonly used mutable option fields.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public static function verify_common_fields(array $input, object $settings): array {
        $warnings = [];

        if (array_key_exists('text', $input)) {
            $requested = trim((string)$input['text']);
            $actual = trim((string)($settings->text ?? ''));
            if ($requested !== '' && !self::equals_ci($requested, $actual)) {
                $warnings[] = get_string('agent_booking_verify_field_text_failed', 'mod_booking', (object)[
                    'requested' => $requested,
                    'actual' => $actual,
                ]);
            }
        }

        if (array_key_exists('location', $input)) {
            $requested = trim((string)$input['location']);
            $actual = trim((string)($settings->location ?? ''));
            $entityname = trim((string)($settings->entity['name'] ?? ''));

            if ($requested !== '') {
                $matched = self::equals_ci($requested, $actual) || self::equals_ci($requested, $entityname);
                if (!$matched) {
                    $warnings[] = get_string('agent_booking_verify_field_location_failed', 'mod_booking', (object)[
                        'requested' => $requested,
                        'actual' => $actual,
                    ]);
                }
            }
        }

        if (array_key_exists('address', $input)) {
            $requested = trim((string)$input['address']);
            $actual = trim((string)($settings->address ?? ''));
            if ($requested !== '' && !self::equals_ci($requested, $actual)) {
                $warnings[] = get_string('agent_booking_verify_field_address_failed', 'mod_booking', (object)[
                    'requested' => $requested,
                    'actual' => $actual,
                ]);
            }
        }

        if (array_key_exists('description', $input)) {
            $requested = trim((string)$input['description']);
            $actual = trim(strip_tags((string)($settings->description ?? '')));
            if ($requested !== '' && stripos($actual, $requested) === false) {
                $warnings[] = get_string('agent_booking_verify_field_description_failed', 'mod_booking');
            }
        }

        if (array_key_exists('maxanswers', $input)) {
            $requested = (int)$input['maxanswers'];
            $actual = (int)($settings->maxanswers ?? 0);
            if ($requested !== $actual) {
                $warnings[] = get_string('agent_booking_verify_field_maxanswers_failed', 'mod_booking', (object)[
                    'requested' => $requested,
                    'actual' => $actual,
                ]);
            }
        }

        if (array_key_exists('maxoverbooking', $input)) {
            $requested = (int)$input['maxoverbooking'];
            $actual = (int)($settings->maxoverbooking ?? 0);
            if ($requested !== $actual) {
                $warnings[] = get_string('agent_booking_verify_field_maxoverbooking_failed', 'mod_booking', (object)[
                    'requested' => $requested,
                    'actual' => $actual,
                ]);
            }
        }

        return $warnings;
    }

    /**
     * Case-insensitive equality for normalized text values.
     *
     * @param string $left
     * @param string $right
     * @return bool
     */
    private static function equals_ci(string $left, string $right): bool {
        return core_text::strtolower(trim($left)) === core_text::strtolower(trim($right));
    }
}
