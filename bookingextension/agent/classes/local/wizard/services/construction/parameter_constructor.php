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
use bookingextension_agent\local\wizard\services\input_payload_pruner;
use bookingextension_agent\local\wizard\services\requester_reference;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Build normalized parameter payloads after concrete skill selection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parameter_constructor {
    /** @var skill_registry */
    private skill_registry $registry;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     */
    public function __construct(skill_registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Build canonical input for one selected skill.
     *
     * @param string $skillname
     * @param array $rawinput
     * @param string $lastusermessage
     * @return parameter_construction_result
     */
    public function build(string $skillname, array $rawinput, string $lastusermessage = ''): parameter_construction_result {
        // Schema/hook-driven only — no domain field names live here. Provider-owned
        // skill_input_normalizers handle domain coercion (e.g. booking timestamp / self-reference
        // fields); free-text hydration is driven by the schema `from_user_message` flag (audit 05-F01).
        // #2569: the yes/no companion of a person field becomes the requester marker before anything else reads the
        // input, so no normalizer or structural check ever sees a key the skill does not declare.
        $rawinput = requester_reference::apply_flags($this->registry->get_skill($skillname), $rawinput);
        $input = $this->canonicalize_command_input($skillname, $rawinput);
        $input = $this->hydrate_user_message_fields($skillname, $input, $lastusermessage);
        $input = input_payload_pruner::prune($input);

        return new parameter_construction_result($input, true, [], []);
    }

    /**
     * Canonicalize skill input through registry-owned normalizers.
     *
     * @param string $skillname
     * @param array $input
     * @return array
     */
    private function canonicalize_command_input(string $skillname, array $input): array {
        $input = $this->registry->normalize_skill_input($skillname, $input);

        foreach ($input as $key => $value) {
            if (is_array($value) && count($value) === 0) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /**
     * Hydrate any schema property flagged `from_user_message` with the last user message when the
     * planner left it empty. Schema-driven: the engine names no domain field (audit 05-F01).
     *
     * @param string $skillname
     * @param array $input
     * @param string $lastusermessage
     * @return array
     */
    private function hydrate_user_message_fields(string $skillname, array $input, string $lastusermessage): array {
        if ($lastusermessage === '') {
            return $input;
        }

        $skill = $this->registry->get_skill($skillname);
        if ($skill === null) {
            return $input;
        }

        $props = $skill->get_schema()['properties'] ?? [];
        foreach ($props as $field => $spec) {
            if (!is_array($spec) || empty($spec['from_user_message'])) {
                continue;
            }
            if (trim((string)($input[$field] ?? '')) === '') {
                $input[$field] = $lastusermessage;
            }
        }

        return $input;
    }
}
