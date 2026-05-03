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

namespace mod_booking\local\wbagent\booking;

use context_module;
use mod_booking\booking_option;
use mod_booking\local\wbagent\booking\support\booking_mutation_validation;
use mod_booking\local\wbagent\booking\tasks\bulk_update_options_task;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\booking\tasks\update_option_task;

/**
 * Execute service for mutating booking AI tasks.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_task_mutation_execute_service {
    /**
     * Execute supported mutating tasks.
     *
     * @param string $taskname
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param booking_task_support $support
     * @return array<string,mixed>|null Null when task is not handled here.
     */
    public function execute(string $taskname, array $input, int $cmid, int $userid, booking_task_support $support): ?array {
        global $CFG, $USER;

        if (
            !in_array(
                $taskname,
                [
                    create_option_task::TASK_NAME,
                    update_option_task::TASK_NAME,
                    bulk_update_options_task::TASK_NAME,
                ],
                true
            )
        ) {
            return null;
        }

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $preflight = $this->preflight_validate($taskname, $input, $cmid, $userid);
        if (!empty($preflight['errors'])) {
            return [
                'status' => 'error',
                'detail' => trim(implode(' ', $preflight['errors'])),
                'resultid' => null,
            ];
        }
        if (!empty($preflight['ambiguities'])) {
            return [
                'status' => 'error',
                'detail' => trim(implode(' ', $preflight['ambiguities'])),
                'resultid' => null,
            ];
        }

        $input = is_array($preflight['normalized_input'] ?? null)
            ? (array)$preflight['normalized_input']
            : $input;

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            return ['status' => 'error', 'detail' => 'Invalid course module.', 'resultid' => null];
        }

        $context = context_module::instance($cmid);

        $data = new \stdClass();
        $data->bookingid = (int)$cm->instance;
        $data->cmid = $cmid;
        $data->importing = true;
        $bookusersforoption = [];
        $bookusersmeta = [
            'completed' => false,
            'updateexisting' => false,
            'timebooked' => null,
        ];

        if (
            $taskname === update_option_task::TASK_NAME
            && empty($input['optionid'])
            && !empty($input['optionquery'])
            && booking_task_support::is_last_preview_selection_reference((string)$input['optionquery'])
        ) {
            $previewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
            if (empty($previewids)) {
                return [
                    'status' => 'error',
                    'detail' => 'No recently previewed booking options are available for this follow-up request.',
                    'resultid' => null,
                ];
            }

            if (count($previewids) === 1) {
                $input['optionid'] = (int)reset($previewids);
            } else {
                $taskname = bulk_update_options_task::TASK_NAME;
                $input['optionids'] = array_values(array_map('intval', $previewids));
            }
        }

        $resolvedoptiontype = $this->resolve_option_type_from_input($input);
        if ($resolvedoptiontype !== null) {
            $data->optiontype = $resolvedoptiontype;
            $data->selflearningcourse = $resolvedoptiontype === MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE ? 1 : 0;
            $data->slot_enabled = $resolvedoptiontype === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING ? 1 : 0;
        }

        foreach (['text', 'location', 'address', 'description'] as $field) {
            if (isset($input[$field])) {
                $data->$field = clean_param($input[$field], PARAM_TEXT);
            }
        }

        foreach (['maxanswers', 'maxoverbooking'] as $field) {
            if (isset($input[$field])) {
                $data->$field = (int)$input[$field];
            }
        }

        if (array_key_exists('selflearningcourse', $input)) {
            $data->selflearningcourse = !empty($input['selflearningcourse']) ? 1 : 0;
        }

        foreach (['slot_opening_time', 'slot_closing_time'] as $slottimefield) {
            if (isset($input[$slottimefield])) {
                $data->{$slottimefield} = trim((string)$input[$slottimefield]);
            }
        }

        if (isset($input['slot_type'])) {
            $slottype = trim((string)$input['slot_type']);
            if (in_array($slottype, ['fixed', 'rolling', 'session', 'userdefined'], true)) {
                $data->slot_type = $slottype;
            }
        }

        if (isset($input['slot_booking_view_mode'])) {
            $viewmode = trim((string)$input['slot_booking_view_mode']);
            $data->slot_booking_view_mode = $viewmode === 'list' ? 'list' : 'calendar';
        }

        $slotintfields = [
            'slot_duration_minutes',
            'slot_interval_minutes',
            'slot_max_participants_per_slot',
            'slot_max_slots_per_user',
        ];
        foreach ($slotintfields as $slotintfield) {
            if (isset($input[$slotintfield])) {
                $data->{$slotintfield} = (int)$input[$slotintfield];
            }
        }

        foreach (['slot_custom_max_duration', 'slot_custom_min_duration', 'slot_custom_max_days'] as $customslotfield) {
            if (isset($input[$customslotfield])) {
                $data->{$customslotfield} = (int)$input[$customslotfield];
            }
        }

        if (isset($input['slot_custom_start_interval_minutes'])) {
            $data->slot_custom_start_interval_minutes = (int)$input['slot_custom_start_interval_minutes'];
        }

        foreach (['slot_valid_from', 'slot_valid_until'] as $slotdatefield) {
            if (isset($input[$slotdatefield])) {
                $parsed = booking_task_support::parse_datetime($input[$slotdatefield]);
                if ($parsed !== false) {
                    $data->{$slotdatefield} = (int)$parsed;
                }
            }
        }

        // When slot_enabled, explicitly initialise ALL 7 day flags to 0 so no day
        // is accidentally active from a previous save or missing input key.
        if (!empty($input['slot_enabled'])) {
            for ($day = 1; $day <= 7; $day++) {
                $data->{'slot_day_' . $day} = 0;
            }
        }
        for ($day = 1; $day <= 7; $day++) {
            $key = 'slot_day_' . $day;
            if (array_key_exists($key, $input)) {
                $data->{$key} = !empty($input[$key]) ? 1 : 0;
            }
        }

        if (array_key_exists('slot_add_examiners', $input)) {
            $data->slot_add_examiners = !empty($input['slot_add_examiners']) ? 1 : 0;
        }

        if (isset($input['slot_teacher_pool']) && is_array($input['slot_teacher_pool'])) {
            $data->slot_teacher_pool = array_values(array_map('intval', $input['slot_teacher_pool']));
        }

        if (isset($input['slot_teachers_required'])) {
            $data->slot_teachers_required = max(0, (int)$input['slot_teachers_required']);
        }

        if (isset($input['duration'])) {
            $data->duration = (int)$input['duration'];
        }
        if (array_key_exists('disablecancel', $input)) {
            $data->disablecancel = !empty($input['disablecancel']) ? 1 : 0;
        }

        $normalizedvisibility = booking_task_support::normalize_visibility_input($input);
        if ($taskname === create_option_task::TASK_NAME) {
            // Create flow always starts hidden. Visibility changes must happen via update flow.
            $data->invisible = MOD_BOOKING_OPTION_INVISIBLE;
        } else if (isset($normalizedvisibility['value'])) {
            $data->invisible = (int)$normalizedvisibility['value'];
        }

        if (isset($input['bookingopeningtime'])) {
            $opening = booking_task_support::parse_datetime($input['bookingopeningtime']);
            if ($opening !== false) {
                $data->bookingopeningtime = $opening;
            }
        }
        if (isset($input['bookingclosingtime'])) {
            $closing = booking_task_support::parse_datetime($input['bookingclosingtime']);
            if ($closing !== false) {
                $data->bookingclosingtime = $closing;
            }
        }

        $parsedoptiondates = booking_task_support::extract_optiondates($input);

        $normalizedprices = booking_task_support::normalize_prices_input_for_execute($input['prices'] ?? null);
        if (!empty($normalizedprices)) {
            $data->useprice = 1;
            foreach ($normalizedprices as $identifier => $value) {
                $data->{$identifier} = $value;
            }
        }

        if (!empty($input['teacheremail'])) {
            $data->teacheremail = trim((string)$input['teacheremail']);
        } else if (!empty($input['teacherquery'])) {
            $teacherquery = trim((string)$input['teacherquery']);

            if ($this->is_self_reference_query($teacherquery) && !empty($USER->email)) {
                $data->teacheremail = (string)$USER->email;
            } else {
                $teacherresult = booking_task_support::resolve_single_user($teacherquery);
                if ($teacherresult['status'] !== 'ok') {
                    // Teacher is optional for slotbooking: ignore unresolved noisy queries.
                    if ($resolvedoptiontype === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
                        $teacherresult = null;
                    } else {
                        return ['status' => 'error', 'detail' => (string)$teacherresult['message'], 'resultid' => null];
                    }
                }
                if ($teacherresult !== null) {
                    if (empty($teacherresult['email'])) {
                        return [
                            'status' => 'error',
                            'detail' => 'Resolved teacher has no e-mail address. Please provide teacheremail directly.',
                            'resultid' => null,
                        ];
                    }
                    $data->teacheremail = (string)$teacherresult['email'];
                }
            }
        }

        if (!empty($input['coursequery'])) {
            $courseresult = booking_task_support::resolve_single_course((string)$input['coursequery']);
            if ($courseresult['status'] !== 'ok') {
                return ['status' => 'error', 'detail' => (string)$courseresult['message'], 'resultid' => null];
            }
            $data->courseid = (int)$courseresult['courseid'];
            $data->enroltocourseshortname = (string)$courseresult['shortname'];
            $data->chooseorcreatecourse = 1;
        }

        if (array_key_exists('enrolledincourseenabled', $input) && empty($input['enrolledincourseenabled'])) {
            $data->bo_cond_enrolledincourse_restrict = 0;
        }
        if (!empty($input['enrolledincoursequery'])) {
            $restrictioncourses = booking_task_support::resolve_courses_for_restriction((string)$input['enrolledincoursequery']);
            if (!empty($restrictioncourses['errors'])) {
                return ['status' => 'error', 'detail' => implode(' ', $restrictioncourses['errors']), 'resultid' => null];
            }
            if (!empty($restrictioncourses['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => implode(' ', $restrictioncourses['ambiguities']),
                    'resultid' => null,
                ];
            }
            if (empty($restrictioncourses['courseids'])) {
                return ['status' => 'error', 'detail' => 'No valid course found for enrolledincoursequery.', 'resultid' => null];
            }

            $data->bo_cond_enrolledincourse_restrict = 1;
            $data->bo_cond_enrolledincourse_courseids = $restrictioncourses['courseids'];
            $operator = strtoupper(trim((string)($input['enrolledincourseoperator'] ?? 'OR')));
            $data->bo_cond_enrolledincourse_courseids_operator = in_array($operator, ['OR', 'AND'], true) ? $operator : 'OR';
        }
        if (array_key_exists('enrolledincoursesqlfilter', $input)) {
            $data->bo_cond_enrolledincourse_sqlfiltercheck = !empty($input['enrolledincoursesqlfilter']) ? 1 : 0;
        }
        if (!empty($input['enrolledincourseoverride'])) {
            $data->bo_cond_enrolledincourse_overrideconditioncheckbox = 1;
            if (isset($input['enrolledincourseoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['enrolledincourseoverrideoperator']));
                $data->bo_cond_enrolledincourse_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            $overrideids = $input['enrolledincourseoverrideconditionids'] ?? [];
            if (!empty($overrideids) && is_array($overrideids)) {
                $data->bo_cond_enrolledincourse_overridecondition = array_map('intval', $overrideids);
            }
        } else if (array_key_exists('enrolledincourseoverride', $input) && empty($input['enrolledincourseoverride'])) {
            $data->bo_cond_enrolledincourse_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('enrolledincohortenabled', $input) && empty($input['enrolledincohortenabled'])) {
            $data->bo_cond_enrolledincohorts_restrict = 0;
        }
        if (!empty($input['enrolledincohortquery'])) {
            $cohorts = booking_task_support::resolve_cohorts_for_restriction((string)$input['enrolledincohortquery']);
            if (!empty($cohorts['errors']) || !empty($cohorts['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($cohorts['errors'], $cohorts['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_enrolledincohorts_restrict = 1;
            $data->bo_cond_enrolledincohorts_cohortids = $cohorts['cohortids'];
            $operator = strtoupper(trim((string)($input['enrolledincohortoperator'] ?? 'OR')));
            $data->bo_cond_enrolledincohorts_cohortids_operator = in_array($operator, ['OR', 'AND'], true) ? $operator : 'OR';
        }
        if (array_key_exists('enrolledincohort_sqlfilter', $input)) {
            $data->bo_cond_enrolledincohorts_sqlfiltercheck = !empty($input['enrolledincohort_sqlfilter']) ? 1 : 0;
        }
        if (!empty($input['enrolledincohortoverride'])) {
            $data->bo_cond_enrolledincohorts_overrideconditioncheckbox = 1;
            if (isset($input['enrolledincohortoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['enrolledincohortoverrideoperator']));
                $data->bo_cond_enrolledincohorts_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            $overrideids = $input['enrolledincohortoverrideconditionids'] ?? [];
            if (!empty($overrideids) && is_array($overrideids)) {
                $data->bo_cond_enrolledincohorts_overridecondition = array_map('intval', $overrideids);
            }
        } else if (array_key_exists('enrolledincohortoverride', $input) && empty($input['enrolledincohortoverride'])) {
            $data->bo_cond_enrolledincohorts_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('hascompetencyenabled', $input) && empty($input['hascompetencyenabled'])) {
            $data->bo_cond_hascompetency_restrict = 0;
        }
        if (!empty($input['hascompetencyquery'])) {
            $competencies = booking_task_support::resolve_competencies_for_restriction((string)$input['hascompetencyquery']);
            if (!empty($competencies['errors']) || !empty($competencies['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($competencies['errors'], $competencies['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_hascompetency_restrict = 1;
            $data->bo_cond_hascompetency_competencyids = $competencies['competencyids'];
            $operator = strtoupper(trim((string)($input['hascompetencyoperator'] ?? 'AND')));
            $data->bo_cond_hascompetency_competencyids_operator = in_array($operator, ['OR', 'AND'], true) ? $operator : 'AND';
        }
        if (!empty($input['hascompetencyoverride'])) {
            $data->bo_cond_hascompetency_overrideconditioncheckbox = 1;
            if (isset($input['hascompetencyoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['hascompetencyoverrideoperator']));
                $data->bo_cond_hascompetency_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (!empty($input['hascompetencyoverrideconditionids']) && is_array($input['hascompetencyoverrideconditionids'])) {
                $data->bo_cond_hascompetency_overridecondition = array_map('intval', $input['hascompetencyoverrideconditionids']);
            }
        } else if (array_key_exists('hascompetencyoverride', $input) && empty($input['hascompetencyoverride'])) {
            $data->bo_cond_hascompetency_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('previouslybookedenabled', $input) && empty($input['previouslybookedenabled'])) {
            $data->bo_cond_previouslybooked_restrict = 0;
        }
        if (!empty($input['previouslybookedquery'])) {
            $prev = booking_task_support::resolve_single_option($cmid, (string)$input['previouslybookedquery']);
            if (($prev['status'] ?? '') !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)($prev['message'] ?? 'Could not resolve previouslybookedquery.'),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_previouslybooked_restrict = 1;
            $data->bo_cond_previouslybooked_optionid = (int)$prev['optionid'];
            if (!empty($input['previouslybookedrequirecompletion'])) {
                $data->bo_cond_previouslybooked_requirecompletion = 1;
            }
        }

        if (array_key_exists('selectusersenabled', $input) && empty($input['selectusersenabled'])) {
            $data->bo_cond_selectusers_restrict = 0;
        }
        if (!empty($input['selectusersquery'])) {
            $users = booking_task_support::resolve_users_for_restriction((string)$input['selectusersquery']);
            if (!empty($users['errors']) || !empty($users['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($users['errors'], $users['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $data->bo_cond_selectusers_restrict = 1;
            $data->bo_cond_selectusers_userids = $users['userids'];
        }

        if (!empty($input['bookusersquery'])) {
            $usersforbooking = booking_task_support::resolve_users_for_booking((string)$input['bookusersquery']);
            if (!empty($usersforbooking['errors']) || !empty($usersforbooking['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge($usersforbooking['errors'], $usersforbooking['ambiguities']))),
                    'resultid' => null,
                ];
            }
            $bookusersforoption = $usersforbooking['userids'];
            $bookusersmeta['updateexisting'] = !empty($input['bookusersupdateexisting']);
            $bookusersmeta['completed'] = !empty($input['bookuserscompleted']);
            if (isset($input['bookuserstimebooked'])) {
                $timebooked = booking_task_support::parse_datetime($input['bookuserstimebooked']);
                if ($timebooked !== false) {
                    $bookusersmeta['timebooked'] = $timebooked;
                }
            }
        }
        if (!empty($input['selectusersoverride'])) {
            $data->bo_cond_selectusers_overrideconditioncheckbox = 1;
            if (isset($input['selectusersoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['selectusersoverrideoperator']));
                $data->bo_cond_selectusers_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (!empty($input['selectusersoverrideconditionids']) && is_array($input['selectusersoverrideconditionids'])) {
                $data->bo_cond_selectusers_overridecondition = array_map('intval', $input['selectusersoverrideconditionids']);
            }
        } else if (array_key_exists('selectusersoverride', $input) && empty($input['selectusersoverride'])) {
            $data->bo_cond_selectusers_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('nooverlappingenabled', $input) && empty($input['nooverlappingenabled'])) {
            $data->bo_cond_nooverlapping_restrict = 0;
        }
        if (!empty($input['nooverlappingmode'])) {
            $mode = strtolower(trim((string)$input['nooverlappingmode']));
            $data->bo_cond_nooverlapping_restrict = 1;
            $data->bo_cond_nooverlapping_handling =
                ($mode === 'warn')
                    ? MOD_BOOKING_COND_OVERLAPPING_HANDLING_WARN
                    : MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK;
        }

        if (array_key_exists('allowedtobookininstance', $input)) {
            $restrict = !empty($input['allowedtobookininstance']) ? 1 : 0;
            $data->bo_cond_allowedtobookininstance_restrict = $restrict;
            if ($restrict === 1) {
                $data->bo_cond_allowedtobookininstance_capabilitynotneeded =
                    array_key_exists('allowedtobookininstancecapabilitynotneeded', $input)
                        ? (!empty($input['allowedtobookininstancecapabilitynotneeded']) ? 1 : 0)
                        : 1;
            }
        }

        if (array_key_exists('userprofilestandardenabled', $input) && empty($input['userprofilestandardenabled'])) {
            $data->bo_cond_userprofilefield_1_default_restrict = 0;
        }
        if (!empty($input['userprofilestandardfield']) && !empty($input['userprofilestandardoperator'])) {
            $data->bo_cond_userprofilefield_1_default_restrict = 1;
            $data->bo_cond_userprofilefield_field = trim((string)$input['userprofilestandardfield']);
            $data->bo_cond_userprofilefield_operator = trim((string)$input['userprofilestandardoperator']);
            $data->bo_cond_userprofilefield_value = (string)($input['userprofilestandardvalue'] ?? '');
        }
        if (!empty($input['userprofilestandardoverride'])) {
            $data->bo_cond_userprofilefield_overrideconditioncheckbox = 1;
            if (isset($input['userprofilestandardoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['userprofilestandardoverrideoperator']));
                $data->bo_cond_userprofilefield_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (
                !empty($input['userprofilestandardoverrideconditionids'])
                && is_array($input['userprofilestandardoverrideconditionids'])
            ) {
                $data->bo_cond_userprofilefield_overridecondition =
                    array_map('intval', $input['userprofilestandardoverrideconditionids']);
            }
        } else if (array_key_exists('userprofilestandardoverride', $input) && empty($input['userprofilestandardoverride'])) {
            $data->bo_cond_userprofilefield_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('userprofilecustomenabled', $input) && empty($input['userprofilecustomenabled'])) {
            $data->bo_cond_userprofilefield_2_custom_restrict = 0;
        }
        if (!empty($input['userprofilecustomfield']) && !empty($input['userprofilecustomoperator'])) {
            $data->bo_cond_userprofilefield_2_custom_restrict = 1;
            $data->bo_cond_customuserprofilefield_field = trim((string)$input['userprofilecustomfield']);
            $data->bo_cond_customuserprofilefield_operator = trim((string)$input['userprofilecustomoperator']);
            $data->bo_cond_customuserprofilefield_value = (string)($input['userprofilecustomvalue'] ?? '');
        }
        if (array_key_exists('userprofilecustomconnectsecondfield', $input)) {
            $connectsecond = !empty($input['userprofilecustomconnectsecondfield']) ? 1 : 0;
            $data->bo_cond_customuserprofilefield_connectsecondfield = $connectsecond;
        }
        if (isset($input['userprofilecustomfield2'])) {
            $data->bo_cond_customuserprofilefield_field2 = trim((string)$input['userprofilecustomfield2']);
        }
        if (isset($input['userprofilecustomoperator2'])) {
            $data->bo_cond_customuserprofilefield_operator2 = trim((string)$input['userprofilecustomoperator2']);
        }
        if (isset($input['userprofilecustomvalue2'])) {
            $data->bo_cond_customuserprofilefield_value2 = (string)$input['userprofilecustomvalue2'];
        }
        if (array_key_exists('userprofilecustomsqlfilter', $input)) {
            $data->bo_cond_customuserprofilefield_sqlfiltercheck = !empty($input['userprofilecustomsqlfilter']) ? 1 : 0;
        }
        if (!empty($input['userprofilecustomoverride'])) {
            $data->bo_cond_customuserprofilefield_overrideconditioncheckbox = 1;
            if (isset($input['userprofilecustomoverrideoperator'])) {
                $op = strtoupper(trim((string)$input['userprofilecustomoverrideoperator']));
                $data->bo_cond_customuserprofilefield_overrideoperator = in_array($op, ['OR', 'AND'], true) ? $op : 'OR';
            }
            if (
                !empty($input['userprofilecustomoverrideconditionids'])
                && is_array($input['userprofilecustomoverrideconditionids'])
            ) {
                $data->bo_cond_customuserprofilefield_overridecondition =
                    array_map('intval', $input['userprofilecustomoverrideconditionids']);
            }
        } else if (array_key_exists('userprofilecustomoverride', $input) && empty($input['userprofilecustomoverride'])) {
            $data->bo_cond_customuserprofilefield_overrideconditioncheckbox = 0;
        }

        if (array_key_exists('customformenabled', $input) && empty($input['customformenabled'])) {
            $data->bo_cond_customform_restrict = 0;
        }

        if (array_key_exists('customformelements', $input) && is_array($input['customformelements'])) {
            $elements = booking_task_support::normalize_customform_elements_for_execute($input['customformelements']);
            if (!empty($elements)) {
                $data->bo_cond_customform_restrict = 1;
                $index = 1;
                foreach ($elements as $element) {
                    $data->{'bo_cond_customform_select_1_' . $index} = (string)$element['formtype'];
                    $data->{'bo_cond_customform_label_1_' . $index} = (string)($element['label'] ?? '');
                    $data->{'bo_cond_customform_value_1_' . $index} = (string)($element['value'] ?? '');
                    $data->{'bo_cond_customform_notempty_1_' . $index} = !empty($element['required']) ? 1 : 0;
                    $data->{'bo_cond_customform_enroluserstowaitinglist' . $index} =
                        !empty($element['enroluserstowaitinglist']) ? 1 : 0;
                    $index++;
                }
                if (array_key_exists('customformdeleteinfoscheckboxadmin', $input)) {
                    $data->bo_cond_customform_deleteinfoscheckboxadmin =
                        !empty($input['customformdeleteinfoscheckboxadmin']) ? 1 : 0;
                }
            } else if (array_key_exists('customformenabled', $input) && !empty($input['customformenabled'])) {
                $data->bo_cond_customform_restrict = 0;
            }
        }

        if (!empty($input['customformjson']) && is_array($input['customformjson'])) {
            $customformjson = $input['customformjson'];
            if (!empty($customformjson['formsarray']) && is_array($customformjson['formsarray'])) {
                $data->bo_cond_customform_restrict = 1;
                foreach ($customformjson['formsarray'] as $formcounter => $formrows) {
                    if (!is_array($formrows)) {
                        continue;
                    }
                    foreach ($formrows as $counter => $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $fc = (int)$formcounter;
                        $ix = (int)$counter;
                        $data->{'bo_cond_customform_select_' . $fc . '_' . $ix} = (string)($row['formtype'] ?? '0');
                        $data->{'bo_cond_customform_label_' . $fc . '_' . $ix} = (string)($row['label'] ?? '');
                        $data->{'bo_cond_customform_value_' . $fc . '_' . $ix} = (string)($row['value'] ?? '');
                        $data->{'bo_cond_customform_notempty_' . $fc . '_' . $ix} = !empty($row['notempty']) ? 1 : 0;
                        $data->{'bo_cond_customform_enroluserstowaitinglist' . $ix} =
                            !empty($row['enroluserstowaitinglist']) ? 1 : 0;
                    }
                }
                $data->bo_cond_customform_deleteinfoscheckboxadmin = !empty($customformjson['deleteinfoscheckboxadmin']) ? 1 : 0;
            }
        }

        if ($taskname === create_option_task::TASK_NAME) {
            $data->id = 0;
            if (empty($data->text)) {
                return ['status' => 'error', 'detail' => 'Option title is required.', 'resultid' => null];
            }
        } else if ($taskname === update_option_task::TASK_NAME) {
            if (!empty($input['optionid'])) {
                $data->id = (int)$input['optionid'];
            } else if (booking_task_support::is_last_option_reference((string)($input['optionquery'] ?? ''))) {
                $lastoptionid = booking_task_support::resolve_last_option_for_user_for_execute($cmid, $userid);
                if (!$lastoptionid) {
                    return [
                        'status' => 'error',
                        'detail' => 'No previously worked-on option found in this booking context. '
                            . 'Please provide optionid or a specific optionquery.',
                        'resultid' => null,
                    ];
                }
                $data->id = $lastoptionid;
            } else {
                $result = booking_task_support::resolve_single_option(
                    $cmid,
                    (string)($input['optionquery'] ?? ''),
                    (string)($input['optionwhen'] ?? '')
                );
                if ($result['status'] !== 'ok') {
                    return ['status' => 'error', 'detail' => (string)$result['message'], 'resultid' => null];
                }
                $data->id = (int)$result['optionid'];
            }

            if (!empty($parsedoptiondates)) {
                $datesmode = strtolower(trim((string)($input['optiondatesmode'] ?? 'append')));
                if ($datesmode === 'append') {
                    $parsedoptiondates = booking_task_support::merge_existing_optiondates_with_new_for_execute(
                        (int)$data->id,
                        $parsedoptiondates
                    );
                }
            }
        } else if ($taskname === bulk_update_options_task::TASK_NAME) {
            $optionids = booking_task_support::resolve_bulk_option_ids_for_execute($cmid, $input, $userid);
            if (empty($optionids)) {
                return ['status' => 'error', 'detail' => 'No matching booking options found to update.', 'resultid' => null];
            }

            $updated = [];
            $failed = [];
            foreach ($optionids as $optionid) {
                $itemdata = clone $data;
                $itemdata->id = (int)$optionid;
                try {
                    booking_option::update($itemdata, $context);
                    $updated[] = (int)$optionid;
                    booking_task_support::remember_last_option_for_user_for_execute(
                        $userid,
                        $cmid,
                        (int)$optionid,
                        (int)$cm->instance
                    );
                } catch (\Throwable $e) {
                    $failed[] = (int)$optionid . ' (' . $e->getMessage() . ')';
                }
            }

            if (!empty($failed)) {
                return [
                    'status' => 'error',
                    'detail' => 'Updated: ' . implode(', ', $updated) . '. Failed: ' . implode('; ', $failed),
                    'resultid' => !empty($updated) ? $updated[0] : null,
                ];
            }

            return [
                'status' => 'executed',
                'detail' => 'Updated ' . count($updated) . ' booking option(s): ' . implode(', ', $updated) . '.',
                'resultid' => !empty($updated) ? $updated[0] : null,
                'previewoptionids' => $updated,
            ];
        }

        if (!empty($parsedoptiondates)) {
            booking_task_support::apply_optiondates_to_update_data_for_execute($data, $parsedoptiondates);
        }

        try {
            $newoptionid = booking_option::update($data, $context);

            if ($taskname === create_option_task::TASK_NAME || $taskname === update_option_task::TASK_NAME) {
                booking_task_support::remember_last_option_for_user_for_execute(
                    $userid,
                    $cmid,
                    (int)$newoptionid,
                    (int)$cm->instance
                );
            }

            $detail = 'Booking option ' . ($taskname === create_option_task::TASK_NAME ? 'created' : 'updated')
                . ' (id=' . (int)$newoptionid . ', link='
                . booking_task_support::build_option_link_for_output($cmid, (int)$newoptionid)
                . ').';

            $verificationwarnings = booking_task_support::verify_persisted_option_state_for_task_for_execute(
                $taskname,
                $input,
                (int)$newoptionid
            );
            if (!empty($verificationwarnings)) {
                $detail .= ' Verification warnings: ' . implode(' ', $verificationwarnings);
            }

            if (!empty($bookusersforoption)) {
                $bookusersresult = booking_task_support::book_users_via_bookit_for_execute(
                    (int)$newoptionid,
                    $bookusersforoption,
                    $bookusersmeta
                );
                if (!empty($bookusersresult['errors'])) {
                    return [
                        'status' => 'error',
                        'detail' => $detail . ' User booking failed: ' . implode(' ', $bookusersresult['errors']),
                        'resultid' => (int)$newoptionid,
                    ];
                }

                if (!empty($bookusersresult['bookeduserids'])) {
                    $detail .= ' Booked users: ' . implode(', ', $bookusersresult['bookeduserids']) . '.';
                }
            }

            return [
                'status' => 'executed',
                'detail' => $detail,
                'resultid' => (int)$newoptionid,
                'warnings' => $verificationwarnings,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null];
        }
    }

    /**
     * Resolve option type from AI command input.
     *
     * @param array $input
     * @return int|null
     */
    private function resolve_option_type_from_input(array $input): ?int {
        if (!empty($input['slot_enabled'])) {
            return MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;
        }

        if (array_key_exists('optiontype', $input)) {
            $raw = strtolower(trim((string)$input['optiontype']));
            if (in_array($raw, ['0', 'normal', 'withdates', 'with_dates', 'default'], true)) {
                return MOD_BOOKING_OPTIONTYPE_DEFAULT;
            }
            if (in_array($raw, ['1', 'selflearning', 'self-learning', 'selflearningcourse'], true)) {
                return MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE;
            }
            if (in_array($raw, ['2', 'slot', 'slotbooking', 'slot-booking'], true)) {
                return MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;
            }
        }

        if (!empty($input['selflearningcourse'])) {
            return MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE;
        }

        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                return MOD_BOOKING_OPTIONTYPE_SLOTBOOKING;
            }
        }

        return null;
    }

    /**
     * Shared preflight validation for mutating tasks.
     *
     * This method is intentionally side-effect free.  The input is expected to
     * have already been deanonymized by the caller (agent_decision_service or the
     * task's own preflight() method).
     *
     * @param string $taskname
     * @param array $input  Already-deanonymized input.
     * @param int $cmid
     * @param int $userid
     * @return array{errors:array<int,string>,ambiguities:array<int,string>,normalized_input:array<string,mixed>}
     */
    public function preflight_validate(string $taskname, array $input, int $cmid, int $userid): array {
        global $USER;

        $errors = [];
        $ambiguities = [];
        $normalizedinput = $input;

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            $errors[] = 'Invalid course module.';
            return [
                'errors' => $errors,
                'ambiguities' => $ambiguities,
                'normalized_input' => $normalizedinput,
            ];
        }

        $common = booking_mutation_validation::validate_common($normalizedinput, $cmid, $taskname);
        $errors = array_merge($errors, $common['errors']);
        $ambiguities = array_merge($ambiguities, $common['ambiguities']);

        $resolvedoptiontype = $this->resolve_option_type_from_input($normalizedinput);
        if (
            !isset($normalizedinput['courseendtime'])
            && isset($normalizedinput['coursestarttime'])
            && isset($normalizedinput['duration'])
            && (int)$normalizedinput['duration'] > 0
        ) {
            $startts = booking_task_support::parse_datetime($normalizedinput['coursestarttime']);
            if ($startts !== false) {
                $normalizedinput['courseendtime'] = (int)$startts + (int)$normalizedinput['duration'];
            }
        }

        if ($taskname === create_option_task::TASK_NAME) {
            $isnormal = $resolvedoptiontype === null || $resolvedoptiontype === MOD_BOOKING_OPTIONTYPE_DEFAULT;
            if ($isnormal) {
                $parsedoptiondates = booking_task_support::extract_optiondates($normalizedinput);
                if (empty($parsedoptiondates)) {
                    $errors[] = 'Field "optiondates" must contain at least one valid date range.';
                }
            }
        }

        if ($taskname === update_option_task::TASK_NAME && empty($normalizedinput['optionid'])) {
            $optionquery = (string)($normalizedinput['optionquery'] ?? '');
            if ($optionquery !== '') {
                if (booking_task_support::is_last_preview_selection_reference($optionquery)) {
                    $previewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
                    if (empty($previewids)) {
                        $errors[] = 'No recently previewed booking options are available for this follow-up request.';
                    } else if (count($previewids) === 1) {
                        $normalizedinput['optionid'] = (int)reset($previewids);
                    }
                } else if (!booking_task_support::is_last_option_reference($optionquery)) {
                    $result = booking_task_support::resolve_single_option(
                        $cmid,
                        $optionquery,
                        (string)($normalizedinput['optionwhen'] ?? '')
                    );
                    if (($result['status'] ?? '') === 'ambiguity') {
                        $ambiguities[] = (string)($result['message'] ?? '');
                    } else if (($result['status'] ?? '') === 'error') {
                        $errors[] = (string)($result['message'] ?? 'Could not resolve the option to update.');
                    } else if (($result['status'] ?? '') === 'ok') {
                        $normalizedinput['optionid'] = (int)($result['optionid'] ?? 0);
                    }
                }
            }
        }

        if ($taskname === bulk_update_options_task::TASK_NAME) {
            $optionids = booking_task_support::resolve_bulk_option_ids_for_execute($cmid, $normalizedinput, $userid);
            if (empty($optionids)) {
                $errors[] = 'No matching booking options found to update.';
            }
        }

        if (empty($normalizedinput['teacheremail']) && !empty($normalizedinput['teacherquery'])) {
            $teacherquery = trim((string)$normalizedinput['teacherquery']);
            if ($this->is_self_reference_query($teacherquery) && !empty($USER->email)) {
                $normalizedinput['teacheremail'] = (string)$USER->email;
            }
        }

        return [
            'errors' => array_values(array_unique(array_filter($errors))),
            'ambiguities' => array_values(array_unique(array_filter($ambiguities))),
            'normalized_input' => $normalizedinput,
        ];
    }

    /**
     * Detect whether a teacher query refers to the current user.
     *
     * @param string $query
     * @return bool
     */
    private function is_self_reference_query(string $query): bool {
        $normalized = strtolower(trim($query, " \t\n\r\0\x0B.,;:!?\"'"));
        if ($normalized === '') {
            return false;
        }

        if ($normalized === '__current_user__') {
            return true;
        }

        $tokens = [
            'self',
            'me',
            'myself',
            'i',
            'ich',
            'mich',
            'mir',
            'mein',
            'current user',
            'aktueller benutzer',
            'der aktuelle benutzer',
        ];

        if (in_array($normalized, $tokens, true)) {
            return true;
        }

        return preg_match('/\b(ich|mich|mir|self|myself|me)\b/u', $normalized) === 1;
    }
}
