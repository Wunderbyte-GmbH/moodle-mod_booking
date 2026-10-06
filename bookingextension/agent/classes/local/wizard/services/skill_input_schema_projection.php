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

/**
 * Readable projection of a skill's input schema for the construction phase.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Turn the input schema of ONE skill into compact field lines for the constructor prompt.
 *
 * The construction phase knows which skill it builds for, so the fields of exactly that skill can travel
 * with the prompt. The raw schema is not used for this: it carries validation internals the model does not
 * need, and a wide schema would crowd out the request itself. What the constructor is missing is narrow —
 * which fields exist, what type they are, whether they are required, and which values are allowed.
 */
class skill_input_schema_projection {
    /** Character budget for the whole projection. Roughly 1000 tokens. */
    public const MAX_CHARS = 4000;

    /** Cut-off for a single field description. */
    private const MAX_DESCRIPTION_CHARS = 160;

    /**
     * Project the input fields of a skill schema into one line per field.
     *
     * Required fields come first and are never dropped: they are what the constructor must fill to
     * produce a command at all. Optional fields follow in declared order until the budget is spent;
     * the number of omitted fields is stated so the model knows the list was shortened.
     *
     * @param array $schema Skill schema as returned by skill_interface::get_schema().
     * @param array $requesterflags Person field => description of its yes/no requester companion (#2569); the
     *     companion line follows its field and is kept or dropped together with it.
     * @return string[] Field lines, empty when the schema declares no input.
     */
    public static function project(array $schema, array $requesterflags = []): array {
        $properties = (array)($schema['properties'] ?? ($schema['input']['properties'] ?? []));
        if (empty($properties)) {
            return [];
        }

        // Each entry is a group: the field line plus, for a declared person field, its yes/no requester companion
        // (#2569). A group is listed or left out as a whole.
        $required = [];
        $optional = [];
        foreach ($properties as $name => $definition) {
            $name = trim((string)$name);
            if ($name === '' || !is_array($definition)) {
                continue;
            }
            $group = [self::field_line($name, $definition)];
            if (array_key_exists($name, $requesterflags)) {
                $group[] = requester_reference::flag_name($name) . ' (boolean, optional): ' . $requesterflags[$name];
            }
            if (!empty($definition['required'])) {
                $required[] = $group;
            } else {
                $optional[] = $group;
            }
        }

        if (empty($required) && empty($optional)) {
            return [];
        }

        $lines = array_merge([], ...$required);
        $requiredcount = count($lines);
        $used = self::length_of($lines);
        $omitted = 0;
        foreach ($optional as $group) {
            $size = self::length_of($group);
            if ($used + $size > self::MAX_CHARS) {
                $omitted++;
                continue;
            }
            array_push($lines, ...$group);
            $used += $size;
        }

        if ($omitted > 0) {
            // The note itself has to fit: optional lines give way for it, required ones never do.
            $note = $omitted . ' further optional fields exist and are not listed here. '
                . 'Ask the user only for what the request itself leaves open.';
            while (count($lines) > $requiredcount && self::length_of($lines) + strlen($note) + 1 > self::MAX_CHARS) {
                array_pop($lines);
                $omitted++;
                $note = $omitted . ' further optional fields exist and are not listed here. '
                    . 'Ask the user only for what the request itself leaves open.';
            }
            $lines[] = $note;
        }

        return $lines;
    }

    /**
     * Project the input fields of a skill object.
     *
     * A skill declares the person fields whose empty value does not mean the requester through the duck-typed
     * get_requester_flag_fields(); each gets a yes/no companion line right after it (#2569).
     *
     * @param object $skill Any skill exposing get_schema().
     * @return string[] Field lines, empty when the skill declares no input or has no schema.
     */
    public static function for_skill(object $skill): array {
        if (!method_exists($skill, 'get_schema')) {
            return [];
        }
        return self::project((array)$skill->get_schema(), requester_reference::flag_fields($skill));
    }

    /**
     * Build one field line: name, type, requiredness, allowed values, purpose.
     *
     * @param string $name
     * @param array $definition
     * @return string
     */
    private static function field_line(string $name, array $definition): string {
        $type = trim((string)($definition['type'] ?? ''));
        $facts = [$type !== '' ? $type : 'value'];
        $facts[] = !empty($definition['required']) ? 'required' : 'optional';

        $allowed = array_values(array_filter(array_map('strval', (array)($definition['enum'] ?? [])), 'strlen'));
        if (!empty($allowed)) {
            $facts[] = 'one of: ' . implode(', ', $allowed);
        }

        $line = $name . ' (' . implode(', ', $facts) . ')';

        $description = trim((string)($definition['description'] ?? ''));
        if ($description !== '') {
            $line .= ': ' . self::shorten($description);
        }

        return $line;
    }

    /**
     * Shorten a field description to its first sentence within the per-field cut-off.
     *
     * @param string $description
     * @return string
     */
    private static function shorten(string $description): string {
        $description = (string)preg_replace('/\s+/u', ' ', $description);
        if (\core_text::strlen($description) <= self::MAX_DESCRIPTION_CHARS) {
            return $description;
        }

        return rtrim(\core_text::substr($description, 0, self::MAX_DESCRIPTION_CHARS - 1)) . '…';
    }

    /**
     * Total length of the lines including the newline that will join them.
     *
     * @param string[] $lines
     * @return int
     */
    private static function length_of(array $lines): int {
        $total = 0;
        foreach ($lines as $line) {
            $total += strlen((string)$line) + 1;
        }

        return $total;
    }
}
