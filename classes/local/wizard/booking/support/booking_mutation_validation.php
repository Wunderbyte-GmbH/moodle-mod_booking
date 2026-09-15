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

namespace mod_booking\local\wizard\booking\support;

use context_module;
use mod_booking\local\wizard\booking\booking_skill_support;

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
     * @return array{errors:array<int,string>,ambiguities:array<int,string>,issue_codes:array<int,string>,error_details:array}
     */
    public static function validate_common(array $input, int $cmid, string $taskname): array {
        global $DB;

        $errors = [];
        $ambiguities = [];
          $issuecodes = [];

        if (!empty($input['teacherids'])) {
            if (!is_array($input['teacherids'])) {
                $errors[] = 'teacherids must be an array of integer user IDs.';
            } else {
                $teacherids = array_values(array_unique(array_filter(
                    array_map('intval', $input['teacherids']),
                    static fn(int $id): bool => $id > 0
                )));

                if (empty($teacherids)) {
                    $errors[] = 'teacherids must contain at least one valid positive user ID.';
                } else {
                    $users = $DB->get_records_list('user', 'id', $teacherids, '', 'id,email,deleted,suspended');
                    foreach ($teacherids as $teacherid) {
                        if (empty($users[$teacherid])) {
                            $errors[] = 'Teacher user ID not found: ' . $teacherid . '.';
                            continue;
                        }
                        $user = $users[$teacherid];
                        if (!empty($user->deleted) || !empty($user->suspended)) {
                            $errors[] = 'Teacher user ID is inactive: ' . $teacherid . '.';
                            continue;
                        }
                        if (trim((string)($user->email ?? '')) === '') {
                            $errors[] = 'Teacher user ID has no e-mail address: ' . $teacherid . '.';
                        }
                    }
                }
            }
        }

        try {
            $context = context_module::instance($cmid);
            $permissioncheck = booking_skill_support::validate_update_field_permissions($input, (int)$context->id);
            if (($permissioncheck['status'] ?? '') !== 'ok') {
                $errors[] = (string)($permissioncheck['message']
                    ?? get_string('agent_booking_update_permission_denied_generic', 'booking'));
            }
        } catch (\Throwable $e) {
            $errors[] = get_string('agent_booking_update_permission_check_failed', 'booking');
        }

        // F3-W2: remember where the prices errors land so their two-channel details
        // (error_details) can be re-aligned by offset at the end. $errors is append-only
        // and is returned without dedup/reindex, so positions stay stable.
        $priceerroroffset = count($errors);
        $pricevalidation = booking_skill_support::validate_prices_input($input);
        $errors = array_merge($errors, $pricevalidation['errors']);
        $ambiguities = array_merge($ambiguities, $pricevalidation['ambiguities']);

        if (empty($input['teacheremail']) && !empty($input['teacherquery'])) {
            $userresult = booking_skill_support::resolve_single_user((string)$input['teacherquery']);
            if ($userresult['status'] === 'error') {
                $errors[] = (string)$userresult['message'];
                if ((string)($userresult['issue_code'] ?? '') === 'USER_NOT_FOUND') {
                    $issuecodes[] = 'TEACHER_USER_NOT_FOUND';
                }
            } else if ($userresult['status'] === 'ambiguity') {
                $ambiguities[] = (string)$userresult['message'];
                if ((string)($userresult['issue_code'] ?? '') !== '') {
                    $issuecodes[] = (string)$userresult['issue_code'];
                }
            }
        }

        if (!empty($input['coursequery'])) {
            $courseresult = booking_skill_support::resolve_single_course((string)$input['coursequery']);
            if ($courseresult['status'] === 'error') {
                $errors[] = (string)$courseresult['message'];
            } else if ($courseresult['status'] === 'ambiguity') {
                $ambiguities[] = (string)$courseresult['message'];
            }
        }

        if (
            array_key_exists('invisible', $input)
            || array_key_exists('visibility', $input)
            || array_key_exists('visible', $input)
        ) {
            $normalizedvisibility = booking_skill_support::normalize_visibility_input($input);
            if (!empty($normalizedvisibility['error'])) {
                $errors[] = (string)$normalizedvisibility['error'];
            }
        }

        if (array_key_exists('optiondatesmode', $input)) {
            $mode = strtolower(trim((string)$input['optiondatesmode']));
            if (!in_array($mode, ['append', 'replace'], true)) {
                $errors[] = get_string('agent_validation_optiondatesmode_invalid', 'booking');
            }
        }

        if (!empty($input['enrolledincoursequery'])) {
            $restrictioncourses = booking_skill_support::resolve_courses_for_restriction((string)$input['enrolledincoursequery']);
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
                $errors[] = get_string('agent_validation_enrolledincourseoperator_invalid', 'booking');
            }
        }

        if (
            array_key_exists('enrolledincourseenabled', $input)
            && empty($input['enrolledincourseenabled'])
            && !empty($input['enrolledincoursequery'])
        ) {
            $errors[] = get_string('agent_validation_enrolledincourseenabled_disabled', 'booking');
        }

        $parsedoptiondates = booking_skill_support::extract_optiondates($input);

        // Check date/time fields. Skip validation if value is 0/empty and field is in override list.
        $overrides = is_array($input['override'] ?? null) ? $input['override'] : [];

        // Note: empty() (not isset()) — an optiondates key holding an EMPTY array, e.g. every item was
        // dropped by alias normalization (thread 545), must not disable the datetime checks.
        if (isset($input['coursestarttime']) && empty($input['optiondates'])) {
            $val = $input['coursestarttime'];
            // Skip validation only if it's a placeholder AND in override.
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('coursestarttime', $overrides, true)) {
                if (!booking_skill_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_coursestarttime_invalid', 'booking');
                }
            }
        }
        if (isset($input['courseendtime']) && empty($input['optiondates'])) {
            $val = $input['courseendtime'];
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('courseendtime', $overrides, true)) {
                if (!booking_skill_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_courseendtime_invalid', 'booking');
                }
            }
        }
        if (isset($input['bookingopeningtime'])) {
            $val = $input['bookingopeningtime'];
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('bookingopeningtime', $overrides, true)) {
                if (!booking_skill_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_bookingopeningtime_invalid', 'booking');
                }
            }
        }
        if (isset($input['bookingclosingtime'])) {
            $val = $input['bookingclosingtime'];
            $isplaceholder = $val === 0 || $val === '0' || $val === '' || $val === null;
            if (!$isplaceholder || !in_array('bookingclosingtime', $overrides, true)) {
                if (!booking_skill_support::parse_datetime($val)) {
                    $errors[] = get_string('agent_validation_bookingclosingtime_invalid', 'booking');
                }
            }
        }

        if (!empty($input['optiondates']) && empty($parsedoptiondates)) {
            $errors[] = get_string('agent_validation_optiondates_invalid', 'booking');
        }

        if (!empty($parsedoptiondates)) {
            foreach ($parsedoptiondates as $idx => $date) {
                $startts = $date['coursestarttime'];
                $endts = $date['courseendtime'];
                $label = count($parsedoptiondates) > 1
                    ? get_string('agent_validation_date_range_label', 'booking', $idx + 1)
                    : '';

                if ($startts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . get_string('agent_validation_date_start_in_past', 'booking');
                }
                if ($endts < (time() - DAYSECS)) {
                    $ambiguities[] = $label
                        . get_string('agent_validation_date_end_in_past', 'booking');
                }
                if ($endts <= $startts) {
                    $errors[] = $label . get_string('agent_validation_courseendtime_before_starttime', 'booking');
                }
            }
        }

        if (!empty($input['enrolledincohortquery'])) {
            $cohortresult = booking_skill_support::resolve_cohorts_for_restriction((string)$input['enrolledincohortquery']);
            foreach ($cohortresult['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($cohortresult['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
            $operator = strtoupper(trim((string)($input['enrolledincohortoperator'] ?? 'OR')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = get_string('agent_validation_enrolledincohortoperator_invalid', 'booking');
            }
        }

        if (
            array_key_exists('enrolledincohortenabled', $input)
            && empty($input['enrolledincohortenabled'])
            && !empty($input['enrolledincohortquery'])
        ) {
            $errors[] = get_string('agent_validation_enrolledincohortenabled_disabled', 'booking');
        }

        if (!empty($input['hascompetencyquery'])) {
            $competencyresult = booking_skill_support::resolve_competencies_for_restriction((string)$input['hascompetencyquery']);
            foreach ($competencyresult['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($competencyresult['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
            $operator = strtoupper(trim((string)($input['hascompetencyoperator'] ?? 'AND')));
            if (!in_array($operator, ['OR', 'AND'], true)) {
                $errors[] = get_string('agent_validation_hascompetencyoperator_invalid', 'booking');
            }
        }

        if (
            array_key_exists('hascompetencyenabled', $input)
            && empty($input['hascompetencyenabled'])
            && !empty($input['hascompetencyquery'])
        ) {
            $errors[] = get_string('agent_validation_hascompetencyenabled_disabled', 'booking');
        }

        if (!empty($input['previouslybookedquery'])) {
            $prev = booking_skill_support::resolve_single_option($cmid, (string)$input['previouslybookedquery']);
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
            $errors[] = get_string('agent_validation_previouslybookedenabled_disabled', 'booking');
        }

        if (!empty($input['selectusersquery'])) {
            $users = booking_skill_support::resolve_users_for_restriction((string)$input['selectusersquery']);
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
            $errors[] = get_string('agent_validation_selectusersenabled_disabled', 'booking');
        }

        if (!empty($input['bookusersquery'])) {
            $users = booking_skill_support::resolve_users_for_booking((string)$input['bookusersquery']);
            foreach ($users['errors'] as $error) {
                $errors[] = $error;
            }
            foreach ($users['ambiguities'] as $ambiguity) {
                $ambiguities[] = $ambiguity;
            }
        }

        if (!empty($input['bookusersquery'])) {
            $forbidden = booking_skill_support::detect_forbidden_fields_for_bookusers_update($input);
            if (!empty($forbidden)) {
                $errors[] = get_string(
                    'agent_validation_bookusersquery_exclusive',
                    'booking',
                    implode(', ', $forbidden)
                );
            }
        }

        if (isset($input['bookuserstimebooked']) && !booking_skill_support::parse_datetime($input['bookuserstimebooked'])) {
            $errors[] = get_string('agent_validation_bookuserstimebooked_invalid', 'booking');
        }

        if (!empty($input['nooverlappingmode'])) {
            $mode = strtolower(trim((string)$input['nooverlappingmode']));
            if (!in_array($mode, ['block', 'warn'], true)) {
                $errors[] = get_string('agent_validation_nooverlappingmode_invalid', 'booking');
            }
        }

        if (!empty($input['userprofilestandardfield']) || !empty($input['userprofilestandardoperator'])) {
            if (empty($input['userprofilestandardfield']) || empty($input['userprofilestandardoperator'])) {
                $errors[] = get_string('agent_validation_userprofile_standard_incomplete', 'booking');
            }
        }

        if (!empty($input['userprofilecustomfield']) || !empty($input['userprofilecustomoperator'])) {
            if (empty($input['userprofilecustomfield']) || empty($input['userprofilecustomoperator'])) {
                $errors[] = get_string('agent_validation_userprofile_custom_incomplete', 'booking');
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
                    $errors[] = get_string(
                        'agent_validation_overrideoperator_invalid',
                        'booking',
                        $overrideoperatorfield
                    );
                }
            }
        }

        if (isset($input['userprofilecustomfield2']) && empty($input['userprofilecustomoperator2'])) {
            $errors[] = get_string('agent_validation_userprofilecustomoperator2_required', 'booking');
        }

        if (!empty($input['duration']) && (int)$input['duration'] <= 0) {
            $errors[] = get_string('agent_validation_duration_invalid', 'booking');
        }

        if (
            array_key_exists('customformenabled', $input)
            && empty($input['customformenabled'])
            && (!empty($input['customformjson']) || !empty($input['customformelements']))
        ) {
            $errors[] = get_string('agent_validation_customformenabled_disabled', 'booking');
        }

        if (array_key_exists('customformelements', $input)) {
            if (!is_array($input['customformelements'])) {
                $errors[] = get_string('agent_validation_customformelements_not_array', 'booking');
            } else {
                $validation = booking_skill_support::validate_customform_elements($input['customformelements']);
                foreach ($validation['errors'] as $error) {
                    $errors[] = $error;
                }
            }
        }

        // F3-W2 two-channel contract: 'errors' stays the legacy channel (unchanged).
        // 'error_details' mirrors it index-by-index with {user_cause, repair}; today only the
        // prices errors carry a real split (resolver/permission texts are already user-
        // appropriate and default to themselves). Migrated skills read error_details to keep
        // planner repair vocabulary out of the user channel; legacy callers ignore it.
        $errordetails = [];
        foreach ($errors as $idx => $error) {
            $errordetails[$idx] = ['user_cause' => (string)$error, 'repair' => ''];
        }
        foreach (array_values((array)($pricevalidation['error_details'] ?? [])) as $i => $detail) {
            if (!is_array($detail) || !isset($errordetails[$priceerroroffset + $i])) {
                continue;
            }
            $errordetails[$priceerroroffset + $i] = [
                'user_cause' => (string)($detail['user_cause'] ?? $errors[$priceerroroffset + $i]),
                'repair' => (string)($detail['repair'] ?? ''),
            ];
        }

        return [
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issue_codes' => array_values(array_unique(array_filter($issuecodes))),
            'error_details' => $errordetails,
        ];
    }
}
