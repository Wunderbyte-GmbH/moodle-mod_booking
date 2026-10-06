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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * A less-than sign inside a JSON string value must not kill a perfect answer.
 *
 * F76 (baseline run 31, threads 9318 and 9322, LRP-1/LRP-4; already run 30, thread 9059): the model answered
 * the rule-property question with valid JSON whose message lists placeholders ("<chat>, <due_date>") and
 * operators ("user value <= now + N days"). The interpreter ran strip_tags() over the whole JSON candidate,
 * which ate everything from "<chat>" onwards, and the complete answer died as CONTRACT_PARSE_ERROR; the retry
 * answered the same way and the turn ended as `error` although the skill had run. Tags are only stripped
 * from a candidate that is NOT already a JSON object.
 *
 * @covers \bookingextension_agent\local\wizard\interpreter
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class json_angle_bracket_content_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Run 31, thread 9318 (LRP-1): placeholders in angle brackets inside the message.
     */
    public function test_placeholders_in_angle_brackets_survive(): void {
        $raw = '{"response_type":"sufficient","message":"Eine Vorgabe (Regel) besteht aus folgenden Bausteinen:\n'
            . '- **Platzhalter**: <chat>, <due_date>, <due_date_with_time>, <first_name>\n'
            . '- **Operatoren**: nowminusdays(N) - Nutzerwert <= jetzt - N Tage","next_step_intent":"",'
            . '"lang":"de","user_lang":"de","planned_steps":[]}';

        $result = (new interpreter(skill_registry::make_default()))->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertStringContainsString('<due_date_with_time>', (string)($result['message'] ?? ''));
        $this->assertStringContainsString('<= jetzt', (string)($result['message'] ?? ''));
    }

    /**
     * Run 31, thread 9322 (LRP-4): a comparison operator is enough to trigger it.
     */
    public function test_comparison_operator_survives(): void {
        $raw = '{"response_type":"sufficient","commands":[],"planned_steps":[],"next_step_intent":"",'
            . '"message":"Date operators: nowminusdays(N) - user value <= now - N days, nowplusdays(N) - '
            . 'user value <= now + N days. Generic operators are case-sensitive string comparisons.",'
            . '"lang":"en","user_lang":"en"}';

        $result = (new interpreter(skill_registry::make_default()))->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertStringContainsString('<= now + N days', (string)($result['message'] ?? ''));
    }

    /**
     * An answer wrapped in markup is still unwrapped: the fence and tag handling stays for non-JSON candidates.
     */
    public function test_a_tag_wrapped_object_is_still_unwrapped(): void {
        $raw = '<p>{"response_type":"sufficient","message":"ok","commands":[],"planned_steps":[],'
            . '"next_step_intent":"","lang":"en","user_lang":"en"}</p>';

        $result = (new interpreter(skill_registry::make_default()))->interpret($raw, 0, 0);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
    }
}
