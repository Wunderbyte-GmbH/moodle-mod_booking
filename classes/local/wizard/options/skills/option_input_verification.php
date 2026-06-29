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

namespace mod_booking\local\wizard\options\skills;

use context_module;
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
        $failures = self::verify_common_fields_structured($input, $settings);
        return array_values(array_map(
            static fn(array $failure): string => (string)($failure['message'] ?? ''),
            $failures
        ));
    }

    /**
     * Structured postcondition verification with deterministic failure codes.
     *
     * @param array $input
     * @param object $settings
     * @return array<int,array<string,mixed>>
     */
    public static function verify_common_fields_structured(array $input, object $settings): array {
        $failures = [];

        $teacherlist = [];
        if (!empty($settings->teachers) && is_array($settings->teachers)) {
            $teacherlist = $settings->teachers;
        }

        if (array_key_exists('text', $input)) {
            $requested = trim((string)$input['text']);
            $actual = trim((string)($settings->text ?? ''));
            if ($requested !== '' && !self::equals_ci($requested, $actual)) {
                self::add_failure(
                    $failures,
                    'POSTCOND_TEXT_MISMATCH',
                    get_string('agent_booking_verify_field_text_failed', 'booking', (object)[
                        'requested' => $requested,
                        'actual' => $actual,
                    ]),
                    ['field' => 'text', 'requested' => $requested, 'actual' => $actual]
                );
            }
        }

        if (array_key_exists('location', $input)) {
            $requested = trim((string)$input['location']);
            $actual = trim((string)($settings->location ?? ''));
            $entityname = trim((string)($settings->entity['name'] ?? ''));

            if ($requested !== '') {
                $matched = self::equals_ci($requested, $actual) || self::equals_ci($requested, $entityname);
                if (!$matched) {
                    self::add_failure(
                        $failures,
                        'POSTCOND_LOCATION_MISMATCH',
                        get_string('agent_booking_verify_field_location_failed', 'booking', (object)[
                            'requested' => $requested,
                            'actual' => $actual,
                        ]),
                        [
                            'field' => 'location',
                            'requested' => $requested,
                            'actual' => $actual,
                            'entityname' => $entityname,
                        ]
                    );
                }
            }
        }

        if (array_key_exists('address', $input)) {
            $requested = trim((string)$input['address']);
            $actual = trim((string)($settings->address ?? ''));
            if ($requested !== '' && !self::equals_ci($requested, $actual)) {
                self::add_failure(
                    $failures,
                    'POSTCOND_ADDRESS_MISMATCH',
                    get_string('agent_booking_verify_field_address_failed', 'booking', (object)[
                        'requested' => $requested,
                        'actual' => $actual,
                    ]),
                    ['field' => 'address', 'requested' => $requested, 'actual' => $actual]
                );
            }
        }

        if (array_key_exists('description', $input)) {
            $requested = trim((string)$input['description']);
            $actual = trim(strip_tags((string)($settings->description ?? '')));
            if ($requested !== '' && stripos($actual, $requested) === false) {
                self::add_failure(
                    $failures,
                    'POSTCOND_DESCRIPTION_MISMATCH',
                    get_string('agent_booking_verify_field_description_failed', 'booking'),
                    ['field' => 'description', 'requested' => $requested]
                );
            }
        }

        // Header image is only verified when the caller actually requested one (a non-empty
        // attachment token). An empty/unset image is a legitimate state and must not fail.
        // option_header_image_state() returns null when it cannot be checked (then we do not fail).
        if (!empty($input['headerimage_token']) && self::option_header_image_state($settings) === false) {
            self::add_failure(
                $failures,
                'POSTCOND_HEADERIMAGE_MISSING',
                get_string('agent_booking_verify_field_headerimage_failed', 'booking'),
                ['field' => 'headerimage_token']
            );
        }

        if (array_key_exists('maxanswers', $input)) {
            $requested = (int)$input['maxanswers'];
            $actual = (int)($settings->maxanswers ?? 0);
            if ($requested !== $actual) {
                self::add_failure(
                    $failures,
                    'POSTCOND_MAXANSWERS_MISMATCH',
                    get_string('agent_booking_verify_field_maxanswers_failed', 'booking', (object)[
                        'requested' => $requested,
                        'actual' => $actual,
                    ]),
                    ['field' => 'maxanswers', 'requested' => $requested, 'actual' => $actual]
                );
            }
        }

        if (array_key_exists('maxoverbooking', $input)) {
            $requested = (int)$input['maxoverbooking'];
            $actual = (int)($settings->maxoverbooking ?? 0);
            if ($requested !== $actual) {
                self::add_failure(
                    $failures,
                    'POSTCOND_MAXOVERBOOKING_MISMATCH',
                    get_string('agent_booking_verify_field_maxoverbooking_failed', 'booking', (object)[
                        'requested' => $requested,
                        'actual' => $actual,
                    ]),
                    ['field' => 'maxoverbooking', 'requested' => $requested, 'actual' => $actual]
                );
            }
        }

        if (!empty($teacherlist) && !empty($input['teacherids']) && is_array($input['teacherids'])) {
            $actualids = [];
            foreach ($teacherlist as $teacherrow) {
                if (is_object($teacherrow)) {
                    $actualids[] = (int)($teacherrow->id ?? $teacherrow->userid ?? 0);
                } else if (is_array($teacherrow)) {
                    $actualids[] = (int)($teacherrow['id'] ?? $teacherrow['userid'] ?? 0);
                }
            }
            $actualids = array_values(array_unique(array_filter($actualids, static fn(int $id): bool => $id > 0)));
            $expectedids = array_values(array_unique(array_filter(
                array_map('intval', (array)$input['teacherids']),
                static fn(int $id): bool => $id > 0
            )));

            foreach ($expectedids as $expectedid) {
                if (!in_array($expectedid, $actualids, true)) {
                    self::add_failure(
                        $failures,
                        'POSTCOND_TRAINER_ID_MISSING',
                        'Postcondition failed: expected trainer id ' . $expectedid . ' is not assigned.',
                        [
                            'field' => 'teacherids',
                            'expectedid' => $expectedid,
                            'actualids' => $actualids,
                        ]
                    );
                }
            }
        }

        if (!empty($teacherlist) && !empty($input['teacheremail'])) {
            $expectedemail = core_text::strtolower(trim((string)$input['teacheremail']));
            if ($expectedemail !== '') {
                $matched = false;
                foreach ($teacherlist as $teacherrow) {
                    $email = '';
                    if (is_object($teacherrow)) {
                        $email = (string)($teacherrow->email ?? '');
                    } else if (is_array($teacherrow)) {
                        $email = (string)($teacherrow['email'] ?? '');
                    }
                    if (core_text::strtolower(trim($email)) === $expectedemail) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    self::add_failure(
                        $failures,
                        'POSTCOND_TRAINER_EMAIL_MISSING',
                        'Postcondition failed: expected trainer email ' . $expectedemail . ' is not assigned.',
                        [
                            'field' => 'teacheremail',
                            'expectedemail' => $expectedemail,
                        ]
                    );
                }
            }
        }

        return $failures;
    }

    /**
     * Append a structured postcondition failure.
     *
     * @param array $failures
     * @param string $code
     * @param string $message
     * @param array $evidence
     * @return void
     */
    private static function add_failure(array &$failures, string $code, string $message, array $evidence = []): void {
        $failures[] = [
            'code' => trim($code),
            'message' => trim($message),
            'evidence' => $evidence,
        ];
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

    /**
     * Resolve whether the option currently has a stored header image.
     *
     * @param object $settings booking_option_settings
     * @return bool|null true = present, false = absent, null = could not be determined (no context)
     */
    private static function option_header_image_state(object $settings): ?bool {
        $optionid = (int)($settings->id ?? 0);
        $cmid = (int)($settings->cmid ?? 0);
        if ($optionid <= 0 || $cmid <= 0) {
            return null;
        }

        $context = context_module::instance($cmid);
        $fs = get_file_storage();
        $imagefiles = $fs->get_area_files(
            $context->id,
            'mod_booking',
            'bookingoptionimage',
            $optionid,
            'id',
            false
        );

        return !empty($imagefiles);
    }

    /**
     * Build a compact, human-readable summary of the REQUESTED fields and their freshly-read state.
     *
     * Used to produce a small post-mutation verification observation for the planner. Only fields
     * that were actually requested in $input are listed (incl. the header image when requested),
     * keeping the observation tiny instead of dumping the full option payload.
     *
     * @param array $input
     * @param object $settings booking_option_settings (must be freshly loaded by the caller)
     * @return array<int,string> One line per requested field.
     */
    public static function summarize_requested_state(array $input, object $settings): array {
        $lines = [];

        if (array_key_exists('text', $input) && trim((string)$input['text']) !== '') {
            $lines[] = 'title: "' . trim((string)($settings->text ?? '')) . '"';
        }
        if (array_key_exists('location', $input) && trim((string)$input['location']) !== '') {
            $lines[] = 'location: "' . trim((string)($settings->location ?? '')) . '"';
        }
        if (array_key_exists('address', $input) && trim((string)$input['address']) !== '') {
            $lines[] = 'address: "' . trim((string)($settings->address ?? '')) . '"';
        }
        if (array_key_exists('description', $input) && trim((string)$input['description']) !== '') {
            $desc = trim(strip_tags((string)($settings->description ?? '')));
            if (core_text::strlen($desc) > 80) {
                $desc = core_text::substr($desc, 0, 80) . '…';
            }
            $lines[] = 'description: "' . $desc . '"';
        }
        if (array_key_exists('maxanswers', $input)) {
            $lines[] = 'maxanswers: ' . (int)($settings->maxanswers ?? 0);
        }
        if (array_key_exists('maxoverbooking', $input)) {
            $lines[] = 'maxoverbooking: ' . (int)($settings->maxoverbooking ?? 0);
        }
        if (!empty($input['headerimage_token'])) {
            $state = self::option_header_image_state($settings);
            $lines[] = 'header image: ' . ($state === true ? 'PRESENT' : ($state === false ? 'MISSING' : 'unknown'));
        }
        if (!empty($input['teacherids']) || !empty($input['teacheremail'])) {
            $names = [];
            foreach ((array)($settings->teachers ?? []) as $teacherrow) {
                $firstname = is_object($teacherrow)
                    ? (string)($teacherrow->firstname ?? '')
                    : (string)($teacherrow['firstname'] ?? '');
                $lastname = is_object($teacherrow)
                    ? (string)($teacherrow->lastname ?? '')
                    : (string)($teacherrow['lastname'] ?? '');
                $name = trim($firstname . ' ' . $lastname);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $lines[] = 'teachers: ' . (empty($names) ? 'none' : implode(', ', $names));
        }

        return $lines;
    }
}
