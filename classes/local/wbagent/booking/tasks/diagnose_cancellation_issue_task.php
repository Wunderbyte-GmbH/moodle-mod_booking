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

use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\local\wbagent\services\answering\diagnose_answering_service;
use mod_booking\singleton_service;

/**
 * Task definition for booking.diagnose_cancellation_issue.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_cancellation_issue_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.diagnose_cancellation_issue';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
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
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question in natural language, e.g. "Why can I not cancel option X?"',
                    'required' => true,
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
                'id' => 'booking.diagnose_cancellation_issue_self_help',
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
                'id' => 'booking.cancellation_self_help_diagnostics',
                'triggers' => [
                    'why can i not cancel',
                    'why no cancel button',
                    'cannot cancel booking',
                    'cancel button missing',
                ],
                'guidance' => [
                    '- Use booking.diagnose_cancellation_issue for self-help questions about missing cancellation options.',
                    '- Pass the original user wording as question.',
                    '- If the question is about another person, pass userquery (e.g. "Maxima Müller") or targetuserid explicitly.',
                    '- Do NOT infer userquery from question text; extract it semantically and pass it as a field.',
                    '- Pass optionquery when the option title/reference is available; '
                        . 'otherwise the task will ask a follow-up question.',
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $ambiguities = [];
        $lang = $this->get_output_language($input);

        $question = trim((string)($input['question'] ?? ''));
        if ($question === '') {
            $errors[] = $this->localized_string('agent_booking_diagnose_cancel_required_question', null, $lang);
        }

        $optionvalidation = $this->validate_option_reference($input, $cmid, $lang);
        $errors = array_merge($errors, $optionvalidation['errors']);
        $ambiguities = array_merge($ambiguities, $optionvalidation['ambiguities']);

        $uservalidation = $this->validate_target_user_reference($input, $lang);
        $errors = array_merge($errors, $uservalidation['errors']);
        $ambiguities = array_merge($ambiguities, $uservalidation['ambiguities']);

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
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
        global $DB;

        $outputlang = $this->get_output_language($input);
        $resolveduser = $this->resolve_diagnostic_user($input, $userid, $outputlang);
        if (($resolveduser['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolveduser['message'] ?? 'Could not resolve target user.'),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
            ];
        }

        $diagnosticuserid = (int)($resolveduser['userid'] ?? $userid);
        if ($diagnosticuserid !== $userid && !$this->can_analyze_other_user($cmid)) {
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
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
        $conditionresults = bo_info::get_condition_results($optionid, $diagnosticuserid);
        $highestblocker = $this->resolve_highest_blocking_condition($conditionresults);
        $optionname = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: ('Option #' . $optionid);

        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $bookinginformation = $ba->return_all_booking_information($diagnosticuserid);
        $userstatus = (string)$ba->user_status_as_string($diagnosticuserid);

        $optiondisablecancel = !empty(booking_option::get_value_of_json_by_key($optionid, 'disablecancel'));
        $instancedisablecancel = !empty(booking::get_value_of_json_by_key($settings->bookingid, 'disablecancel'));
        $optioncanceluntil = (int)(booking_option::get_value_of_json_by_key($optionid, 'canceluntil') ?? 0);
        $effectivecanceluntil = (int)(booking_option::return_cancel_until_date($optionid) ?? 0);
        $coolingoffactive = cancelmyself::apply_coolingoff_period($settings, $diagnosticuserid);
        $coolingoffseconds = (int)get_config('booking', 'coolingoffperiod');
        $canuserscancel = ((int)($bookingsettings->cancancelbook ?? 0) === 1);

        $reasoncontext = [
            'optiondisablecancel' => $optiondisablecancel,
            'instancedisablecancel' => $instancedisablecancel,
            'optioncanceluntil' => $optioncanceluntil,
            'effectivecanceluntil' => $effectivecanceluntil,
            'coolingoffactive' => $coolingoffactive,
            'coolingoffseconds' => $coolingoffseconds,
            'canuserscancel' => $canuserscancel,
            'waitforconfirmation' => !empty($settings->waitforconfirmation),
            'hasprice' => !empty($settings->jsonobject->useprice),
            'shoppingcartexists' => class_exists('local_shopping_cart\\shopping_cart'),
        ];

        $reasons = $this->build_reason_lines(
            $conditionresults,
            $highestblocker,
            $bookinginformation,
            $reasoncontext,
            $settings,
            $diagnosticuserid,
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

        $usermessage = '';
        $answersource = 'none';
        try {
            $answerquestion = trim((string)($input['question'] ?? ''));
            $answerquestion .= "\n\n"
                . 'Answer requirements: mention exact setting keys and provide a concrete admin action for each blocker.';

            $answeringresult = $this->create_diagnose_answering_service()->answer_question(
                $answerquestion,
                [
                    'issuetype' => 'cannot_cancel',
                    'optionname' => $optionname,
                    'userstatus' => $userstatus,
                    'reasons' => $reasons,
                    'stats' => $stats,
                ],
                $outputlang,
                $cmid,
                $userid
            );
            $llmanswer = trim((string)($answeringresult['answer'] ?? ''));
            if ($llmanswer !== '') {
                $usermessage = $this->enforce_max_chars($llmanswer, 500);
                $answersource = 'llm';
            }
        } catch (\Throwable $e) {
            $answersource = 'error';
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'summary' => $usermessage,
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
                    'Answer source: ' . $answersource,
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
     * @param int $userid
     * @param string $lang
     * @return array
     */
    private function build_reason_lines(
        array $conditionresults,
        array $highestblocker,
        array $bookinginformation,
        array $reasoncontext,
        object $settings,
        int $userid,
        string $lang = ''
    ): array {
        $reasons = [];
        $now = time();

        $highestid = (int)($highestblocker['id'] ?? 0);

        if (!empty($reasoncontext['instancedisablecancel'])) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_instance_disablecancel', null, $lang);
            $reasons[] = 'Concrete setting: booking.json.disablecancel = 1 (instance-wide cancellation block). '
                . 'Admin action: In the booking instance, disable "Disable cancellation for the whole booking instance".';
        }

        if (!empty($reasoncontext['optiondisablecancel'])) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_option_disablecancel', null, $lang);
            $reasons[] = 'Concrete setting: booking_option.json.disablecancel = 1 (this option only). '
                . 'Admin action: In option advanced settings, disable "Disable cancellation of this booking option".';
        }

        $optioncanceluntil = (int)($reasoncontext['optioncanceluntil'] ?? 0);
        if ($optioncanceluntil > 0 && $now > $optioncanceluntil) {
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_option_canceluntil_passed',
                userdate($optioncanceluntil),
                $lang
            );
            $reasons[] = 'Concrete setting: booking_option.json.canceluntil = ' . $optioncanceluntil
                . ' (' . userdate($optioncanceluntil) . ') is in the past. '
                . 'Admin action: set canceluntil to a future timestamp or remove that restriction.';
        }

        if (!empty($reasoncontext['hasprice']) && empty($reasoncontext['shoppingcartexists'])) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_price_without_shopping_cart', null, $lang);
        }

        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

        if ($bookinganswer->is_activity_completed($userid)) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_activity_completed', null, $lang);
        }

        if (isset($bookinginformation['notbooked'])) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_not_booked', null, $lang);
            $reasons[] = 'Concrete state: bookinginformation.notbooked is set. '
                . 'Without an active booking, self-cancel is not available.';
        }

        if (isset($bookinginformation['iamreserved'])) {
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
            if (!empty($bookingsettings->iselective)) {
                $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_elective_reservation', null, $lang);
            } else {
                $reasons[] = $this->localized_string('agent_booking_diagnose_cancel_reason_reserved_state', null, $lang);
            }
        }

        if (empty($reasoncontext['canuserscancel'])) {
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_cancel_reason_instance_cancancelbook_disabled',
                null,
                $lang
            );
            $reasons[] = 'Concrete setting: booking.cancancelbook != 1. '
                . 'Admin action: In the booking instance, enable "Allow users to cancel their booking themselves".';
        }

        if (isset($bookinginformation['onwaitinglist']) || isset($bookinginformation['iambooked'])) {
            $effectivecanceluntil = (int)($reasoncontext['effectivecanceluntil'] ?? 0);
            if ($effectivecanceluntil > 0 && $now > $effectivecanceluntil) {
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_cancel_reason_effective_canceluntil_passed',
                    userdate($effectivecanceluntil),
                    $lang
                );
                $reasons[] = 'Concrete setting: Effective cancellation deadline (computed from instance settings) '
                    . 'has passed: ' . $effectivecanceluntil . ' (' . userdate($effectivecanceluntil) . '). '
                    . 'Admin action: adjust allowupdatedays/allowupdatetimestamp or relative cancellation rule.';
            }

            if (!empty($reasoncontext['hasprice']) && !empty($reasoncontext['shoppingcartexists'])) {
                $item = (object)[
                    'itemid' => $settings->id,
                    'componentname' => 'mod_booking',
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
                        $ba = $usersonwaitinglist[$userid] ?? null;
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
                $reasons[] = 'Concrete setting: booking/coolingoffperiod = '
                    . (int)($reasoncontext['coolingoffseconds'] ?? 0)
                    . ' seconds. Admin action: reduce cooling-off period or set it to 0.';
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
        $resolved = booking_task_support::resolve_single_user($userquery);
        if (($resolved['status'] ?? '') === 'ok') {
            return ['status' => 'ok', 'userid' => (int)($resolved['userid'] ?? $currentuserid)];
        }

        return $resolved;
    }

    /**
     * Check if current user may diagnose another user in this booking context.
     *
     * @param int $cmid
     * @return bool
     */
    private function can_analyze_other_user(int $cmid): bool {
        $context = \context_module::instance($cmid);
        return has_capability('mod/booking:bookforothers', $context);
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

        if (booking_task_support::is_last_option_reference($optionquery)) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        $resolved = booking_task_support::resolve_single_option($cmid, $optionquery, '');
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
        if ($optionid > 0) {
            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
            if ($DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])) {
                return ['status' => 'ok', 'optionid' => $optionid];
            }
            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_option_not_in_instance', null, $lang),
            ];
        }

        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionquery === '') {
            return [
                'status' => 'ambiguity',
                'message' => $this->localized_string('agent_booking_diagnose_ambiguity_option_title_or_id', null, $lang),
            ];
        }

        if (booking_task_support::is_last_option_reference($optionquery)) {
            $lastids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
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

        return booking_task_support::resolve_single_option($cmid, $optionquery, '');
    }

    /**
     * Create the diagnose answering service.
     *
     * @return diagnose_answering_service
     */
    protected function create_diagnose_answering_service(): diagnose_answering_service {
        return new diagnose_answering_service();
    }
}
