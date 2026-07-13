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

namespace mod_booking;

use advanced_testcase;
use context_module;
use mod_booking\local\wizard\options\skills\create_option_skill;

/**
 * F3-W2 guards: create_option must keep planner repair vocabulary out of the user error channel.
 *
 * The agent engine's two-channel cause contract (see parameter_contract_validator and
 * bookingextension/agent/tests/agent/contracts/f3_error_cause_channels_test.php) splits every
 * structural/preflight failure into:
 *  - USER channel: check_structure 'errors' and issue 'user_question'/'usermessage'/'message' —
 *    plain-English material the synchronizer may render to the end user;
 *  - REPAIR channel: a 'repair' field — planner-only instructions (key lists, format specs,
 *    "Retry <task> …" directives, allowed-key enumerations).
 *
 * create_option_skill is NOT migrated yet (F3-W2): it stuffs the full repair vocabulary
 * ("Unknown properties: …", "Only use the allowed keys …", "Retry mod_booking.create_option once
 * with corrected canonical keys …", 'Field "prices" must be an object map like {"default": …}')
 * into the user channel and supplies no 'repair' field at all. These tests are red until the
 * skill is migrated to the two-channel contract.
 *
 * Direction guard (agent commit 90c9399): the future fix delivers the user cause as plain-English
 * LLM material, NOT necessarily via get_string — so nothing here asserts localization; the tests
 * only assert that fixed engine repair strings stay out of the user channel and that a repair
 * channel exists.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 */
