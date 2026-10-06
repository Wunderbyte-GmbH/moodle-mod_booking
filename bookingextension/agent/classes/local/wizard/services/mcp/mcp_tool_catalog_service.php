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
 * MCP tool catalog: maps agent skills to MCP tool definitions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\mcp;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Builds the MCP tool list from the skill registry.
 *
 * Skills self-describe via get_name()/get_schema()/is_read_only()/get_risk_class();
 * this service only translates that contract into the MCP tool-definition shape
 * (name, description, inputSchema, annotations) and applies the exposure policy.
 * It deliberately contains no per-skill knowledge — a newly registered skill
 * appears as an MCP tool without any change here.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_tool_catalog_service {
    /**
     * Skills never exposed over MCP unless an admin explicitly allowlists them:
     * the wizard.* meta/memory skills solve problems of the internal chat engine
     * (prompt budget, thread memory), and core.search_users returns PII that
     * would flow to the external model provider as a tool result.
     */
    private const DEFAULT_EXCLUDED_SKILLS = [
        'core.search_users',
        'wizard.forget',
        'wizard.list_memories',
        'wizard.list_skills',
        'wizard.recall_memory',
        'wizard.recreate_skill_catalog',
        'wizard.remember',
        'wizard.search_skills',
    ];

    /** @var string Name of the synthetic confirm tool (step 2 of the mutation flow). */
    public const CONFIRM_TOOL_NAME = 'confirm_pending_action';

    /** @var string[] JSON Schema types we pass through verbatim. */
    private const JSON_SCHEMA_TYPES = ['string', 'integer', 'number', 'boolean', 'object', 'array'];

    /** @var int Maximum example utterances appended to a tool description. */
    private const MAX_EXAMPLES = 3;

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var skill_executability_evaluator */
    private skill_executability_evaluator $evaluator;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param skill_executability_evaluator $evaluator
     */
    public function __construct(skill_registry $registry, skill_executability_evaluator $evaluator) {
        $this->registry = $registry;
        $this->evaluator = $evaluator;
    }

    /**
     * Build MCP tool definitions for every exposed skill the user can execute here.
     *
     * @param int $userid
     * @param int $contextid
     * @return array MCP tool definitions (name, description, inputSchema, annotations).
     */
    public function get_tools(int $userid, int $contextid): array {
        $tools = [];
        $seennames = [];
        $allowmutations = (bool)get_config('bookingextension_agent', 'mcpallowmutations');

        foreach ($this->registry->get_skill_names() as $skillname) {
            if (!$this->is_exposed($skillname)) {
                continue;
            }

            // While mutations are disabled, mutating tools are not just refused on call —
            // they are hidden from the list, so the client never sees tools it cannot run.
            if (!$allowmutations && !$this->registry->is_read_only_skill($skillname)) {
                continue;
            }

            // A skill may declare itself unavailable on this instance (e.g. its remote backend
            // is not configured) via an optional is_available(). Duck-typed like
            // get_result_preview so this service keeps no per-skill knowledge: unavailable
            // skills are hidden from the list instead of preflight-blocking on every call.
            $skill = $this->registry->get_skill($skillname);
            if ($skill !== null && method_exists($skill, 'is_available') && !$skill->is_available()) {
                continue;
            }

            $evaluation = $this->evaluator->evaluate_skill($skillname, $userid, $contextid);
            if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
                continue;
            }

            $toolname = self::tool_name_for($skillname);
            if (isset($seennames[$toolname])) {
                // Two skills collapsing onto one MCP name would silently shadow each
                // other — skip the later one loudly instead.
                debugging('wizard mcp: tool name collision for ' . $skillname . ' -> ' . $toolname, DEBUG_DEVELOPER);
                continue;
            }
            $seennames[$toolname] = true;

            $tools[] = $this->build_tool_definition($skillname, $toolname);
        }

        if ($allowmutations) {
            // Synthetic step-2 tool of the mutation flow, injected server-side so every
            // transport (stdio bridge, future HTTP endpoint) gets it without own logic.
            $tools[] = $this->build_confirm_tool_definition();
        }

        return $tools;
    }

    /**
     * Build the synthetic confirm tool definition (no backing skill).
     *
     * @return array
     */
    private function build_confirm_tool_definition(): array {
        $properties = new \stdClass();
        $properties->queueitemid = [
            'type' => 'string',
            'description' => 'The queueitemid from the pending tool result.',
        ];
        $properties->confirmationcode = [
            'type' => 'string',
            'description' => 'The confirmationcode from the pending tool result.',
        ];

        return [
            'name' => self::CONFIRM_TOOL_NAME,
            'description' => 'Execute a previously previewed mutating action. Mutating tools do not execute '
                . 'directly: they return a preview with a queueitemid and a confirmationcode. Show the preview '
                . 'to the user, and ONLY after the user explicitly agrees call this tool with both values. '
                . 'The pending action expires after a few minutes.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'additionalProperties' => false,
                'required' => ['queueitemid', 'confirmationcode'],
            ],
            'annotations' => [
                'title' => 'Confirm pending action',
                'readOnlyHint' => false,
                'destructiveHint' => true,
            ],
        ];
    }

    /**
     * Whether a skill is exposed on the MCP surface.
     *
     * With the mcpexposedskills config set (comma-separated skill names, edited on
     * the governance page) that explicit allowlist is authoritative. Without it,
     * the default policy applies: every skill except the built-in exclusions.
     * Executability (activation, licence, capabilities) is enforced separately.
     *
     * @param string $skillname
     * @return bool
     */
    public function is_exposed(string $skillname): bool {
        // The skill's own opt-out wins over every admin policy: a skill that declares itself
        // chat-only is never exposed, allowlist or not.
        $skill = $this->registry->get_skill($skillname);
        if ($skill !== null && method_exists($skill, 'is_mcp_exposable') && !$skill->is_mcp_exposable()) {
            return false;
        }

        $configured = get_config('bookingextension_agent', 'mcpexposedskills');
        if ($configured !== false) {
            // Set = authoritative, including the explicitly empty list (nothing exposed).
            // Only an UNSET config falls back to the default policy below.
            $allowlist = array_filter(array_map('trim', explode(',', (string)$configured)));
            return in_array($skillname, $allowlist, true);
        }

        return !in_array($skillname, self::DEFAULT_EXCLUDED_SKILLS, true);
    }

    /**
     * Map a canonical skill name to an MCP tool name.
     *
     * Claude restricts tool names to [a-zA-Z0-9_-], so the namespace dot becomes
     * an underscore ("course.search_courses" -> "course_search_courses").
     *
     * @param string $skillname
     * @return string
     */
    public static function tool_name_for(string $skillname): string {
        return str_replace('.', '_', trim($skillname));
    }

    /**
     * Resolve an MCP tool name back to the canonical skill name.
     *
     * Accepts the underscore form and, for robustness, the canonical dotted name.
     * Only exposed skills resolve — hiding a skill from tools/list also blocks
     * calling it by guessed name.
     *
     * @param string $toolname
     * @return string|null Canonical skill name, or null when unknown/not exposed.
     */
    public function skill_for_tool_name(string $toolname): ?string {
        $toolname = trim($toolname);
        if ($toolname === '') {
            return null;
        }

        foreach ($this->registry->get_skill_names() as $skillname) {
            if ($toolname !== $skillname && $toolname !== self::tool_name_for($skillname)) {
                continue;
            }
            return $this->is_exposed($skillname) ? $skillname : null;
        }

        return null;
    }

    /**
     * Build one MCP tool definition from the skill's self-description.
     *
     * @param string $skillname
     * @param string $toolname
     * @return array
     */
    private function build_tool_definition(string $skillname, string $toolname): array {
        $skill = $this->registry->get_skill($skillname);
        $schema = (array)$skill->get_schema();

        $description = trim((string)($schema['description'] ?? ''));
        $examples = array_slice((array)($schema['example_utterances'] ?? []), 0, self::MAX_EXAMPLES);
        $examples = array_filter(array_map('strval', $examples));
        if (!empty($examples)) {
            $description .= ' Examples: "' . implode('", "', $examples) . '".';
        }

        $riskclass = (string)$skill->get_risk_class();
        $destructive = in_array($riskclass, [skill_risk_class::R2, skill_risk_class::R3], true);

        return [
            'name' => $toolname,
            'description' => $description,
            'inputSchema' => $this->build_input_schema((array)($schema['properties'] ?? [])),
            'annotations' => [
                'title' => $skillname,
                'readOnlyHint' => $skill->is_read_only(),
                'destructiveHint' => $destructive,
            ],
        ];
    }

    /**
     * Convert the skill's properties map to a JSON Schema object.
     *
     * The skill schema carries per-property 'required' booleans; JSON Schema wants
     * a top-level 'required' array. Finer constraints stay where they live today —
     * in check_structure()/preflight() — so the schema advertises shape, not rules.
     *
     * @param array $properties
     * @return array
     */
    private function build_input_schema(array $properties): array {
        $schemaproperties = new \stdClass();
        $required = [];

        foreach ($properties as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }
            $type = (string)($definition['type'] ?? 'string');
            if (!in_array($type, self::JSON_SCHEMA_TYPES, true)) {
                $type = 'string';
            }
            $property = ['type' => $type];
            $propertydescription = trim((string)($definition['description'] ?? ''));
            if ($propertydescription !== '') {
                $property['description'] = $propertydescription;
            }
            $schemaproperties->{$name} = $property;
            if (!empty($definition['required'])) {
                $required[] = $name;
            }
        }

        $inputschema = [
            'type' => 'object',
            // An stdClass so an empty properties map serialises as {} instead of [].
            'properties' => $schemaproperties,
            'additionalProperties' => false,
        ];
        if (!empty($required)) {
            $inputschema['required'] = $required;
        }

        return $inputschema;
    }
}
