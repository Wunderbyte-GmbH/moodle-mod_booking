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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\orchestrator;

/**
 * Dedicated synchronizer prompt builder.
 *
 * Keeps message-polish prompts separated from planner prompt assembly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_prompt_builder {
    /**
     * Turn continuation state: nothing runs after this reply (sufficient / clarification /
     * error). The reply contract forbids announcing any automatic follow-up action.
     */
    public const CONTINUATION_NONE = 'none';

    /**
     * Turn continuation state: the turn ends as a confirmation_request — queued steps run
     * after (and only after) the user confirms.
     */
    public const CONTINUATION_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    /** The turn ends with a question to the user; nothing was executed. */
    public const CONTINUATION_AWAITING_ANSWER = 'awaiting_answer';

    /**
     * Derive the continuation state from the final response_type (single source).
     *
     * @param string $responsetype
     * @return string
     */
    public static function continuation_for_response_type(string $responsetype): string {
        if ($responsetype === 'confirmation_request') {
            return self::CONTINUATION_AWAITING_CONFIRMATION;
        }
        if ($responsetype === 'clarification') {
            return self::CONTINUATION_AWAITING_ANSWER;
        }

        return self::CONTINUATION_NONE;
    }

    /** The line before the first observation block: the observations are new to the user (threads 23502/23481). */
    public const OBSERVATIONS_UNSEEN_LINE = 'The user has not seen the observations yet.';

    /**
     * Build synchronizer system prompt.
     *
     * @param string $actionclass
     * @return string
     */
    public function build_system_prompt(string $actionclass): string {
        $template = orchestrator::get_default_initial_prompt_template_for_action($actionclass);

        // Allow optional admin synthesis-style prefix without planner prompt reuse.
        // The setting is seeded with the default opening sentence, which the template
        // already contains — only a real admin customization is merged. When both start
        // with the expert-opening sentence, the prefix replaces the template's opening
        // line instead of duplicating it (same rule as phase_prompt_bundle_builder).
        $summaryprefix = trim((string)(get_config('bookingextension_agent', 'aiinitialprompt_summarise_text') ?? ''));
        if ($summaryprefix !== '' && !orchestrator::is_default_summary_prompt_prefix($summaryprefix)) {
            $trimmedtemplate = ltrim($template);
            $isexpertopening = static function (string $text): bool {
                return preg_match(
                    '/^You are an expert that composes polished, helpful answers/',
                    trim($text)
                ) === 1;
            };

            if ($isexpertopening($summaryprefix) && $isexpertopening($trimmedtemplate)) {
                $newlinepos = strpos($trimmedtemplate, "\n");
                $template = $newlinepos === false
                    ? $summaryprefix
                    : $summaryprefix . "\n" . ltrim(substr($trimmedtemplate, $newlinepos + 1), "\n");
            } else {
                $template = $summaryprefix . "\n\n" . $trimmedtemplate;
            }
        }

        // Keep placeholders stable across requests for better prompt-prefix caching:
        // the [SYSTEM] block stays byte-identical, real values live in the runtime blocks.
        return strtr($template, [
            '{{contextname}}' => '[SYSTEM_RUNTIME_STATE.context_name]',
            // Deprecated alias (agent is site-wide): resolves to the generic context name. Kept so
            // admin-customized templates that still use {{bookingname}} do not dangle.
            '{{bookingname}}' => '[SYSTEM_RUNTIME_STATE.context_name]',
            '{{timezonename}}' => '[SYSTEM_RUNTIME.timezone]',
            '{{nowiso}}' => '[SYSTEM_RUNTIME_STATE.now_iso]',
        ]);
    }

    /**
     * Build synchronizer prompt from history + observations.
     *
     * Cache-friendly ordering: static [SYSTEM], per-thread-stable [SYSTEM_RUNTIME],
     * append-only history/observations, then the per-request [SYSTEM_RUNTIME_STATE]
     * (now_iso, execution ledgers) so volatile content never busts the shared prefix.
     *
     * @param string $systemprompt
     * @param \stdClass[] $messages
     * @param string[] $observations
     * @param string $runtimecontext Per-thread-stable runtime facts.
     * @param string $runtimestate Per-request volatile runtime state.
     * @param string $continuation One of the CONTINUATION_* states, computed by the engine.
     * @param string[] $omittedfields Supported detail fields a read skill did NOT look up this
     *                                turn (engine-collected from the structured result rows).
     * @param string[] $activetokens Anonymizer tokens active in this thread (engine state).
     * @param string $replylanguage ISO code of the conversation's reply language (language_policy_service), '' = none.
     * @return string
     */
    public function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations,
        string $runtimecontext = '',
        string $runtimestate = '',
        string $continuation = self::CONTINUATION_NONE,
        array $omittedfields = [],
        array $activetokens = [],
        string $replylanguage = ''
    ): string {
        $parts = ["[SYSTEM]\n{$systemprompt}"];

        if ($runtimecontext !== '') {
            $parts[] = "[SYSTEM_RUNTIME]\n{$runtimecontext}";
        }

        foreach ($messages as $msg) {
            $role = strtoupper((string)($msg->role ?? 'user'));
            $content = (string)($msg->content ?? '');
            $parts[] = "[{$role}]\n{$content}";
        }

        // Wave 32 (frozen prompt spec, appendix A.3): the engine adds only the state of this turn - a fact, no rule. A
        // clarification and a confirmation_request both wait for the user (template rule 1). The former continuation
        // policies claimed "NOTHING was executed in this turn" on a question even after step 1 of a multi-step turn ran.
        $turnstate = 'TURN STATE: this reply ' . ($continuation === self::CONTINUATION_NONE
            ? 'reports the result.'
            : 'asks the user a question.');
        // L45 (SCC-2, GQ-3, URT-3; George 2026-09-27): memory, attachments or a relayed question in another language pulled
        // the reply away from the user's. The conversation's language is stated as a fact of this turn (selector's
        // user_lang with thread gravity, language_policy_service); rule 7 of the template stays as it is.
        $languageline = (new language_policy_service())->reply_language_line($replylanguage);
        if ($languageline !== '') {
            $turnstate .= "\n" . $languageline;
        }
        $parts[] = "[SYSTEM_RUNTIME_STATE]\n" . ($runtimestate !== '' ? $runtimestate . "\n" : '') . $turnstate;

        // Observations come AFTER the state ledgers, closest to [ASSISTANT]: the template's rule that the
        // observations are the facts is then reinforced by recency instead of being contradicted by it.
        // Threads 23502/23481/23506 (2026-09-30): nothing said that the user has not seen them, and gpt-oss replied
        // "I have already listed them". The sentence stands directly before the first block - in the rules or in the
        // turn-state line it changed nothing (A/B on the exact live prompts), here it did.
        $observationnumber = 1;
        foreach ($observations as $observation) {
            $trimmed = trim((string)$observation);
            if ($trimmed === '') {
                continue;
            }
            if ($observationnumber === 1) {
                $parts[] = self::OBSERVATIONS_UNSEEN_LINE;
            }
            $parts[] = "[OBSERVATION {$observationnumber}]\n{$trimmed}";
            $observationnumber++;
        }

        // PRO presentation policy — generic, never skill-specific. Only added WITHOUT full access
        // (no PRO license AND not running on the Wunderbyte LLM). With full access the agent runs
        // unrestricted, so this hint must not appear at all.
        if (!agent_access_service::has_full_access()) {
            $parts[] = "[PRO_LICENSE_POLICY]\n"
                . "Some tasks are only available with the Wunderbyte PRO license or an active Wunderbyte "
                . "subscription. When a request cannot be fulfilled for that reason, state plainly in the "
                . "user's language that the task is only available with a Wunderbyte PRO license or a "
                . "Wunderbyte subscription, and include this upgrade link as a markdown link labelled Get "
                . "Pro: [Get Pro](" . get_string('aitrial_pro_license_url', 'bookingextension_agent') . "). "
                . "Never reveal internal skill or function names, and never tell the user to try again "
                . "later or contact support — upgrading via the Get Pro link is the only next step.";
        }

        // Omitted-fields truth — injected ONLY when a read skill reported fields it did not look
        // up (engine state from the structured result rows, never a text match). Without this the
        // model reads a missing key as "the option has no such value" and contradicts the option
        // card standing right next to its reply (no seat limit defined vs. "0 / 12").
        $omittedfields = array_values(array_unique(array_filter(array_map(
            static fn($field): string => trim((string)$field),
            $omittedfields
        ))));
        if (!empty($omittedfields)) {
            $parts[] = "[OMITTED_FIELDS_POLICY]\n"
                . "The detail lookup this turn did NOT retrieve these fields: "
                . implode(', ', $omittedfields) . ". "
                . "Their absence from the observations says NOTHING about whether such a value exists. "
                . "NEVER state or imply that any of these fields is missing, unset, undefined or unlimited. "
                . "If the user asked about one of them, say plainly that it was not retrieved and offer to look "
                . "it up. Only fields listed as empty_fields in an observation may be reported as not set.";
        }

        // Token truth — only when the anonymizer masked something in this thread (engine state).
        $activetokens = array_values(array_unique(array_filter(array_map(
            static fn($token): string => trim((string)$token),
            $activetokens
        ))));
        if (!empty($activetokens)) {
            $parts[] = "[ANON_TOKEN_POLICY]\n"
                . 'Privacy placeholders active in this conversation: ' . implode(', ', $activetokens) . '.';
        }

        $parts[] = '[ASSISTANT]';

        return implode("\n\n", $parts);
    }
}