final class agent_create_option_error_channels_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * (a) Unknown input key: the user channel of the structural error must not leak schema
     * internals; the technical removal instruction belongs into the 'repair' channel.
     *
     * Deliberately NOT using 'price' as the unknown key here: 'price' is covered by the N3
     * decision test (agent_create_option_intuitive_keys_test) and may become a legal alias,
     * which must not flip this channel-separation guard.
     */
    public function test_unknown_key_structure_error_keeps_repair_out_of_user_channel(): void {
        $this->resetAfterTest();
        $skill = new create_option_skill();

        $result = $skill->check_structure([
            'text' => 'Morning yoga',
            'zzz_unknown_planner_key' => 'whatever',
        ]);

        $this->assertFalse($result['valid']);

        $usertext = implode(' ', array_map('strval', (array)($result['errors'] ?? [])));
        $this->assertStringNotContainsStringIgnoringCase(
            'Unknown properties',
            $usertext,
            'F3-W2 leak: the schema-mismatch observation is placed in the USER channel (errors). '
            . 'Plain-English user cause belongs there; "Unknown properties: …" belongs into repair.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'Only use the allowed keys',
            $usertext,
            'F3-W2 leak: the allowed-key enumeration is planner repair vocabulary and must not '
            . 'appear in the USER channel (errors).'
        );

        $repairtext = $this->repair_channel_text($result);
        $this->assertNotSame(
            '',
            $repairtext,
            "Two-channel contract: check_structure must supply a non-empty 'repair' field carrying "
            . 'the technical instructions (key lists, retry directive) for the planner.'
        );
        $this->assertStringContainsString(
            'zzz_unknown_planner_key',
            $repairtext,
            'The repair channel must name the offending key so the planner knows what to remove.'
        );
    }

    /**
     * (c1) Missing title, structural path: the user channel must be a plain ask for the title,
     * free of retry directives and canonical-key vocabulary; those go into 'repair'.
     */
    public function test_missing_title_structure_error_keeps_repair_out_of_user_channel(): void {
        $this->resetAfterTest();
        $skill = new create_option_skill();

        $result = $skill->check_structure(['maxanswers' => 12]);

        $this->assertFalse($result['valid']);

        $usertext = implode(' ', array_map('strval', (array)($result['errors'] ?? [])));
        $this->assertStringNotContainsString(
            'Retry ' . create_option_skill::TASK_NAME,
            $usertext,
            'F3-W2 leak: the planner retry directive is placed in the USER channel (errors) '
            . 'for a missing title. The user only needs to be asked for a title.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'canonical keys',
            $usertext,
            'F3-W2 leak: canonical-key vocabulary is planner repair material and must not appear '
            . 'in the USER channel (errors).'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'Only use the allowed keys',
            $usertext,
            'F3-W2 leak: the allowed-key enumeration must not appear in the USER channel (errors).'
        );

        $this->assertNotSame(
            '',
            $this->repair_channel_text($result),
            "Two-channel contract: check_structure must supply a non-empty 'repair' field with the "
            . 'canonical-key retry instructions for the planner.'
        );
    }

    /**
     * (c2) Missing title, preflight path: the MISSING_TITLE issue must carry a plain title
     * question in the user channel; message/usermessage are user-channel fallbacks and must not
     * transport the retry directive; the technical part belongs into a repair channel.
     */
    public function test_missing_title_preflight_issue_carries_plain_question_and_repair(): void {
        [$contextid, $userid] = $this->make_booking_context();

        $dto = (new create_option_skill())->preflight(['maxanswers' => 5], $contextid, $userid);

        $this->assertNotSame('pass', $dto->status);

        $issue = null;
        foreach ($dto->issues as $candidate) {
            if (is_array($candidate) && (string)($candidate['code'] ?? '') === 'MISSING_TITLE') {
                $issue = $candidate;
                break;
            }
        }
        $this->assertNotNull($issue, 'Preflight without a title must raise a MISSING_TITLE issue. Got: '
            . json_encode($dto->issues));

        $this->assertNotSame(
            '',
            trim((string)($issue['user_question'] ?? '')),
            'The MISSING_TITLE issue must ask the user for the title via user_question.'
        );

        $usertext = $this->issue_user_channel_text($issue);
        $this->assertStringNotContainsString(
            'Retry ' . create_option_skill::TASK_NAME,
            $usertext,
            'F3-W2 leak: the planner retry directive sits in a USER-channel field '
            . '(user_question/usermessage/message) of the MISSING_TITLE issue.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'canonical keys',
            $usertext,
            'F3-W2 leak: canonical-key vocabulary sits in a USER-channel field of the '
            . 'MISSING_TITLE issue; it belongs into the repair channel.'
        );

        $this->assertTrue(
            $this->issue_has_repair($issue),
            "Two-channel contract: the MISSING_TITLE issue must carry the technical retry "
            . "instructions in a 'repair' (or 'repair_hints') field instead of the user channel."
        );
    }

    /**
     * (b) Malformed prices (raw string instead of a category=>value map): the preflight issue's
     * user channel must not transport JSON-format specs; those belong into a repair channel.
     */
    public function test_malformed_prices_preflight_issue_keeps_format_specs_out_of_user_channel(): void {
        [$contextid, $userid] = $this->make_booking_context();

        $dto = (new create_option_skill())->preflight([
            'text' => 'Vikings evening class',
            'maxanswers' => 10,
            'prices' => '15 Euro pro Person',
        ], $contextid, $userid);

        $this->assertNotSame('pass', $dto->status, 'A raw-string prices value must not pass preflight.');
        $this->assertNotEmpty($dto->issues, 'The rejected prices input must surface as a structured issue.');

        $hasrepair = false;
        foreach ($dto->issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $usertext = $this->issue_user_channel_text($issue);
            $this->assertStringNotContainsStringIgnoringCase(
                'must be an object map',
                $usertext,
                'F3-W2 leak: the JSON-format instruction for "prices" sits in a USER-channel field '
                . '(user_question/usermessage/message) of issue ' . json_encode($issue['code'] ?? '')
                . '; format specs belong into the repair channel.'
            );
            $this->assertStringNotContainsString(
                '{"default"',
                $usertext,
                'F3-W2 leak: a literal JSON example sits in a USER-channel field of issue '
                . json_encode($issue['code'] ?? '') . '; it belongs into the repair channel.'
            );
            $hasrepair = $hasrepair || $this->issue_has_repair($issue);
        }

        $this->assertTrue(
            $hasrepair,
            "Two-channel contract: the malformed-prices rejection must carry the technical format "
            . "instruction in a 'repair' (or 'repair_hints') field on at least one issue."
        );
    }

    /**
     * Create a course with one booking instance and return [contextid, userid] for preflight.
     *
     * @return array Two ints: module context id and acting (admin) user id.
     */
    private function make_booking_context(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $booking = $gen->create_module('booking', ['course' => $course->id]);
        $context = context_module::instance($booking->cmid);

        return [(int)$context->id, (int)$USER->id];
    }

    /**
     * Normalize the 'repair' field of a check_structure result to a single string.
     *
     * @param array $result check_structure() return value.
     * @return string Empty string when no repair channel is present.
     */
    private function repair_channel_text(array $result): string {
        $repair = $result['repair'] ?? null;
        if (is_string($repair)) {
            return trim($repair);
        }
        if (is_array($repair)) {
            return trim(implode(' ', array_map('strval', $repair)));
        }
        return '';
    }

    /**
     * Concatenate the user-channel fields of a preflight issue.
     *
     * @param array $issue Structured issue array.
     * @return string
     */
    private function issue_user_channel_text(array $issue): string {
        $texts = [];
        foreach (['user_question', 'usermessage', 'message'] as $key) {
            $value = trim((string)($issue[$key] ?? ''));
            if ($value !== '') {
                $texts[] = $value;
            }
        }
        return implode(' ', $texts);
    }

    /**
     * Whether a preflight issue carries a non-empty repair channel.
     *
     * @param array $issue Structured issue array.
     * @return bool
     */
    private function issue_has_repair(array $issue): bool {
        foreach (['repair', 'repair_hints'] as $key) {
            $value = $issue[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if (is_array($value) && trim(implode(' ', array_map('strval', $value))) !== '') {
                return true;
            }
        }
        return false;
    }
}
