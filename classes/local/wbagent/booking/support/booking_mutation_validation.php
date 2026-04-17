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

namespace mod_booking\local\wbagent\booking\support;

use context_module;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\booking\tasks\update_option_task;

/**
 * Shared validation for mutating booking tasks.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_mutation_validation {
    /**
     * Validate common mutating-input rules shared across create/update/bulk tasks.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param string $taskname
     * @return array{errors:array<int,string>,ambiguities:array<int,string>}
     */
    public static function validate_common(array $input, int $cmid, string $taskname): array {
        $errors = [];
        $ambiguities = [];

        try {
            $context = context_module::instance($cmid);
            $permissioncheck = booking_task_support::validate_update_field_permissions($input, (int)$context->id);
            if (($permissioncheck['status'] ?? '') !== 'ok') {
                $errors[] = (string)($permissioncheck['message']
                    ?? get_string('agent_booking_update_permission_denied_generic', 'booking'));
            }
        } catch (\Throwable $e) {
            $errors[] = get_string('agent_booking_update_permission_check_failed', 'booking');
        }

        $pricevalidation = booking_task_support::validate_prices_input($input);
        $errors = array_merge($errors, $pricevalidation['errors']);
        $ambiguities = array_merge($ambiguities, $pricevalidation['ambiguities']);

        if (empty($input['teacheremail']) && !empty($input['teacherquery'])) {
            $userresult = booking_task_support::resolve_single_user((string)$input['teacherquery']);
            if ($userresult['status'] === 'error') {
                $errors[] = (string)$userresult['message'];
            } else if ($userresult['status'] === 'ambiguity') {
                $ambiguities[] = (string)$userresult['message'];
            }
        }

        if (!empty($input['coursequery'])) {
            $courseresult = booking_task_support::resolve_single_course((string)$input['coursequery']);
            if ($courseresult['status'] === 'error') {
                $errors[] = (string)$courseresult['message'];
            } else if ($courseresult['status'] === 'ambiguity') {
                $ambiguities[] = (string)$courseresult['message'];
            }
        }

        if (array_key_exists('invisible', $input) || array_key_exists('visibility', $input)) {
            $normalizedvisibility = booking_task_support::normalize_visibility_input($input);
            if (!empty($normalizedvisibility['error'])) {
                $errors[] = (string)$normalizedvisibility['error'];
            }
        }

        if (array_key_exists('optiondatesmode', $input)) {
            $mode = strtolower(trim((string)$input['optiondatesmode']));
            if (!in_array($mode, ['append', 'replace'], true)) {
                $errors[] = 'Field "optiondatesmode" must be either "append" or "replace".';
            }
        }

        if (!empty($input['enrolledincoursequery'])) {
            $restrictioncourses = booking_task_support::resolve_courses_for_restriction((string)$input['enrolledincoursequery']);
            if (!empty($restrictioncourses['errors'])) {
                foreach ($restrictioncourses['errors'] as $error) {
                    $errors[] = $error;
                }
            }
            if (!empty($restrictioncourses['ambiguities'])) {
                foreach ($restrictioncourses['ambiguities'] as $ambiguity) {
                    $ambiguities[] = $ambiguity;
                }
            }

            $operator = strtoupper(trim((string)($input['enrolledincourseoperator'] ?? 'OR')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = 'Field "enrolledincourseoperator" must be either "OR" or "AND".';
            }
        }

        if (
            array_key_exists('enrolledincourseenabled', $input)
            && empty($input['enrolledincourseenabled'])
            && !empty($input['enrolledincoursequery'])
        ) {
            $errors[] = 'Cannot provide enrolledincoursequery when enrolledincourseenabled is false.';
        }

        $parsedoptiondates = booking_task_support::extract_optiondates($input);

        if (isset($input['coursestarttime']) && !isset($input['optiondates'])) {
            if (!booking_task_support::parse_datetime($input['coursestarttime'])) {
                $errors[] = 'Field "coursestarttime" must be a valid ISO 8601 date-time string or Unix timestamp.';
            }
        }
        if (isset($input['courseendtime']) && !isset($input['optiondates'])) {
            if (!booking_task_support::parse_datetime($input['courseendtime'])) {
                $errors[] = 'Field "courseendtime" must be a valid ISO 8601 date-time string or Unix timestamp.';
            }
        }
        if (isset($input['bookingopeningtime']) && !booking_task_support::parse_datetime($input['bookingopeningtime'])) {
            $errors[] = 'Field "bookingopeningtime" must be a valid ISO 8601 date-time string or Unix timestamp.';
        }
        if (isset($input['bookingclosingtime']) && !booking_task_support::parse_datetime($input['bookingclosingtime'])) {
            $errors[] = 'Field "bookingclosingtime" must be a valid ISO 8601 date-time string or Unix timestamp.';
        }

        if (isset($input['optiondates']) && empty($parsedoptiondates)) {
            $errors[] = 'Field "optiondates" must contain at least one valid date range.';
        }

        if ($taskname === create_option_task::TASK_NAME) {
            foreach ($parsedoptiondates as $idx => $date) {
                $startts = $date['coursestarttime'];
                $endts = $date['courseendtime'];
                $label = count($parsedoptiondates) > 1 ? 'Date range #' . ($idx + 1) . ': ' : '';

                if ($startts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . 'The provided start time appears to be in the past. '
                        . 'Please confirm the intended date/time.';
                }
                if ($endts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . 'The provided end time appears to be in the past. '
                        . 'Please confirm the intended date/time.';
                }
                if ($endts <= $startts) {
                    $errors[] = $label . '"courseendtime" must be later than "coursestarttime".';
                }
            }
        }

        if (!empty($input['enrolledincohortquery'])) {
            $cohortresult = booking_task_support::resolve_cohorts_for_restriction((string)$input['enrolledincohortquery']);
            foreach ($cohortresult['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($cohortresult['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
            $operator = strtoupper(trim((string)($input['enrolledincohortoperator'] ?? 'OR')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = 'Field "enrolledincohortoperator" must be either "OR" or "AND".';
            }
        }

        if (
            array_key_exists('enrolledincohortenabled', $input)
            && empty($input['enrolledincohortenabled'])
            && !empty($input['enrolledincohortquery'])
        ) {
            $errors[] = 'Cannot provide enrolledincohortquery when enrolledincohortenabled is false.';
        }

        if (!empty($input['hascompetencyquery'])) {
            $competencyresult = booking_task_support::resolve_competencies_for_restriction((string)$input['hascompetencyquery']);
            foreach ($competencyresult['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($competencyresult['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
            $operator = strtoupper(trim((string)($input['hascompetencyoperator'] ?? 'AND')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = 'Field "hascompetencyoperator" must be either "OR" or "AND".';
            }
        }

        if (
            array_key_exists('hascompetencyenabled', $input)
            && empty($input['hascompetencyenabled'])
            && !empty($input['hascompetencyquery'])
        ) {
            $errors[] = 'Cannot provide hascompetencyquery when hascompetencyenabled is false.';
        }

        if (!empty($input['previouslybookedquery'])) {
            $prev = booking_task_support::resolve_single_option($cmid, (string)$input['previouslybookedquery']);
            if ($prev['status'] === 'error') {
                $errors[] = (string)$prev['message'];
            } else if ($prev['status'] === 'ambiguity') {
                $ambiguities[] = (string)$prev['message'];
            }
        }

        if (
            array_key_exists('previouslybookedenabled', $input)
            && empty($input['previouslybookedenabled'])
            && !empty($input['previouslybookedquery'])
        ) {
            $errors[] = 'Cannot provide previouslybookedquery when previouslybookedenabled is false.';
        }

        if (!empty($input['selectusersquery'])) {
            $users = booking_task_support::resolve_users_for_restriction((string)$input['selectusersquery']);
            foreach ($users['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($users['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
        }

        if (
            array_key_exists('selectusersenabled', $input)
            && empty($input['selectusersenabled'])
            && !empty($input['selectusersquery'])
        ) {
            $errors[] = 'Cannot provide selectusersquery when selectusersenabled is false.';
        }

        if (
            ($taskname === create_option_task::TASK_NAME || $taskname === update_option_task::TASK_NAME)
            && !empty($input['bookusersquery'])
        ) {
            $users = booking_task_support::resolve_users_for_booking((string)$input['bookusersquery']);
            foreach ($users['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($users['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
        }

        if ($taskname === update_option_task::TASK_NAME && !empty($input['bookusersquery'])) {
            $forbidden = booking_task_support::detect_forbidden_fields_for_bookusers_update($input);
            if (!empty($forbidden)) {
                $errors[] = 'When using "bookusersquery" in booking.update_option, '
                    . 'no option updates are allowed in the same command. '
                    . 'Remove these fields: ' . implode(', ', $forbidden) . '.';
            }
        }

        if (isset($input['bookuserstimebooked']) && !booking_task_support::parse_datetime($input['bookuserstimebooked'])) {
            $errors[] = 'Field "bookuserstimebooked" must be a valid ISO 8601 date-time string or Unix timestamp.';
        }

        if (!empty($input['nooverlappingmode'])) {
            $mode = strtolower(trim((string)$input['nooverlappingmode']));
            if (!in_array($mode, ['block', 'warn'], true)) {
                $errors[] = 'Field "nooverlappingmode" must be "block" or "warn".';
            }
        }

        if (!empty($input['userprofilestandardfield']) || !empty($input['userprofilestandardoperator'])) {
            if (empty($input['userprofilestandardfield']) || empty($input['userprofilestandardoperator'])) {
                $errors[] = 'For standard profile condition, provide userprofilestandardfield and userprofilestandardoperator.';
            }
        }

        if (!empty($input['userprofilecustomfield']) || !empty($input['userprofilecustomoperator'])) {
            if (empty($input['userprofilecustomfield']) || empty($input['userprofilecustomoperator'])) {
                $errors[] = 'For custom profile condition, provide userprofilecustomfield and userprofilecustomoperator.';
            }
        }

        foreach (
            [
                'enrolledincourseoverrideoperator',
                'enrolledincohortoverrideoperator',
                'hascompetencyoverrideoperator',
                'selectusersoverrideoperator',
                'userprofilestandardoverrideoperator',
                'userprofilecustomoverrideoperator',
            ] as $overrideoperatorfield
        ) {
            if (isset($input[$overrideoperatorfield])) {
                $op = strtoupper(trim((string)$input[$overrideoperatorfield]));
                if (!in_array($op, ['OR', 'AND'], true)) {
                    $errors[] = 'Field "' . $overrideoperatorfield . '" must be "OR" or "AND".';
                }
            }
        }

        if (isset($input['userprofilecustomfield2']) && empty($input['userprofilecustomoperator2'])) {
            $errors[] = 'Field "userprofilecustomoperator2" is required when "userprofilecustomfield2" is provided.';
        }

        if (isset($input['duration']) && (int)$input['duration'] <= 0) {
            $errors[] = 'Field "duration" must be a positive integer (seconds).';
        }

        if (
            array_key_exists('customformenabled', $input)
            && empty($input['customformenabled'])
            && (!empty($input['customformjson']) || !empty($input['customformelements']))
        ) {
            $errors[] = 'Cannot provide custom form content when customformenabled is false.';
        }

        if (array_key_exists('customformelements', $input)) {
            if (!is_array($input['customformelements'])) {
                $errors[] = 'Field "customformelements" must be an array.';
            } else {
                $validation = booking_task_support::validate_customform_elements($input['customformelements']);
                foreach ($validation['errors'] as $error) {
                    $errors[] = $error;
                }
            }
        }

        return [
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
    }
}
