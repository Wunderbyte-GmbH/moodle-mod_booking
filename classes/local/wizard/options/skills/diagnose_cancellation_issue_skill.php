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

defined('MOODLE_INTERNAL') || die();

global $CFG;
// Global MOD_BOOKING_* constants — not loaded on non-booking pages (e.g. the
// dashboard agent entry point), so pull them in explicitly.
require_once($CFG->dirroot . '/mod/booking/lib.php');

use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\local\wizard\booking\booking_skill_support;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use mod_booking\singleton_service;

/**
 * Task definition for booking.diagnose_cancellation_issue.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_cancellation_issue_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.diagnose_cancellation_issue';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, \bookingextension_agent\local\wizard\dto\skill_risk_class::R0);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Diagnose why the current user cannot cancel their own booking for a booking option.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Why can\'t I cancel my booking?',
                'Why didn\'t my refund go through?',
                'The cancel button is greyed out for me',
                'I cancelled but wasn\'t removed from the option',
                'Why is the cancellation deadline blocking me?',
            ],
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question in natural language, e.g. "Why can I not cancel option X?". '
                        . 'Pass the original wording so the task can classify the blocker automatically. '
                        . 'Omit only when the option is already identified via optionquery or optionid.',
                    'required' => false,
                    'from_user_message' => true,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Booking option title, id-like reference, or words like "last option" '
                        . 'when referring to the last shown option.',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Explicit booking option id when already known.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'User reference (name, email, id-like text) when diagnosing for another person. '
                        . 'Omit if diagnosing for the current user (self-service).',
                    'required' => false,
                ],
                'targetuserid' => [
                    'type' => 'integer',
                    'description' => 'Optional explicit user id to diagnose for. Defaults to current user.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for localized task strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.diagnose_cancellation_issue_self_help',
                'description' => 'User asks why cancellation is not possible for a booking option.',
                'examples' => [
                    'Why can I not cancel this booking option?',
                    'Why do I not see a cancel button for option XY?',
                    'Why is cancellation unavailable for this booking?',
                ],
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'mod_booking.cancellation_self_help_diagnostics',
                'triggers' => [
                    'why can i not cancel', 'why no cancel button', 'cannot cancel booking',
                    'cancel button missing', 'cannot cancel', 'can not cancel',
                    'why can i not cancel', 'why can i not unregister',
                    'no cancellation', 'unable to cancel',
                ],
                'guidance' => [
                    '- Use booking.diagnose_cancellation_issue as response_type "task_call" IMMEDIATELY
                        — no clarification, no confirmation_request.',
                    '- booking.diagnose_cancellation_issue is READ-ONLY.
                        Execute it directly without asking the user for permission.',
                    '- Extract ALL information from the user message in one pass: option name
                        → optionquery, person name → userquery.',
                    '- Example: "Why can\'t Maxima cancel \'Reading with Georg\'?"
                        → optionquery="Reading with Georg", userquery="Maxima".',
                    '- Same applies to German input: extract optionquery and userquery directly from the message.',
                    '- Pass the full original user question as the "question" field so the task can classify the blocker.',
                    '- Do NOT ask for clarification when the option name appears in the user message — extract directly.',
                    '- If genuinely nothing about the option is mentioned (no name, no id, no context), only then ask once.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        $question = trim((string)($input['question'] ?? ''));
        $hasoptionref = trim((string)($input['optionquery'] ?? '')) !== '' || !empty($input['optionid']);
        if ($question === '' && !$hasoptionref) {
            $errors[] = $this->localized_string('agent_booking_diagnose_cancel_required_question', null, $lang);
        }

        $uservalidation = $this->validate_target_user_reference($input, $lang);
        $errors = array_merge($errors, $uservalidation['errors']);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Deep preflight validation and diagnostic entity preparation.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($guard = $this->require_booking_instance_scope($cmid)) {
            return $guard;
        }
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            return $this->invalid($this->build_preflight_issues((array)($structure['errors'] ?? [])));
        }

        $errors = [];
        $ambiguities = [];
        $lang = $this->get_output_language($input);
        $preparedinput = $input;

        $resolveduser = $this->resolve_diagnostic_user($input, $userid, $lang);
        if (($resolveduser['status'] ?? '') !== 'ok') {
            $errors[] = (string)($resolveduser['message']
                ?? get_string('agent_booking_diagnose_cancellation_user_resolve_failed', 'bookingextension_agent'));
        } else {
            $diagnosticuserid = (int)($resolveduser['userid'] ?? $userid);
            if ($diagnosticuserid !== $userid && !$this->can_analyze_other_user($cmid, $userid)) {
                $errors[] = $this->get_other_user_permission_error_message($lang);
            } else {
                $preparedinput['resolveddiagnosticuserid'] = $diagnosticuserid;
                $preparedinput['targetuserid'] = $diagnosticuserid;
            }
        }

        $resolvedoption = $this->resolve_option_id($input, $cmid, $userid, $lang);
        if (($resolvedoption['status'] ?? '') === 'ok') {
            $optionid = (int)($resolvedoption['optionid'] ?? 0);
            $preparedinput['resolvedoptionid'] = $optionid;
            $preparedinput['optionid'] = $optionid;
        } else if (($resolvedoption['status'] ?? '') === 'ambiguity') {
            $ambiguities[] = (string)($resolvedoption['message']
                ?? $this->localized_string('agent_booking_diagnose_ambiguity_option_specify', null, $lang));
        } else {
            $errors[] = (string)($resolvedoption['message']
                ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $lang));
        }

        if (!empty($errors) || !empty($ambiguities)) {
            return $this->invalid($this->build_preflight_issues(array_merge($errors, $ambiguities)));
        }

        return $this->pass($preparedinput);
    }

    /**
     * Convert messages to preflight issues.
     *
     * @param array $messages
     * @return array<int,array<string,string>>
     */
    private function build_preflight_issues(array $messages): array {
        $issues = [];
        foreach ($messages as $message) {
            $message = trim((string)$message);
            if ($message === '') {
                continue;
            }
            $issues[] = [
                'code' => 'DIAGNOSE_CANCELLATION_PREFLIGHT_BLOCKED',
                'severity' => 'needs_clarification',
                'message' => $message,
            ];
        }
        return $issues;
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($scoperesult = $this->build_no_instance_scope_result($cmid)) {
            return $scoperesult;
        }
        global $DB;

        $outputlang = $this->get_output_language($input);

        $diagnosticuserid = (int)($input['resolveddiagnosticuserid'] ?? 0);
        if ($diagnosticuserid <= 0) {
            // Step 1: Resolve which user to diagnose.
            // Priority: explicit targetuserid > userquery (resolved via DB) > current user.
            $resolveduser = $this->resolve_diagnostic_user($input, $userid, $outputlang);
            if (($resolveduser['status'] ?? '') !== 'ok') {
                $resolveusermessage = (string)($resolveduser['message']
                    ?? get_string('agent_booking_diagnose_cancellation_user_resolve_failed', 'bookingextension_agent'));
                return [
                    'status' => 'error',
                    'detail' => $resolveusermessage,
                    'resultid' => null,
                    'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
                ];
            }
            $diagnosticuserid = (int)($resolveduser['userid'] ?? $userid);
        }

        // Step 2: Permission check — diagnosing another user requires mod/booking:bookforothers.
        if ($diagnosticuserid !== $userid && !$this->can_analyze_other_user($cmid, $userid)) {
            return [
                'status' => 'error',
                'detail' => $this->get_other_user_permission_error_message($outputlang),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(
                    self::TASK_NAME,
                    $input,
                    ['Status: error', 'Permission denied for diagnosing other users.']
                ),
            ];
        }

        // Step 3: Resolve the booking option.
        // Priority: explicit optionid > "last option" session reference > optionquery (DB search).
        $optionid = (int)($input['resolvedoptionid'] ?? 0);
        if ($optionid <= 0) {
            $resolvedoption = $this->resolve_option_id($input, $cmid, $userid, $outputlang);
            if (($resolvedoption['status'] ?? '') !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)($resolvedoption['message']
                        ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang)),
                    'resultid' => null,
                    'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
                ];
            }
            $optionid = (int)($resolvedoption['optionid'] ?? 0);
        }

        // Step 4: Load option settings, booking answers and availability condition results.
        // conditionresults is a sorted array of all active bo_availability conditions for this user;
        // the last entry is the highest-priority blocker.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
        $conditionresults = bo_info::get_condition_results($optionid, $diagnosticuserid);
        $highestblocker = $this->resolve_highest_blocking_condition($conditionresults);
        $optionname = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: ('Option #' . $optionid);

        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $bookinginformation = $ba->return_all_booking_information($diagnosticuserid);
        $userstatus = (string)$ba->user_status_as_string($diagnosticuserid);

        // Step 4b: Check course enrollment.
        // A user who is not enrolled in the course cannot have an active booking to cancel.
        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $coursecontext = \context_course::instance((int)$cm->course);
        $isenrolled = is_enrolled($coursecontext, $diagnosticuserid, '', true);

        // Step 4c: Check option visibility.
        // invisible=1 → hidden (non-privileged users cannot see or interact with the option).
        // invisible=2 → not shown in list, but still accessible via direct link (informational only).
        $invisiblevalue = (int)($settings->invisible ?? 0);

        // Step 5: Collect all cancellation-related configuration values into a context array.
        // These values are passed to build_reason_lines() to avoid repeated DB/config lookups.
        $optiondisablecancel = !empty(booking_option::get_value_of_json_by_key($optionid, 'disablecancel'));
        $instancedisablecancel = !empty(booking::get_value_of_json_by_key($settings->bookingid, 'disablecancel'));
        $optioncanceluntil = (int)(booking_option::get_value_of_json_by_key($optionid, 'canceluntil') ?? 0);
        $effectivecanceluntil = (int)(booking_option::return_cancel_until_date($optionid) ?? 0);
        $coolingoffactive = cancelmyself::apply_coolingoff_period($settings, $diagnosticuserid);
        $coolingoffseconds = (int)get_config('booking', 'coolingoffperiod');
        $canuserscancel = ((int)($bookingsettings->cancancelbook ?? 0) === 1);

        $reasoncontext = [
            'optiondisablecancel'   => $optiondisablecancel, // Disablecancel set on the option itself.
            'instancedisablecancel' => $instancedisablecancel, // Disablecancel set on the booking instance.
            'optioncanceluntil'     => $optioncanceluntil, // Explicit canceluntil timestamp on the option.
            'effectivecanceluntil'  => $effectivecanceluntil, // Computed effective deadline (may differ from above).
            'coolingoffactive'      => $coolingoffactive, // Whether the global cooling-off period still applies.
            'coolingoffseconds'     => $coolingoffseconds, // Cooling-off duration in seconds.
            'canuserscancel'        => $canuserscancel, // Instance-level cancancelbook toggle.
            'waitforconfirmation'   => !empty($settings->waitforconfirmation), // Waitinglist confirmation flow.
            'hasprice'              => !empty($settings->jsonobject->useprice), // Option has a price.
            'shoppingcartexists'    => class_exists('local_shopping_cart\\shopping_cart'), // Plugin present.
            'isenrolled'            => $isenrolled, // Whether user is enrolled in the course.
            'invisiblevalue'        => $invisiblevalue, // Zero means visible, one means hidden, two means not in list.
            'courseid'              => (int)$cm->course,
        ];

        // Step 6: Build human-readable reason lines.
        // Checks (in order): enrollment, option visibility, instance/option disablecancel,
        // canceluntil deadlines, price without cart, activity completion, not-booked / reserved states,
        // cancancelbook disabled, effective deadline, shopping-cart policy,
        // waitinglist confirmation flow, cooling-off period.
        $reasons = $this->build_reason_lines(
            $conditionresults,
            $highestblocker,
            $bookinginformation,
            $reasoncontext,
            $settings,
            $diagnosticuserid,
            $userid,
            $outputlang
        );

        $stats = [
            'option_disablecancel' => $optiondisablecancel,
            'instance_disablecancel' => $instancedisablecancel,
            'instance_cancancelbook_value' => (int)($bookingsettings->cancancelbook ?? 0),
            'instance_cancancelbook_enabled' => $canuserscancel,
            'option_canceluntil' => $optioncanceluntil,
            'effective_canceluntil' => $effectivecanceluntil,
            'coolingoff_active' => $coolingoffactive,
            'reply_requirements' => 'Mention exact setting keys and concrete admin changes.',
        ];

        $usermessage = $this->localized_string(
            'agent_booking_diagnose_intro_checked_option',
            $optionname,
            $outputlang
        );
        // Entity mentions always carry a real moodle_url link for the synchronizer.
        if ($cmid > 0 && $optionid > 0) {
            $usermessage .= ' (' . booking_skill_support::build_option_link_for_output($cmid, $optionid) . ')';
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $optionid,
            'previewoptionids' => [$optionid],
            'diagnosis' => [
                'issue' => 'cannot_cancel',
                'optionid' => $optionid,
                'optionname' => $optionname,
                'userid' => $diagnosticuserid,
                'userstatus' => $userstatus,
                'highestblocker' => $highestblocker,
                'stats' => $stats,
                'reasons' => $reasons,
            ],
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Resolved option: ' . $optionname . ' (id=' . $optionid . ')',
                    'Diagnosed user id: ' . $diagnosticuserid,
                    'User status: ' . $userstatus,
                    'Highest blocker id: ' . (int)($highestblocker['id'] ?? 0),
                    'Reasons: ' . count($reasons),
                ]
            ),
        ];
    }

    /**
     * Resolve the highest blocking condition in a sorted condition result set.
     *
     * @param array $conditionresults
     * @return array
     */
    private function resolve_highest_blocking_condition(array $conditionresults): array {
        if (empty($conditionresults)) {
            return [];
        }

        $last = end($conditionresults);
        if (!is_array($last)) {
            return [];
        }

        return $last;
    }

    /**
     * Build concrete reason lines for cancellation diagnosis.
     *
     * @param array $conditionresults
     * @param array $highestblocker
     * @param array $bookinginformation
     * @param array $reasoncontext
     * @param object $settings
     * @param int $diagnosticuserid
     * @param int $currentuserid
     * @param string $lang
     * @return array
     */
    private function build_reason_lines(
        array $conditionresults,
        array $highestblocker,
        array $bookinginformation,
        array $reasoncontext,
        object $settings,
        int $diagnosticuserid,
        int $currentuserid,
        string $lang = ''
    ): array {
        $reasons = [];
        $now = time();
        $isselfdiagnosis = ($diagnosticuserid === $currentuserid);

        $highestid = (int)($highestblocker['id'] ?? 0);

        // Structural pre-checks: enrollment and visibility.
        // These are checked first; if they block, further checks are still shown for completeness.

        if (empty($reasoncontext['isenrolled'])) {
            $reasons[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_reason_not_enrolled'
                    : 'agent_booking_diagnose_reason_not_enrolled_other',
                null,
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_reason_not_enrolled_concrete',
                (object)['courseid' => (int)($reasoncontext['courseid'] ?? 0)],
                $lang
            );
        }

        $invisiblevalue = (int)($reasoncontext['invisiblevalue'] ?? 0);
        if ($invisiblevalue === 1) {
            $reasons[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_reason_option_invisible'
                    : 'agent_booking_diagnose_reason_option_invisible_other',
                null,
                $lang
            );
            $reasons[] = $this->localized_string('agent_booking_diagnose_reason_option_invisible_concrete', null, $lang);
        } else if ($invisiblevalue === 2) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_reason_option_hidden_from_list_concrete', null, $lang);
        }

        // Cancellation-specific checks.

        if (!empty($reasoncontext['instancedisablecancel'])) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_instance_disablecancel', null, $lang);
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_concrete_instance_disablecancel',
                null,
                $lang
            );
        }

        if (!empty($reasoncontext['optiondisablecancel'])) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_option_disablecancel', null, $lang);
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_concrete_option_disablecancel',
                null,
                $lang
            );
        }

        $optioncanceluntil = (int)($reasoncontext['optioncanceluntil'] ?? 0);
        if ($optioncanceluntil > 0 && $now > $optioncanceluntil) {
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_option_canceluntil_passed',
                userdate($optioncanceluntil),
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_concrete_option_canceluntil_passed',
                (object)[
                    'timestamp' => $optioncanceluntil,
                    'date' => userdate($optioncanceluntil),
                ],
                $lang
            );
        }

        if (!empty($reasoncontext['hasprice']) && empty($reasoncontext['shoppingcartexists'])) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_price_without_shopping_cart', null, $lang);
        }

        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

        if ($bookinganswer->is_activity_completed($diagnosticuserid)) {
            $reasons[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_cancel_reason_activity_completed'
                    : 'agent_booking_diagnose_cancel_reason_activity_completed_other',
                null,
                $lang
            );
        }

        if (isset($bookinginformation['notbooked'])) {
            $reasons[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_cancel_reason_not_booked'
                    : 'agent_booking_diagnose_cancel_reason_not_booked_other',
                null,
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_concrete_notbooked_state',
                null,
                $lang
            );
        }

        if (isset($bookinginformation['iamreserved'])) {
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
            if (!empty($bookingsettings->iselective)) {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_cancel_reason_elective_reservation'
                        : 'agent_booking_diagnose_cancel_reason_elective_reservation_other',
                    null,
                    $lang
                );
            } else {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_cancel_reason_reserved_state'
                        : 'agent_booking_diagnose_cancel_reason_reserved_state_other',
                    null,
                    $lang
                );
            }
        }

        if (empty($reasoncontext['canuserscancel'])) {
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_instance_cancancelbook_disabled',
                null,
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_concrete_instance_cancancelbook_disabled',
                null,
                $lang
            );
        }

        if (isset($bookinginformation['onwaitinglist']) || isset($bookinginformation['iambooked'])) {
            $effectivecanceluntil = (int)($reasoncontext['effectivecanceluntil'] ?? 0);
            if ($effectivecanceluntil > 0 && $now > $effectivecanceluntil) {
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_cancel_reason_effective_canceluntil_passed',
                    userdate($effectivecanceluntil),
                    $lang
                );
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_cancel_reason_concrete_effective_canceluntil_passed',
                    (object)[
                        'timestamp' => $effectivecanceluntil,
                        'date' => userdate($effectivecanceluntil),
                    ],
                    $lang
                );
            }

            if (!empty($reasoncontext['hasprice']) && !empty($reasoncontext['shoppingcartexists'])) {
                $item = (object)[
                    'itemid' => $settings->id,
                    'componentname' => 'bookingextension_agent',
                    'canceluntil' => $effectivecanceluntil,
                ];
                if (!\local_shopping_cart\shopping_cart::allowed_to_cancel_for_item($item, 'option')) {
                    $reasons[] = $this->localized_string(
                        'agent_booking_diagnose_cancel_reason_shopping_cart_denies_cancel',
                        null,
                        $lang
                    );
                }

                if (isset($bookinginformation['onwaitinglist'])) {
                    $waitinglistfullybooked = !empty($bookinginformation['onwaitinglist']['fullybooked']);
                    if (empty($reasoncontext['waitforconfirmation']) && !$waitinglistfullybooked) {
                        $reasons[] = $this->localized_string(
                            'agent_booking_diagnose_cancel_reason_waitinglist_no_confirmation_flow',
                            null,
                            $lang
                        );
                    } else {
                        $usersonwaitinglist = $bookinganswer->get_usersonwaitinglist();
                        $ba = $usersonwaitinglist[$diagnosticuserid] ?? null;
                        if (!empty($ba->json)) {
                            $jsonobject = json_decode($ba->json);
                            if (!empty($jsonobject->confirmwaitinglist)) {
                                $reasons[] = $this->localized_string(
                                    'agent_booking_diagnose_cancel_reason_waitinglist_confirmation_pending',
                                    null,
                                    $lang
                                );
                            }
                        }
                    }
                }
            }

            if (!empty($reasoncontext['coolingoffactive'])) {
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_cancel_reason_coolingoff_active',
                    (int)($reasoncontext['coolingoffseconds'] ?? 0),
                    $lang
                );
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_cancel_reason_concrete_coolingoff_active',
                    (int)($reasoncontext['coolingoffseconds'] ?? 0),
                    $lang
                );
            }
        }

        if ($highestid === MOD_BOOKING_BO_COND_CANCELMYSELF) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_cancel_button_available', null, $lang);
        }

        $reasons = array_values(array_unique(array_filter(array_map('trim', $reasons))));
        if (empty($reasons)) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_none', null, $lang);
        }

        return $reasons;
    }

    /**
     * Validate target user reference if provided.
     *
     * @param array $input
     * @param string $lang
     * @return array
     */
    private function validate_target_user_reference(array $input, string $lang = ''): array {
        $errors = [];
        $ambiguities = [];

        $targetuserid = (int)($input['targetuserid'] ?? 0);
        $userquery = trim((string)($input['userquery'] ?? ''));

        if ($targetuserid <= 0 && $userquery === '') {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        // Do not fail hard in pre-validation for free-text userquery.
        // Execute() performs robust resolution with question-based fallback.
        return ['errors' => $errors, 'ambiguities' => $ambiguities];
    }

    /**
     * Resolve diagnostic target user from explicit LLM input.
     *
     * The LLM must provide either targetuserid or userquery explicitly.
     * If neither is provided, defaults to the current user.
     *
     * @param array $input
     * @param int $currentuserid
     * @param string $lang
     * @return array
     */
    private function resolve_diagnostic_user(array $input, int $currentuserid, string $lang = ''): array {
        global $DB;

        // Explicit user ID takes precedence.
        $targetuserid = (int)($input['targetuserid'] ?? 0);
        if ($targetuserid > 0) {
            if (!$DB->record_exists('user', ['id' => $targetuserid, 'deleted' => 0])) {
                return [
                    'status' => 'error',
                    'message' => $this->localized_string('agent_booking_resolve_user_no_match', $targetuserid, $lang),
                ];
            }
            return ['status' => 'ok', 'userid' => $targetuserid];
        }

        // LLM must provide userquery if diagnosing another user.
        $userquery = trim((string)($input['userquery'] ?? ''));
        if ($userquery === '') {
            // No user reference provided → diagnose for current user.
            return ['status' => 'ok', 'userid' => $currentuserid];
        }

        // Attempt to resolve the user query.
        $resolved = booking_skill_support::resolve_single_user($userquery);
        if (($resolved['status'] ?? '') === 'ok') {
            return ['status' => 'ok', 'userid' => (int)($resolved['userid'] ?? $currentuserid)];
        }

        return $resolved;
    }

    /**
     * Check if current user may diagnose another user in this booking context.
     *
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    private function can_analyze_other_user(int $cmid, int $userid): bool {
        $context = \context_module::instance($cmid);
        return has_capability('mod/booking:bookforothers', $context, $userid);
    }

    /**
     * Permission denied message for cross-user diagnostics.
     *
     * @param string $lang
     * @return string
     */
    private function get_other_user_permission_error_message(string $lang = ''): string {
        return $this->localized_string('agent_booking_diagnose_cancel_other_user_permission_denied', null, $lang);
    }

    /**
     * Validate whether the task has enough option information.
     *
     * @param array $input
     * @param int $cmid
     * @param string $lang
     * @return array
     */
    private function validate_option_reference(array $input, int $cmid, string $lang = ''): array {
        $errors = [];
        $ambiguities = [];

        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = trim((string)($input['optionquery'] ?? ''));

        if ($optionid <= 0 && $optionquery === '') {
            $ambiguities[] = $this->localized_string('agent_booking_diagnose_ambiguity_option_required', null, $lang);
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        if ($optionid > 0) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        if (booking_skill_support::is_last_option_reference($optionquery)) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        $resolved = booking_skill_support::resolve_single_option($cmid, $optionquery, '');
        if (($resolved['status'] ?? '') === 'ambiguity') {
            $ambiguities[] = (string)($resolved['message']
                ?? $this->localized_string('agent_booking_diagnose_ambiguity_option_specify', null, $lang));
        } else if (($resolved['status'] ?? '') === 'error') {
            $errors[] = (string)($resolved['message']
                ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $lang));
        }

        return ['errors' => $errors, 'ambiguities' => $ambiguities];
    }

    /**
     * Resolve the target option id from explicit id, query or last preview selection.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param string $lang
     * @return array
     */
    private function resolve_option_id(array $input, int $cmid, int $userid, string $lang = ''): array {
        global $DB;

        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionid > 0) {
            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
            if ($DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])) {
                return ['status' => 'ok', 'optionid' => $optionid];
            }

            // If a model provided a stale/wrong optionid but also a concrete title,
            // prefer resolving by query over failing hard.
            if ($optionquery !== '') {
                return booking_skill_support::resolve_single_option($cmid, $optionquery, '');
            }

            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_option_not_in_instance', null, $lang),
            ];
        }

        if ($optionquery === '') {
            return [
                'status' => 'ambiguity',
                'message' => $this->localized_string('agent_booking_diagnose_ambiguity_option_title_or_id', null, $lang),
            ];
        }

        if (booking_skill_support::is_last_option_reference($optionquery)) {
            $lastids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
            if (count($lastids) === 1) {
                return ['status' => 'ok', 'optionid' => (int)$lastids[0]];
            }
            if (count($lastids) > 1) {
                return [
                    'status' => 'ambiguity',
                    'message' => $this->localized_string('agent_booking_diagnose_ambiguity_last_preview_multiple', null, $lang),
                ];
            }
            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_last_preview_none', null, $lang),
            ];
        }

        return booking_skill_support::resolve_single_option($cmid, $optionquery, '');
    }
}
