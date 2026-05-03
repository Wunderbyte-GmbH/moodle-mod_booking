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
     * @param array $input
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
                $errors[] = get_string('agent_validation_optiondatesmode_invalid', 'mod_booking');
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
                $errors[] = get_string('agent_validation_enrolledincourseoperator_invalid', 'mod_booking');
            }
        }

        if (
            array_key_exists('enrolledincourseenabled', $input)
            && empty($input['enrolledincourseenabled'])
            && !empty($input['enrolledincoursequery'])
        ) {
            $errors[] = get_string('agent_validation_enrolledincourseenabled_disabled', 'mod_booking');
        }

        $parsedoptiondates = booking_task_support::extract_optiondates($input);

        // Check date/time fields. Skip validation if value is 0/empty and field is in override list.
        $overrides = is_array($input['override'] ?? null) ? $input['override'] : [];

        if (isset($input['coursestarttime']) && !isset($input['optiondates'])) {
            $val = $input['coursestarttime'];
            // Skip validation only if it's a placeholder AND in override.
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('coursestarttime', $overrides, true)) {
                if (!booking_task_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_coursestarttime_invalid', 'mod_booking');
                }
            }
        }
        if (isset($input['courseendtime']) && !isset($input['optiondates'])) {
            $val = $input['courseendtime'];
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('courseendtime', $overrides, true)) {
                if (!booking_task_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_courseendtime_invalid', 'mod_booking');
                }
            }
        }
        if (isset($input['bookingopeningtime'])) {
            $val = $input['bookingopeningtime'];
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('bookingopeningtime', $overrides, true)) {
                if (!booking_task_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_bookingopeningtime_invalid', 'mod_booking');
                }
            }
        }
        if (isset($input['bookingclosingtime'])) {
            $val = $input['bookingclosingtime'];
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('bookingclosingtime', $overrides, true)) {
                if (!booking_task_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_bookingclosingtime_invalid', 'mod_booking');
                }
            }
        }

        if (!empty($input['optiondates']) && empty($parsedoptiondates)) {
            $errors[] = get_string('agent_validation_optiondates_invalid', 'mod_booking');
        }

        if ($taskname === create_option_task::TASK_NAME) {
            foreach ($parsedoptiondates as $idx => $date) {
                $startts = $date['coursestarttime'];
                $endts = $date['courseendtime'];
                $label = count($parsedoptiondates) > 1
                    ? get_string('agent_validation_date_range_label', 'mod_booking', $idx + 1)
                    : '';

                if ($startts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . get_string('agent_validation_date_start_in_past', 'mod_booking');
                }
                if ($endts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . get_string('agent_validation_date_end_in_past', 'mod_booking');
                }
                if ($endts <= $startts) {
                    $errors[] = $label . get_string('agent_validation_courseendtime_before_starttime', 'mod_booking');
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
                $errors[] = get_string('agent_validation_enrolledincohortoperator_invalid', 'mod_booking');
            }
        }

        if (
            array_key_exists('enrolledincohortenabled', $input)
            && empty($input['enrolledincohortenabled'])
            && !empty($input['enrolledincohortquery'])
        ) {
            $errors[] = get_string('agent_validation_enrolledincohortenabled_disabled', 'mod_booking');
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
                $errors[] = get_string('agent_validation_hascompetencyoperator_invalid', 'mod_booking');
            }
        }

        if (
            array_key_exists('hascompetencyenabled', $input)
            && empty($input['hascompetencyenabled'])
            && !empty($input['hascompetencyquery'])
        ) {
            $errors[] = get_string('agent_validation_hascompetencyenabled_disabled', 'mod_booking');
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
            $errors[] = get_string('agent_validation_previouslybookedenabled_disabled', 'mod_booking');
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
            $errors[] = get_string('agent_validation_selectusersenabled_disabled', 'mod_booking');
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
                $errors[] = get_string(
                    'agent_validation_bookusersquery_exclusive',
                    'mod_booking',
                    implode(', ', $forbidden)
                );
            }
        }

        if (isset($input['bookuserstimebooked']) && !booking_task_support::parse_datetime($input['bookuserstimebooked'])) {
            $errors[] = get_string('agent_validation_bookuserstimebooked_invalid', 'mod_booking');
        }

        if (!empty($input['nooverlappingmode'])) {
            $mode = strtolower(trim((string)$input['nooverlappingmode']));
            if (!in_array($mode, ['block', 'warn'], true)) {
                $errors[] = get_string('agent_validation_nooverlappingmode_invalid', 'mod_booking');
            }
        }

        if (!empty($input['userprofilestandardfield']) || !empty($input['userprofilestandardoperator'])) {
            if (empty($input['userprofilestandardfield']) || empty($input['userprofilestandardoperator'])) {
                $errors[] = get_string('agent_validation_userprofile_standard_incomplete', 'mod_booking');
            }
        }

        if (!empty($input['userprofilecustomfield']) || !empty($input['userprofilecustomoperator'])) {
            if (empty($input['userprofilecustomfield']) || empty($input['userprofilecustomoperator'])) {
                $errors[] = get_string('agent_validation_userprofile_custom_incomplete', 'mod_booking');
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
                    $errors[] = get_string('agent_validation_overrideoperator_invalid', 'mod_booking', $overrideoperatorfield);
                }
            }
        }

        if (isset($input['userprofilecustomfield2']) && empty($input['userprofilecustomoperator2'])) {
            $errors[] = get_string('agent_validation_userprofilecustomoperator2_required', 'mod_booking');
        }

        if (!empty($input['duration']) && (int)$input['duration'] <= 0) {
            $errors[] = get_string('agent_validation_duration_invalid', 'mod_booking');
        }

        if (
            array_key_exists('customformenabled', $input)
            && empty($input['customformenabled'])
            && (!empty($input['customformjson']) || !empty($input['customformelements']))
        ) {
            $errors[] = get_string('agent_validation_customformenabled_disabled', 'mod_booking');
        }

        if (array_key_exists('customformelements', $input)) {
            if (!is_array($input['customformelements'])) {
                $errors[] = get_string('agent_validation_customformelements_not_array', 'mod_booking');
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
