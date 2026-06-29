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

namespace mod_booking\local\wizard\booking;

use context_module;
use context_user;
use mod_booking\booking_option;
use mod_booking\local\wizard\booking\support\booking_mutation_validation;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\option_input_verification;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking\local\wizard\options\skills\update_option_trainer_skill;
use mod_booking\singleton_service;
use mod_booking\local\wizard\engine\attachment_resolver;

/**
 * Execute service for mutating booking AI tasks.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_skill_mutation_execute_service {
    /** @var attachment_resolver|null Engine attachment resolver (chat-upload token -> temp file). */
    private ?attachment_resolver $attachments;

    /**
     * Constructor.
     *
     * @param attachment_resolver|null $attachments injected by the skill via base_skill::attachments()
     */
    public function __construct(?attachment_resolver $attachments = null) {
        $this->attachments = $attachments;
    }

    /**
     * Execute supported mutating tasks.
     *
     * @param string $taskname
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param booking_skill_support $support
     * @return array<string,mixed>|null Null when task is not handled here.
     */
    public function execute(string $taskname, array $input, int $cmid, int $userid, booking_skill_support $support): ?array {
        global $CFG, $USER;

        if (
            !in_array(
                $taskname,
                [
                    create_option_skill::TASK_NAME,
                    update_option_skill::TASK_NAME,
                    update_option_trainer_skill::TASK_NAME,
                    bulk_update_options_skill::TASK_NAME,
                ],
                true
            )
        ) {
            return null;
        }

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        // Defensive: accept a configure_booking_instance-style {changes:[{field,value}]} envelope by
        // flattening it onto the top level, since every mutation field below is read flat. Keeps
        // create/update/update_trainer/bulk consistent and prevents a silent no-op (thread-206).
        $input = $this->flatten_changes_envelope($input);

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
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_invalid_course_module', 'booking'),
                'resultid' => null,
            ];
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
            $this->is_update_option_style_task($taskname)
            && empty($input['optionid'])
            && !empty($input['optionquery'])
            && booking_skill_support::is_last_preview_selection_reference((string)$input['optionquery'])
        ) {
            $previewids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
            if (empty($previewids)) {
                return [
                    'status' => 'error',
                    'detail' => get_string('agent_booking_bulk_update_no_preview', 'booking'),
                    'resultid' => null,
                ];
            }

            if (count($previewids) === 1) {
                $input['optionid'] = (int)reset($previewids);
            } else {
                $taskname = bulk_update_options_skill::TASK_NAME;
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

        $this->apply_headerimage_token_to_data($input, $data, $userid, (int)$context->id);

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
                $parsed = booking_skill_support::parse_datetime($input[$slotdatefield]);
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

        $normalizedvisibility = booking_skill_support::normalize_visibility_input($input);
        if ($taskname === create_option_skill::TASK_NAME) {
            // Create flow always starts hidden. Visibility changes must happen via update flow.
            $data->invisible = MOD_BOOKING_OPTION_INVISIBLE;
        } else if (isset($normalizedvisibility['value'])) {
            $data->invisible = (int)$normalizedvisibility['value'];
        }

        if (isset($input['bookingopeningtime'])) {
            $opening = booking_skill_support::parse_datetime($input['bookingopeningtime']);
            if ($opening !== false) {
                $data->bookingopeningtime = $opening;
            }
        }
        if (isset($input['bookingclosingtime'])) {
            $closing = booking_skill_support::parse_datetime($input['bookingclosingtime']);
            if ($closing !== false) {
                $data->bookingclosingtime = $closing;
            }
        }

        $parsedoptiondates = booking_skill_support::extract_optiondates($input);

        $normalizedprices = booking_skill_support::normalize_prices_input_for_execute($input['prices'] ?? null);
        if (!empty($normalizedprices)) {
            $data->useprice = 1;
            foreach ($normalizedprices as $identifier => $value) {
                $data->{$identifier} = $value;
            }
        }

        if (!empty($input['teacheremail'])) {
            $data->teacheremail = trim((string)$input['teacheremail']);
        } else if (!empty($input['teacherids']) && is_array($input['teacherids'])) {
            $teacheremails = $this->resolve_teacher_emails_from_ids($input['teacherids']);
            if (empty($teacheremails)) {
                return [
                    'status' => 'error',
                    'detail' => 'No valid trainer records found for provided teacherids.',
                    'resultid' => null,
                ];
            }
            $data->teacheremail = implode(',', $teacheremails);
        } else if (!empty($input['teacherquery'])) {
            $teacherquery = trim((string)$input['teacherquery']);

            if ($this->is_self_reference_query($teacherquery) && !empty($USER->email)) {
                $data->teacheremail = (string)$USER->email;
            } else {
                $teacherresult = booking_skill_support::resolve_single_user($teacherquery);
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
                            'detail' => get_string('agent_booking_teacher_no_email', 'booking'),
                            'resultid' => null,
                        ];
                    }
                    $data->teacheremail = (string)$teacherresult['email'];
                }
            }
        }

        if (!empty($input['coursequery'])) {
            $courseresult = booking_skill_support::resolve_single_course((string)$input['coursequery']);
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
            $restrictioncourses = booking_skill_support::resolve_courses_for_restriction((string)$input['enrolledincoursequery']);
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
                return [
                    'status' => 'error',
                    'detail' => get_string('agent_booking_no_valid_course_enrolled', 'booking'),
                    'resultid' => null,
                ];
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
            $cohorts = booking_skill_support::resolve_cohorts_for_restriction((string)$input['enrolledincohortquery']);
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
            $competencies = booking_skill_support::resolve_competencies_for_restriction((string)$input['hascompetencyquery']);
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
            $prev = booking_skill_support::resolve_single_option($cmid, (string)$input['previouslybookedquery']);
            if (($prev['status'] ?? '') !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)($prev['message'] ?? get_string(
                        'agent_booking_previouslybookedquery_resolve_failed',
                        'booking'
                    )),
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
            $users = booking_skill_support::resolve_users_for_restriction((string)$input['selectusersquery']);
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
            $usersforbooking = booking_skill_support::resolve_users_for_booking((string)$input['bookusersquery']);
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
                $timebooked = booking_skill_support::parse_datetime($input['bookuserstimebooked']);
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
            $elements = booking_skill_support::normalize_customform_elements_for_execute($input['customformelements']);
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

        if ($taskname === create_option_skill::TASK_NAME) {
            $data->id = 0;
            if (empty($data->text)) {
                return ['status' => 'error', 'detail' => 'Option title is required.', 'resultid' => null];
            }
        } else if ($this->is_update_option_style_task($taskname)) {
            if (!empty($input['optionid'])) {
                $data->id = (int)$input['optionid'];
            } else if (booking_skill_support::is_last_option_reference((string)($input['optionquery'] ?? ''))) {
                $lastoptionid = booking_skill_support::resolve_last_option_for_user_for_execute($cmid, $userid);
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
                $result = booking_skill_support::resolve_single_option(
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
                    $parsedoptiondates = booking_skill_support::merge_existing_optiondates_with_new_for_execute(
                        (int)$data->id,
                        $parsedoptiondates
                    );
                }
            }
        } else if ($taskname === bulk_update_options_skill::TASK_NAME) {
            $optionids = booking_skill_support::resolve_bulk_option_ids_for_execute($cmid, $input, $userid);
            if (empty($optionids)) {
                // Search-correction recovery: instead of a bare error (which the planner kept
                // re-issuing in an infinite confirm/auto-confirm loop), classify it with an
                // issue_code AND hand the planner the actual candidates so it can correct the
                // query, pick a concrete optionid, or conclude the change was already applied.
                global $DB;
                $query = trim((string)($input['optionquery'] ?? ''));
                $rows = $DB->get_records(
                    'booking_options',
                    ['bookingid' => (int)$cm->instance],
                    'id ASC',
                    'id, text',
                    0,
                    15
                );
                $candidates = [];
                foreach ($rows as $row) {
                    $candidates[] = 'id=' . (int)$row->id . ' "' . trim((string)$row->text) . '"';
                }
                $candidatestext = empty($candidates)
                    ? 'This booking instance currently has no options.'
                    : 'Options currently in this instance: ' . implode('; ', $candidates) . '.';

                $detail = get_string('agent_booking_no_matching_options_to_update', 'booking');
                return [
                    'status' => 'error',
                    'detail' => $detail
                        . ($query !== '' ? ' (query: "' . $query . '")' : '') . ' ' . $candidatestext,
                    'resultid' => null,
                    'issue_codes' => ['OPTION_QUERY_NO_MATCH'],
                    'observation_full' => 'No booking options matched'
                        . ($query !== '' ? ' the query "' . $query . '"' : '') . '. ' . $candidatestext
                        . ' If the requested change was already applied earlier (e.g. a revert that already '
                        . 'succeeded), tell the user that nothing remains to do. Otherwise pick a concrete '
                        . 'optionid from the list above, or ask the user which option is meant. '
                        . 'Do NOT re-issue the same non-matching optionquery.',
                ];
            }

            $updated = [];
            $failed = [];
            // Compact per-option verification lines for the planner observation. Bulk deliberately
            // avoids a full get_option_details re-read per option (too much for the LLM to digest);
            // it only reports a per-option pass/fail of the requested changes. The per-option persist
            // and field-level verification themselves are the SAME as the single-option path
            // (persist_and_verify_single_option) — only this presentation is compact.
            $verifylines = [];
            foreach ($optionids as $optionid) {
                try {
                    $outcome = $this->persist_and_verify_single_option($taskname, $data, (int)$optionid, $input, $context);

                    if (!empty($outcome['warnings'])) {
                        $failed[] = $outcome['optionid'];
                        $verifylines[] = 'Option ' . $outcome['optionid'] . ' ('
                            . booking_skill_support::build_option_link_for_output($cmid, (int)$outcome['optionid'])
                            . '): NOT confirmed — ' . implode(' ', $outcome['warnings']);
                        continue;
                    }

                    $updated[] = $outcome['optionid'];
                    $verifylines[] = 'Option ' . $outcome['optionid'] . ' ('
                        . booking_skill_support::build_option_link_for_output($cmid, (int)$outcome['optionid'])
                        . '): confirmed.';
                    booking_skill_support::remember_last_option_for_user_for_execute(
                        $userid,
                        $cmid,
                        $outcome['optionid'],
                        (int)$cm->instance
                    );
                } catch (\Throwable $e) {
                    $failed[] = (int)$optionid;
                    $verifylines[] = 'Option ' . (int)$optionid . ' ('
                        . booking_skill_support::build_option_link_for_output($cmid, (int)$optionid)
                        . '): FAILED — ' . $e->getMessage();
                }
            }

            $verificationobservation = 'BULK VERIFICATION: ' . count($optionids) . ' option(s) processed. '
                . 'Per-option check of the requested changes (real persisted state):' . "\n- "
                . implode("\n- ", $verifylines)
                . "\nIf any option is NOT confirmed, retry that option or ask the user — "
                . 'do NOT report success for it based on the execution message alone.';

            // Entity mentions travel with real moodle_url links — "id (link)" per
            // option, so the synchronizer presents them clickable without inventing URLs.
            $linklist = static function (array $optionidlist) use ($cmid): string {
                return implode(', ', array_map(
                    static fn(int $id): string => $id . ' ('
                        . booking_skill_support::build_option_link_for_output($cmid, $id) . ')',
                    array_map('intval', $optionidlist)
                ));
            };

            if (!empty($failed)) {
                return [
                    'status' => 'error',
                    'detail' => 'Updated: ' . $linklist($updated) . '. Not confirmed/failed: '
                        . $linklist($failed) . '.',
                    'resultid' => !empty($updated) ? $updated[0] : null,
                    'previewoptionids' => $updated,
                    'observation_full' => $verificationobservation,
                ];
            }

            return [
                'status' => 'executed',
                'detail' => 'Updated ' . count($updated) . ' booking option(s): ' . $linklist($updated) . '.',
                'resultid' => !empty($updated) ? $updated[0] : null,
                'previewoptionids' => $updated,
                'observation_full' => $verificationobservation,
            ];
        }

        if (!empty($parsedoptiondates)) {
            booking_skill_support::apply_optiondates_to_update_data_for_execute($data, $parsedoptiondates);
        }

        try {
            $outcome = $this->persist_and_verify_single_option($taskname, $data, (int)$data->id, $input, $context);
            $newoptionid = $outcome['optionid'];

            if ($taskname === create_option_skill::TASK_NAME || $this->is_update_option_style_task($taskname)) {
                booking_skill_support::remember_last_option_for_user_for_execute(
                    $userid,
                    $cmid,
                    (int)$newoptionid,
                    (int)$cm->instance
                );
            }

            $createdtitle = trim((string)($data->text ?? $input['text'] ?? ''));
            if ($taskname === create_option_skill::TASK_NAME && $createdtitle !== '') {
                $detail = 'Booking option created (title="' . $createdtitle . '", id=' . (int)$newoptionid . ', link='
                    . booking_skill_support::build_option_link_for_output($cmid, (int)$newoptionid)
                    . ').';
            } else {
                $detail = 'Booking option ' . ($taskname === create_option_skill::TASK_NAME ? 'created' : 'updated')
                    . ' (id=' . (int)$newoptionid . ', link='
                    . booking_skill_support::build_option_link_for_output($cmid, (int)$newoptionid)
                    . ').';
            }

            $verificationwarnings = $outcome['warnings'];
            if (!empty($verificationwarnings)) {
                $failedpostconditions = $this->map_postcondition_failures(
                    $verificationwarnings,
                    $input,
                    (int)$newoptionid
                );
                $failedcodes = array_values(array_filter(array_map(
                    static fn(array $failure): string => (string)($failure['code'] ?? ''),
                    $failedpostconditions
                )));
                $issuecodes = array_values(array_unique(array_merge(
                    ['POSTCONDITION_FAILED', $this->postcondition_family_issue_code($taskname)],
                    $failedcodes
                )));
                return [
                    'status' => 'error',
                    'detail' => $detail . ' Postcondition failed: ' . implode(' ', $verificationwarnings),
                    'resultid' => (int)$newoptionid,
                    'warnings' => $verificationwarnings,
                    'issue_codes' => $issuecodes,
                    'postcondition_status' => 'failed',
                    'failed_postconditions' => $failedpostconditions,
                    'postcondition_evidence' => [
                        'skill' => $taskname,
                        'optionid' => (int)$newoptionid,
                        'warnings_count' => count($verificationwarnings),
                        'verified_fields' => array_values(array_keys($input)),
                    ],
                ];
            }

            if (!empty($bookusersforoption)) {
                $bookusersresult = booking_skill_support::book_users_via_bookit_for_execute(
                    (int)$newoptionid,
                    $bookusersforoption,
                    $bookusersmeta
                );
                if (!empty($bookusersresult['errors'])) {
                    return [
                        'status' => 'error',
                        'detail' => $detail . ' ' . get_string(
                            'agent_booking_user_booking_failed',
                            'booking',
                            implode(' ', $bookusersresult['errors'])
                        ),
                        'resultid' => (int)$newoptionid,
                    ];
                }

                if (!empty($bookusersresult['bookeduserids'])) {
                    $detail .= ' ' . get_string(
                        'agent_booking_user_booking_booked_users',
                        'booking',
                        implode(', ', $bookusersresult['bookeduserids'])
                    );
                }
            }

            $result = [
                'status' => 'executed',
                'detail' => $detail,
                'resultid' => (int)$newoptionid,
                'warnings' => $verificationwarnings,
                'postcondition_status' => 'passed',
                'failed_postconditions' => [],
                'postcondition_evidence' => [
                    'skill' => $taskname,
                    'optionid' => (int)$newoptionid,
                    'warnings_count' => 0,
                    'verified_fields' => array_values(array_keys($input)),
                ],
            ];

            // Deterministic post-mutation verification: re-read the affected option and surface a
            // compact, requested-fields-only state summary as an authoritative observation for the
            // next planner turn. completed_observations outranks completed_commands, so this prevents
            // the agent from reporting success purely from "I issued the command" memory.
            $verification = $this->build_verification_observation_fields(
                $taskname,
                (int)$newoptionid,
                $input,
                $detail
            );
            if (!empty($verification)) {
                $result = array_merge($result, $verification);
            }

            return $result;
        } catch (\Throwable $e) {
            return ['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null];
        }
    }

    /**
     * Persist ONE option from the shared $data template and verify the requested fields.
     *
     * Single source of truth for "apply the prepared changes to one option and confirm they really
     * landed". Both the single-option path (create/update/update_trainer) and the bulk loop call it,
     * so the field-level verification (incl. the header image) is byte-for-byte identical; only the
     * surrounding presentation differs (rich single response vs. compact per-option bulk line).
     *
     * Verification is dispatched per skill via verify_persisted_option_state_for_skill_for_execute(),
     * which re-reads fresh settings and calls the skill's own verify_persisted_option_state() —
     * update_option and bulk_update_options both map that to option_input_verification.
     *
     * Lets booking_option::update() throw so callers decide how to report the failure.
     *
     * @param string $taskname
     * @param \stdClass $data Base data template built from the (flat) input.
     * @param int $optionid Target option id (0 for create).
     * @param array $input Requested input (defines which fields are verified).
     * @param context_module $context
     * @return array{optionid:int,warnings:array<int,string>}
     */
    private function persist_and_verify_single_option(
        string $taskname,
        \stdClass $data,
        int $optionid,
        array $input,
        context_module $context
    ): array {
        $itemdata = clone $data;
        $itemdata->id = $optionid;

        $newoptionid = (int)booking_option::update($itemdata, $context);

        $warnings = booking_skill_support::verify_persisted_option_state_for_skill_for_execute(
            $taskname,
            $input,
            $newoptionid
        );

        return [
            'optionid' => $newoptionid,
            'warnings' => array_values($warnings),
        ];
    }

    /**
     * Defensive input normalization: flatten a configure_booking_instance-style changes envelope
     * ({changes:[{field,value}, ...]}) onto the top level so the flat field mapping in execute()
     * sees it.
     *
     * The planner is taught to send flat fields for option mutations, but if it ever wraps them in a
     * changes envelope (as it did for bulk_update_options before the example was fixed — thread-206),
     * the fields would otherwise be silently ignored, because every mutation task here reads flat
     * top-level keys only. This single shared guard keeps create/update/update_trainer/bulk
     * consistent and turns a silent no-op into a real apply. Existing top-level keys are never
     * overwritten by a changes entry.
     *
     * @param array $input
     * @return array
     */
    private function flatten_changes_envelope(array $input): array {
        if (empty($input['changes']) || !is_array($input['changes'])) {
            return $input;
        }

        foreach ($input['changes'] as $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = trim((string)($change['field'] ?? ''));
            if ($field === '' || array_key_exists($field, $input)) {
                continue;
            }
            $input[$field] = $change['value'] ?? null;
        }

        unset($input['changes']);
        return $input;
    }

    /**
     * Build a compact, deterministic post-mutation verification observation.
     *
     * Re-reads fresh option settings and summarises ONLY the fields that were actually requested
     * (incl. the header image's present/absent state), then wraps them with an explicit instruction
     * telling the planner to confirm each requested change really exists before reporting success.
     *
     * Deliberately compact: it does NOT embed a full get_option_details payload (which would bloat
     * the planner context) and does NOT add an 'optiondetails' key (which would make the result be
     * reclassified as a read by result_payload_summarizer and drop the postcondition status).
     *
     * Best-effort: any failure returns [] so the successful mutation result is never broken.
     *
     * @param string $taskname
     * @param int $optionid
     * @param array $input The requested input (defines which fields to summarise).
     * @param string $executiondetail The execution detail line (kept first so existing observation
     *                                 consumers still see the "created/updated ... id=... link=..." text).
     * @return array<string,mixed>
     */
    private function build_verification_observation_fields(
        string $taskname,
        int $optionid,
        array $input,
        string $executiondetail = ''
    ): array {
        if ($optionid <= 0) {
            return [];
        }

        try {
            singleton_service::destroy_booking_option_singleton($optionid);
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            if (!$settings) {
                return [];
            }

            $lines = option_input_verification::summarize_requested_state($input, $settings);
            $action = $taskname === create_option_skill::TASK_NAME ? 'created' : 'updated';

            $directive = 'VERIFICATION REQUIRED: the booking option (id=' . $optionid . ') was reportedly '
                . $action . '. The freshly re-read state of the requested changes is shown below. '
                . 'Confirm every change the user requested is actually present before telling the user '
                . 'it is done. If a requested change is missing, retry the action once or ask the user — '
                . 'do NOT report success based on the execution message alone.';

            // Keep the execution detail first (existing observation consumers rely on it),
            // then the verification directive, then the compact requested-fields state.
            $parts = array_values(array_filter([
                trim($executiondetail),
                $directive,
                empty($lines) ? '' : "Requested changes (verified state):\n- " . implode("\n- ", $lines),
            ], static fn(string $part): bool => $part !== ''));

            return ['observation_full' => implode("\n\n", $parts)];
        } catch (\Throwable $e) {
            debugging(
                'mod_booking wizard: verification observation failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return [];
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
            $errors[] = get_string('agent_booking_invalid_course_module', 'booking');
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
            $startts = booking_skill_support::parse_datetime($normalizedinput['coursestarttime']);
            if ($startts !== false) {
                $normalizedinput['courseendtime'] = (int)$startts + (int)$normalizedinput['duration'];
            }
        }

        // For create_option, only title is required at preflight level.
        // Optional fields (teacher/location/schedule/capacity) can be omitted.

        if ($this->is_update_option_style_task($taskname) && empty($normalizedinput['optionid'])) {
            $optionquery = (string)($normalizedinput['optionquery'] ?? '');
            if ($optionquery !== '') {
                if (booking_skill_support::is_last_preview_selection_reference($optionquery)) {
                    $previewids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
                    if (empty($previewids)) {
                        $errors[] = get_string('agent_booking_bulk_update_no_preview', 'booking');
                    } else if (count($previewids) === 1) {
                        $normalizedinput['optionid'] = (int)reset($previewids);
                    }
                } else if (!booking_skill_support::is_last_option_reference($optionquery)) {
                    $result = booking_skill_support::resolve_single_option(
                        $cmid,
                        $optionquery,
                        (string)($normalizedinput['optionwhen'] ?? '')
                    );
                    if (($result['status'] ?? '') === 'ambiguity') {
                        $ambiguities[] = (string)($result['message'] ?? '');
                    } else if (($result['status'] ?? '') === 'error') {
                        $errors[] = (string)($result['message'] ?? get_string(
                            'agent_booking_update_option_missing_target',
                            'booking'
                        ));
                    } else if (($result['status'] ?? '') === 'ok') {
                        $normalizedinput['optionid'] = (int)($result['optionid'] ?? 0);
                    }
                }
            }
        }

        // NOTE: the bulk "no matching options" case is intentionally NOT a hard preflight error.
        // It is handled in execute() (bulk branch) with a search-correction payload (candidates +
        // issue_code OPTION_QUERY_NO_MATCH), so the planner/user can correct instead of looping.

        if (
            empty($normalizedinput['teacheremail'])
            && !empty($normalizedinput['teacherids'])
            && is_array($normalizedinput['teacherids'])
        ) {
            $teacheremails = $this->resolve_teacher_emails_from_ids($normalizedinput['teacherids']);
            if (!empty($teacheremails)) {
                $normalizedinput['teacheremail'] = implode(',', $teacheremails);
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

    /**
     * Resolve trainer e-mail addresses for provided user IDs.
     *
     * @param array $teacherids
     * @return array<int,string>
     */
    private function resolve_teacher_emails_from_ids(array $teacherids): array {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $teacherids), static fn(int $id): bool => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $users = $DB->get_records_list('user', 'id', $ids, '', 'id,email,deleted,suspended');
        if (empty($users)) {
            return [];
        }

        $emails = [];
        foreach ($ids as $id) {
            if (empty($users[$id])) {
                continue;
            }
            $user = $users[$id];
            if (!empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }
            $email = trim((string)($user->email ?? ''));
            if ($email === '') {
                continue;
            }
            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    /**
     * Whether task behaves like update_option regarding target resolution and persistence.
     *
     * @param string $taskname
     * @return bool
     */
    private function is_update_option_style_task(string $taskname): bool {
        return in_array($taskname, [
            update_option_skill::TASK_NAME,
            update_option_trainer_skill::TASK_NAME,
        ], true);
    }

    /**
     * Map warning strings to deterministic postcondition failure structures.
     *
     * @param array $warnings
     * @param array $input
     * @param int $optionid
     * @return array<int,array<string,mixed>>
     */
    private function map_postcondition_failures(array $warnings, array $input, int $optionid): array {
        $failures = [];

        // Use structured verifier for deterministic field-level codes where possible.
        if (!empty($input)) {
            try {
                singleton_service::destroy_booking_option_singleton($optionid);
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                $failures = option_input_verification::verify_common_fields_structured($input, $settings);
            } catch (\Throwable $e) {
                $failures = [];
            }
        }

        $existingmessages = array_fill_keys(array_map(
            static fn(array $failure): string => (string)($failure['message'] ?? ''),
            $failures
        ), true);

        // Keep non-common warnings as deterministic generic failures so nothing gets dropped.
        foreach ($warnings as $warning) {
            $message = trim((string)$warning);
            if ($message === '' || isset($existingmessages[$message])) {
                continue;
            }
            $failures[] = [
                'code' => 'POSTCOND_GENERIC_MISMATCH',
                'message' => $message,
                'evidence' => ['warning' => $message],
            ];
        }

        return array_values($failures);
    }

    /**
     * Deterministic family-level issue code for postcondition failures.
     *
     * @param string $taskname
     * @return string
     */
    private function postcondition_family_issue_code(string $taskname): string {
        if (
            in_array(
                $taskname,
                [
                    create_option_skill::TASK_NAME,
                    update_option_skill::TASK_NAME,
                    update_option_trainer_skill::TASK_NAME,
                    bulk_update_options_skill::TASK_NAME,
                ],
                true
            )
        ) {
            return 'POSTCONDITION_FAILED_OPTION_MUTATION';
        }

        return 'POSTCONDITION_FAILED_UNKNOWN_FAMILY';
    }

    /**
     * If input contains a headerimage_token, resolve it and prepare a Moodle user
     * draft area so that booking_option::update() → bookingoptionimage::save_data()
     * persists the image through the standard Moodle File API flow.
     *
     * Mirrors exactly what the option form does: the form element "bookingoptionimage"
     * carries a draft-area item ID; save_data() calls file_save_draft_area_files() on it.
     *
     * @param array     $input   Skill input parameters.
     * @param \stdClass $data    The $data object being built for booking_option::update().
     * @param int       $userid  Current user id (owner of the draft area).
     * @param int       $contextid  Module context id (not used here, kept for symmetry).
     */
    private function apply_headerimage_token_to_data(
        array $input,
        \stdClass &$data,
        int $userid,
        int $contextid
    ): void {
        global $USER;

        $token = trim((string)($input['headerimage_token'] ?? ''));
        if ($token === '') {
            return;
        }

        if ($this->attachments === null) {
            // No attachment resolver injected (skill did not wire base_skill::attachments()) -> no image.
            return;
        }

        try {
            // Token ownership is validated against the conversation user ($userid).
            $resolved = $this->attachments->resolve($token, $userid, $contextid);

            $tmppath  = (string)($resolved['path'] ?? '');
            $filename = (string)($resolved['filename'] ?? 'image');

            if ($tmppath === '' || !file_exists($tmppath)) {
                return;
            }

            // Stage the image in a user draft area, exactly as the file manager form element would.
            // The draft MUST belong to the executing $USER: bookingoptionimage::save_data() and
            // file_save_draft_area_files() both read the draft from context_user::instance($USER->id).
            // $userid (conversation owner) is only used for token ownership above; the executing
            // user may differ (e.g. an adhoc task), so we deliberately key the draft to $USER.
            $draftitemid  = file_get_unused_draft_itemid();
            $usercontext  = context_user::instance($USER->id);
            $fs           = get_file_storage();

            // A real filepicker upload always records a non-null source as a serialized stdClass
            // (see the {files}.source column of form-uploaded images). Two reasons this matters:
            // - booking_option_settings::load_imageurl_from_db() filters on "source IS NOT NULL", so a
            // NULL source (the default of create_file_from_pathname) makes the saved image invisible:
            // the file exists but imageurl is never derived.
            // - lib/filelib.php unserialize_object()s the source; a plain string triggers a PHP warning.
            $source = new \stdClass();
            $source->source = $filename;

            $fs->create_file_from_pathname([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $draftitemid,
                'filepath'  => '/',
                'filename'  => $filename,
                'source'    => serialize($source),
            ], $tmppath);

            // Booking_option::update() runs fields_info::set_data() (importing mode) ->
            // bookingoptionimage::set_data(). That method now respects an already-staged, populated
            // draft in $data->bookingoptionimage instead of rebuilding an empty one from the stored
            // files, so simply handing it the draft item id is enough — no form/request emulation.
            $data->bookingoptionimage = $draftitemid;

            $this->attachments->invalidate($token);
        } catch (\Throwable $e) {
            debugging(
                'mod_booking wizard: headerimage_token resolution failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
