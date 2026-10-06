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

namespace bookingextension_agent\local\wizard\core\skills;

use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_checklist_preview;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use context_user;

/**
 * Readonly diagnosis skill: explain why a person may not be receiving e-mails / notifications.
 *
 * v1 scope (deliberately bounded — the broadest of the diagnose family): the user-level blockers that
 * cause the bulk of "no mail" cases (missing/blocked address, unconfirmed/suspended account, emailstop,
 * bounce threshold) plus, for site admins, the site mail switches and mail/message task health. It is
 * explicit about what it CANNOT see (actual SMTP delivery) and points booking-mail questions at
 * mod_booking.diagnose_user_booking.
 *
 * Privacy: notification state is sensitive, so cross-user diagnosis is gated on moodle/user:viewalldetails
 * (managers/admins), never the weaker viewdetails that students hold. Self-diagnosis is always allowed.
 * R0/readonly → all guards live in execute().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_notifications_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'core.diagnose_notifications';

    /**
     * Constructor. Read-only diagnosis (R0).
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * User-centric; works anywhere.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_SYSTEM;
    }

    /**
     * Read-only.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return true;
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Explain why a person may NOT be receiving e-mails or notifications from Moodle. Checks user-level '
                . 'blockers (missing/blocked e-mail, unconfirmed or suspended account, "disable all e-mail" setting, bounce '
                . 'threshold) and, for admins, the site mail switches and mail task health. Use for "why does Maria get no '
                . 'e-mails", "why does Tom get no notifications".',
            'is' => 'Moodle\'s mail and notification plumbing for a person.',
            'not' => 'Confirmation or reminder mails for one option (mod_booking.diagnose_user_booking).',
            'readonly' => true,
            'example_utterances' => [
                'the confirmation email never arrived',
                'why does this user get no emails from the system',
                'she isn\'t receiving any notifications',
                'no reminder emails are reaching him',
                'why aren\'t my Moodle emails coming through',
                'this account stopped getting email alerts',
            ],
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    // Wave 32 (A3): the old text 'Name ... "me" or empty = the current user' contradicted F81
                    // (e08b63b): resolve_userid no longer knows the word "me", only an EMPTY field means the requester.
                    'description' => 'Name, e-mail or id of the person asked about, as the user wrote it. Leave it out '
                        . 'when the requester asks about themself; never a placeholder or a pronoun.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id when known. Takes precedence over userquery.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['userquery'],
                'anchor_fields' => ['userquery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['userquery' => 'Maria Jones'];
    }

    /**
     * Discovery triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.diagnose_notifications_request',
                'description' => 'User asks why a person is not receiving e-mails or notifications from Moodle in '
                    . 'general (not a specific booking option\'s mails — that is mod_booking.diagnose_user_booking).',
                'examples' => [
                    'Why is Maria not getting any e-mails from Moodle?',
                    'Why is Tom not getting any notifications?',
                    'Why are notifications not reaching this user?',
                    'I am not getting any emails from the system.',
                ],
            ],
        ];
    }

    /**
     * Contextual guidance.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'core.diagnose_notifications',
                'triggers' => [
                    'no email', 'no emails', 'no notification', 'no notifications',
                    'not receiving emails', 'not receiving',
                    'notifications not', 'mail not arriving',
                ],
                'guidance' => [
                    '- core.diagnose_notifications explains general e-mail/notification delivery blockers (read-only):',
                    '  user-level (address, confirmed, suspended, emailstop, bounces) + site mail config (admins).',
                    '- For a SPECIFIC booking option\'s confirmation/reminder mails, prefer',
                    '  mod_booking.diagnose_user_booking (it lists the booking messages actually sent).',
                    '- It cannot confirm whether the mail server actually delivered; say so. Answer strictly from the',
                    '  returned findings.',
                ],
            ],
        ];
    }

    /**
     * Structural validation (pure).
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    /**
     * Run the notification diagnosis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $CFG;

        // Resolve the target user (default: self).
        $targetuserid = (int)($input['userid'] ?? 0);
        if ($targetuserid <= 0) {
            $targetuserid = $this->resolve_userid($input, $userid);
        }
        if ($targetuserid <= 0) {
            return $this->error_result(
                'I could not identify the person. Give a full name, e-mail address or numeric user id.',
                'user_unresolved'
            );
        }
        $isself = ($targetuserid === $userid);

        // Cross-user gate (R0 → here): notification state is private — managers/admins only.
        if (!$isself && !has_capability('moodle/user:viewalldetails', context_user::instance($targetuserid), $userid)) {
            return $this->error_result(
                get_string('nopermissions', 'error', 'moodle/user:viewalldetails'),
                'permission_denied'
            );
        }

        $targetuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
        if (!$targetuser || !empty($targetuser->deleted)) {
            return $this->error_result('That user no longer exists.', 'user_not_found');
        }

        $links = new diagnostic_link_builder();
        $rows = [];

        // User-level blockers (the common causes).
        $email = trim((string)($targetuser->email ?? ''));
        if ($email === '') {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'No e-mail address on the account',
                'Moodle cannot send e-mail without an address.'
            );
        } else if (email_is_not_allowed($email) !== false) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'E-mail address is not allowed',
                'The address fails the site allow/deny rules.'
            );
        } else {
            $rows[] = diagnostic_result_builder::row('ok', 'E-mail address present', $email);
        }

        if ((int)($targetuser->confirmed ?? 1) === 0) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Account not confirmed',
                'Unconfirmed accounts do not receive e-mail.'
            );
        }
        if ((int)($targetuser->suspended ?? 0) === 1) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Account suspended',
                'Suspended accounts do not receive notifications.'
            );
        }
        if ((int)($targetuser->emailstop ?? 0) === 1) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'User disabled all e-mail',
                'The account has "do not receive e-mail" set (emailstop).',
                $links->notification_preferences($targetuserid)
            );
        }
        if (over_bounce_threshold($targetuser)) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'E-mail bounce threshold exceeded',
                'Too many bounces — Moodle has stopped sending e-mail to this address.'
            );
        }

        if ($this->all_ok($rows)) {
            $rows[] = diagnostic_result_builder::row(
                'ok',
                'No user-level e-mail blocker found',
                'Address valid, account active/confirmed, e-mail enabled, no bounce problem.',
                $links->notification_preferences($targetuserid)
            );
        }

        // Site mail infrastructure (admins only — never expose server details).
        if (is_siteadmin($userid)) {
            if (!empty($CFG->noemailever)) {
                $rows[] = diagnostic_result_builder::row(
                    'fail',
                    'Site-wide e-mail is disabled',
                    'noemailever is set: Moodle sends no e-mail at all.',
                    $links->if_admin($links->outgoing_mail_config(), $userid)
                );
            }
            if (!empty($CFG->divertallemailsto)) {
                $rows[] = diagnostic_result_builder::row(
                    'warn',
                    'All e-mail is being diverted',
                    'divertallemailsto is set: mail goes to a test address, not the real recipients.',
                    $links->if_admin($links->outgoing_mail_config(), $userid)
                );
            }
            foreach ($this->mail_task_rows($links, $userid) as $taskrow) {
                $rows[] = $taskrow;
            }
        }

        // Honest limit (anti-hallucination).
        $rows[] = diagnostic_result_builder::row(
            'warn',
            'Actual delivery cannot be verified',
            'This check cannot confirm whether the mail server delivered the message to the inbox '
            . '(spam folder, external mail server). For a specific booking option\'s mails use the booking diagnosis.'
        );

        return $this->build_result($targetuser, $isself, $rows);
    }

    /**
     * Build rows for the health of mail/message scheduled tasks (admin-only).
     *
     * @param diagnostic_link_builder $links
     * @param int $userid
     * @return array[]
     */
    private function mail_task_rows(diagnostic_link_builder $links, int $userid): array {
        global $DB;
        $unhealthy = [];
        foreach ($DB->get_records('task_scheduled') as $task) {
            $class = (string)$task->classname;
            if (
                strpos($class, 'message') === false && strpos($class, 'email') === false
                && strpos($class, 'notification') === false
            ) {
                continue;
            }
            if ((int)$task->disabled === 1) {
                $unhealthy[] = $class . ' (disabled)';
            } else if ((int)$task->faildelay > 0) {
                $unhealthy[] = $class . ' (failing, faildelay=' . (int)$task->faildelay . ')';
            }
        }
        $link = $links->if_admin($links->scheduled_tasks(), $userid);
        if (empty($unhealthy)) {
            return [diagnostic_result_builder::row(
                'ok',
                'Mail/message scheduled tasks healthy',
                'No disabled or failing mail tasks.',
                $link
            )];
        }
        return [diagnostic_result_builder::row('fail', 'Mail/message scheduled task problem', implode('; ', $unhealthy), $link)];
    }

    /**
     * Whether all rows so far are OK (no blocker yet).
     *
     * @param array[] $rows
     * @return bool
     */
    private function all_ok(array $rows): bool {
        foreach ($rows as $r) {
            if (($r['status'] ?? '') !== 'ok') {
                return false;
            }
        }
        return true;
    }

    /**
     * Assemble the result.
     *
     * @param \stdClass $targetuser
     * @param bool $isself
     * @param array[] $rows
     * @return array
     */
    private function build_result(\stdClass $targetuser, bool $isself, array $rows): array {
        $subject = $isself ? 'you' : fullname($targetuser);

        $lines = ['Notification/e-mail diagnosis for ' . $subject . ':'];
        foreach ($rows as $r) {
            $glyph = diagnostic_result_builder::glyph((string)$r['status']);
            $line = $glyph . ' ' . $r['check'];
            if (trim((string)$r['finding']) !== '') {
                $line .= ' — ' . $r['finding'];
            }
            if (!empty($r['url'])) {
                $line .= ' (' . $r['url'] . ')';
            }
            $lines[] = $line;
        }
        $lines[] = 'Note: automated notification check. State only the findings above; do not claim a message was '
            . 'or was not delivered beyond them.';

        $usermessage = 'Notification check for ' . $subject . ' completed.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$targetuser->id,
            'diagnosis' => [
                'targetuserid' => (int)$targetuser->id,
                'checklist' => $rows,
            ],
            'checklist_rows' => $rows,
            'checklist_title' => 'Notification check: ' . $subject,
            'observation_full' => implode("\n", $lines),
        ];
    }

    /**
     * Render the checklist preview.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $rows = (array)($resultentry['checklist_rows'] ?? []);
        if (empty($rows)) {
            return null;
        }
        return (new diagnostic_checklist_preview())->render(
            $rows,
            (string)($resultentry['checklist_title'] ?? ''),
            ['targetuserid' => (int)($resultentry['diagnosis']['targetuserid'] ?? 0)]
        );
    }


    /**
     * Build an error result.
     *
     * @param string $message
     * @param string $errorclass
     * @return array
     */
    private function error_result(string $message, string $errorclass): array {
        return diagnostic_result_builder::error_result($message, $errorclass, 'Notification diagnosis could not run: ');
    }
}
