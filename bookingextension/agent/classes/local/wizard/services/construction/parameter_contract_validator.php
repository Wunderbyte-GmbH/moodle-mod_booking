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

namespace bookingextension_agent\local\wizard\services\construction;

use bookingextension_agent\local\wizard\dto\parameter_construction_result;
use bookingextension_agent\local\wizard\interfaces\skill_interface;

/**
 * Structural parameter contract validator for one selected skill.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parameter_contract_validator {
    /**
     * Validate canonical input against skill structural contract.
     *
     * @param skill_interface $skill
     * @param array $input
     * @param string $label
     * @return parameter_construction_result
     */
    public function validate(skill_interface $skill, array $input, string $label): parameter_construction_result {
        // Engine-level key contract (#2364): the planner's input is untrusted; keys are checked
        // against the skill's declared schema properties BEFORE the skill's own check_structure().
        // Spelling variants of a declared key are canonicalized, unknown keys are rejected with a
        // repair hint (one construction retry via CONTRACT_STRUCTURAL_MISMATCH) instead of being
        // dropped silently by the skill.
        $keycheck = self::check_input_keys($skill, $input);
        $input = $keycheck['input'];
        if (!empty($keycheck['unknown'])) {
            $supported = $keycheck['supported'];
            // F3 two-channel contract: the key names are planner vocabulary and travel on the
            // repair channel only (framework retry observation). The error is a label-free user
            // cause without any key or skill name — the synchronizer relays errors to the user
            // (Lauf 8, threads 1427/1438: the supported-property list reached the chat).
            return new parameter_construction_result(
                $input,
                false,
                [self::USER_CAUSE_UNKNOWN_INPUT],
                [self::ISSUE_UNKNOWN_INPUT_PROPERTY],
                [$label . ': unknown input properties ' . implode(', ', $keycheck['unknown'])
                    . '; use only the supported property names of ' . $skill->get_name()
                    . ' (' . (empty($supported) ? '(none)' : implode(', ', $supported)) . ').']
            );
        }

        $structural = $skill->check_structure($input);
        if (($structural['valid'] ?? true) === true) {
            return new parameter_construction_result($input, true, [], []);
        }

        // F3 two-channel cause contract: a skill that supplies 'repair' guarantees its
        // 'errors' are user_cause texts — those stay label-free (the "Command #N:" label is
        // planner orientation, i.e. repair vocabulary). Legacy skills without the key keep
        // the historical labelled/mixed behaviour until they migrate.
        $migrated = array_key_exists('repair', (array)$structural);

        $errors = [];
        foreach ((array)($structural['errors'] ?? []) as $error) {
            $errors[] = $migrated ? (string)$error : $label . ': ' . $error;
        }

        $repair = [];
        foreach ((array)($structural['repair'] ?? []) as $hint) {
            $hint = trim((string)$hint);
            if ($hint !== '') {
                $repair[] = $label . ': ' . $hint;
            }
        }

        $issuecodes = [];
        foreach ((array)($structural['issue_codes'] ?? []) as $issuecode) {
            $code = trim((string)$issuecode);
            if ($code !== '') {
                $issuecodes[] = $code;
            }
        }

        return new parameter_construction_result($input, false, $errors, $issuecodes, $repair);
    }

    /**
     * User cause for rejected input keys: plain English LLM material for the synchronizer (F3: never a
     * get_string rendering, the synchronizer formulates in the user's language); names no key or skill.
     */
    public const USER_CAUSE_UNKNOWN_INPUT = 'The request contained details this step cannot use, so nothing was done.';

    /** Issue code: the planner used input keys the skill schema does not declare (#2364). */
    public const ISSUE_UNKNOWN_INPUT_PROPERTY = 'UNKNOWN_INPUT_PROPERTY';

    /** @var string[] Input keys the engine itself manages; never part of a skill schema. */
    private const ENGINE_INPUT_KEYS = ['outputlang'];

    /**
     * Canonicalize input keys against the skill schema and collect the unknown ones.
     *
     * A key that equals exactly one declared property once case, underscores and hyphens are
     * ignored is renamed to that property (unless the property is already set). Schemas without
     * declared properties or with additionalProperties = true are not checked; only the top level
     * is inspected (nested shapes stay the skill's business).
     *
     * @param skill_interface $skill
     * @param array $input
     * @return array{input:array,unknown:string[],supported:string[]}
     */
    public static function check_input_keys(skill_interface $skill, array $input): array {
        $schema = (array)$skill->get_schema();
        $properties = array_values(array_map('strval', array_keys((array)($schema['properties'] ?? []))));
        sort($properties);
        if (empty($properties) || ($schema['additionalProperties'] ?? false) === true) {
            return ['input' => $input, 'unknown' => [], 'supported' => $properties];
        }

        $bystripped = [];
        foreach ($properties as $property) {
            $bystripped[self::strip_key($property)][] = $property;
        }
        $unknown = [];
        foreach ($input as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $properties, true) || in_array($key, self::ENGINE_INPUT_KEYS, true)) {
                continue;
            }
            $targets = $bystripped[self::strip_key($key)] ?? [];
            if (count($targets) === 1) {
                $target = $targets[0];
                if (!array_key_exists($target, $input)) {
                    $input[$target] = $value;
                }
                unset($input[$key]);
                continue;
            }
            $unknown[] = $key;
        }
        return ['input' => $input, 'unknown' => $unknown, 'supported' => $properties];
    }

    /**
     * Comparison form of a key: lower-case without underscores and hyphens.
     *
     * @param string $key
     * @return string
     */
    private static function strip_key(string $key): string {
        return str_replace(['_', '-'], '', \core_text::strtolower($key));
    }
}
