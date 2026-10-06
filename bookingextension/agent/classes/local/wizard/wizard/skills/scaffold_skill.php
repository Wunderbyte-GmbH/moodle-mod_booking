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

namespace bookingextension_agent\local\wizard\wizard\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\scaffold\skill_template_generator;

/**
 * Skill definition for wizard.scaffold_skill.
 *
 * Lets a third-party developer describe a skill in natural language and download a ready-to-drop,
 * heavily-commented skill template (as a ZIP) for their own plugin. It is read-only: it only
 * generates files for download and never writes into the codebase or touches data. By design it
 * fills the skill CONTRACT but leaves the actual programming (preflight/execute behaviour) to the
 * developer.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scaffold_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.scaffold_skill';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = [
            'version' => 1,
            'description' => 'Create / scaffold a NEW custom agent skill: generate a downloadable starter template for a new AI '
                . 'agent skill (action/command) in a third-party plugin. Use this when the user wants to BUILD or ADD their own '
                . 'skill, capability, command or action to the agent (e.g. "I want to create my own skill that ..."). Fills the '
                . 'skill contract (name, schema, risk class, capability, triggers) with guided comments; it does not implement the '
                . 'behaviour and does not run the new skill.',
            'is' => 'Authoring a new skill.',
            'not' => 'Using an existing skill.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'I want to create my own skill to see all users in a course',
                'How do I build a custom agent skill for my plugin?',
                'Scaffold a new skill that exports bookings to CSV',
                'Add a new command / action to the agent',
                'Generate a starter template for a new agent skill',
                'Create my own skill',
            ],
            'properties' => [
                // Wave 32 (A3): no sample component name here any more. The old text offered "mod/myplugin", and the
                // constructor copied it or a variant as the user's plugin when none was named (SCA-1 thread 10587
                // "mod/myplugin", SCA-2 threads 11376/12721 "local/myplugin") - a template for a plugin nobody has.
                'component' => [
                    'type' => 'string',
                    'description' => 'Component of the plugin the user named, written type/name or type_name. Never a '
                        . 'sample or made-up name. Drives namespace, capability and file paths.',
                    'required' => true,
                ],
                // Wave 32 (A3, SCA-4 thread 10581): the constructor asked what "Anwesenheit erfassen" should do although
                // the command's name was given. The template leaves the behaviour as TODO, so a name is enough.
                'description' => [
                    'type' => 'string',
                    'description' => 'What the new skill should do, in natural language. The name or short phrase the '
                        . 'user gave the command is enough; the template leaves behaviour as TODO.',
                    'required' => true,
                ],
                'is' => [
                    'type' => 'string',
                    'description' => 'Optional one short English clause naming what the new skill covers, '
                        . 'printed on the selector card as "IS:". Only when the user gave it.',
                    'required' => false,
                ],
                'not' => [
                    'type' => 'string',
                    'description' => 'Optional short English clause: what a neighbouring skill covers instead '
                        . '(skill in brackets), shown as "NOT:"; never in the description. Only if user-given.',
                    'required' => false,
                ],
                'example_utterances' => [
                    'type' => 'array',
                    'description' => 'Optional sentences a user would really say to reach the new skill. '
                        . 'Each one becomes its own semantic discovery anchor. Only the ones the user gave.',
                    'required' => false,
                ],
                'skillname' => [
                    'type' => 'string',
                    'description' => 'Optional desired skill name as "<namespace>.<action>" (lowercase), only '
                        . 'when the user gave one. If omitted, a name is derived from the description.',
                    'required' => false,
                ],
                'risk_class' => [
                    'type' => 'string',
                    'description' => 'Risk level: read_only, scoped_write, broad_write or '
                        . 'irreversible_or_external, only when the user stated it. Defaults to read_only.',
                    'required' => false,
                ],
                'properties' => [
                    'type' => 'array',
                    'description' => 'Input fields for the new skill, only those the user listed; each item '
                        . '{name, type, description, required}. Omit otherwise: the template has a placeholder.',
                    'required' => false,
                ],
                'capabilities' => [
                    'type' => 'array',
                    'description' => 'Optional native Moodle capabilities the action requires, e.g. '
                        . '["moodle/course:manageactivities"].',
                    'required' => false,
                ],
                'context_scopes' => [
                    'type' => 'array',
                    'description' => 'Context scopes the skill operates in, e.g. ["module","course","user"]. '
                        . 'Mandatory for broad_write/irreversible_or_external skills.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'intent' => 'Scaffold a downloadable starter template for a new third-party agent skill.',
                'input_fields_for_prompt' => ['component', 'description'],
                'anchor_fields' => ['description'],
                'context_scopes' => ['user'],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return deterministic example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'component' => 'mod/myplugin',
            'description' => 'Archive an item when the teacher asks for it.',
            'is' => 'Archiving an item that already exists.',
            'not' => 'Deleting an item for good (delete_item).',
            'example_utterances' => [
                'Archive the old reading list',
                'Put this item into the archive',
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],issue_codes:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $issuecodes = [];

        $component = trim((string)($input['component'] ?? ''));
        if ($component === '') {
            $errors[] = get_string('agent_scaffold_component_required', 'bookingextension_agent');
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        } else if (!self::is_valid_component($component)) {
            // Wave 32 (A3, SCA-2 thread 13042): "local-Plugin" was accepted and became the component
            // "local_plugin". A component is a known plugin type plus a valid plugin name - checked against
            // core_component, structurally, never by the words the user used.
            // No echo of the value: the error text reaches the model and the user unchanged, so it stays a
            // plain get_string without input (no escaping question, no masked value in a user-facing text).
            $errors[] = get_string('agent_scaffold_component_invalid', 'bookingextension_agent');
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        }
        if (trim((string)($input['description'] ?? '')) === '') {
            $errors[] = get_string('agent_scaffold_description_required', 'bookingextension_agent');
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * Whether a value is a plugin component: a known plugin type and a name Moodle accepts for that type.
     *
     * Accepts "type/name" (the form the generator uses) and the frankenstyle "type_name".
     *
     * @param string $component
     * @return bool
     */
    private static function is_valid_component(string $component): bool {
        $normalised = str_replace('\\', '/', \core_text::strtolower(trim($component)));
        if (strpos($normalised, '/') !== false) {
            [$type, $name] = explode('/', $normalised, 2);
        } else {
            [$type, $name] = \core_component::normalize_component($normalised);
        }
        return array_key_exists((string)$type, \core_component::get_plugin_types())
            && \core_component::is_valid_plugin_name((string)$type, (string)$name);
    }

    /**
     * Preflight: structural validation only; generation happens in execute().
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $structure = $this->check_structure($input);
        if (empty($structure['valid'])) {
            return $this->invalid($structure['errors']);
        }
        return $this->pass($input);
    }

    /**
     * Execute skill: generate the template bundle.
     *
     * @param array $preparedinput
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        try {
            $bundle = skill_template_generator::generate([
                'component' => (string)($preparedinput['component'] ?? ''),
                'description' => (string)($preparedinput['description'] ?? ''),
                'skillname' => (string)($preparedinput['skillname'] ?? ''),
                'risk_class' => (string)($preparedinput['risk_class'] ?? ''),
                'properties' => (array)($preparedinput['properties'] ?? []),
                'capabilities' => (array)($preparedinput['capabilities'] ?? []),
                'context_scopes' => (array)($preparedinput['context_scopes'] ?? []),
                'triggers' => (array)($preparedinput['triggers'] ?? []),
            ]);
        } catch (\invalid_parameter_exception $e) {
            return [
                'status' => 'error',
                'detail' => $e->getMessage(),
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            ];
        }

        $filelist = array_keys($bundle['files']);
        $detail = get_string('agent_scaffold_generated', 'bookingextension_agent', (object)[
            'skillname' => $bundle['skillname'],
            'count' => count($filelist),
        ]);

        $observation = '[SCAFFOLD] Generated skill template "' . $bundle['skillname'] . '" for component "'
            . (string)($preparedinput['component'] ?? '') . '". Files: ' . implode(', ', $filelist) . '. '
            . 'Capability: ' . $bundle['capability'] . '. The template is offered to the user as a ZIP '
            . 'download; behaviour (preflight/execute) is left as TODO for the developer.';

        return [
            'status' => 'executed',
            'detail' => $detail,
            'observation_full' => $observation,
            // Consumed by get_result_preview() only - kept out of the observation sent to the LLM.
            'scaffold_skillname' => $bundle['skillname'],
            'scaffold_relativepath' => $bundle['relativepath'],
            'scaffold_capability' => $bundle['capability'],
            'scaffold_files' => $filelist,
            'scaffold_warnings' => $bundle['warnings'],
            'scaffold_zip_filename' => $bundle['zip_filename'],
            'scaffold_zip_base64' => $bundle['zip_base64'],
        ];
    }

    /**
     * Rich preview: a download card for the generated ZIP plus the list of generated files.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $base64 = (string)($resultentry['scaffold_zip_base64'] ?? '');
        if ($base64 === '') {
            return null;
        }

        $filename = (string)($resultentry['scaffold_zip_filename'] ?? 'skill_template.zip');
        $skillname = (string)($resultentry['scaffold_skillname'] ?? '');
        $relativepath = (string)($resultentry['scaffold_relativepath'] ?? '');
        $files = (array)($resultentry['scaffold_files'] ?? []);
        $warnings = (array)($resultentry['scaffold_warnings'] ?? []);

        $fileitems = '';
        foreach ($files as $file) {
            $fileitems .= '<li><code>' . s((string)$file) . '</code></li>';
        }

        $warninghtml = '';
        if (!empty($warnings)) {
            $warninghtml = '<div class="alert alert-warning mt-2">';
            foreach ($warnings as $warning) {
                $warninghtml .= '<div>' . s((string)$warning) . '</div>';
            }
            $warninghtml .= '</div>';
        }

        // Data-URI download: the preview HTML is injected client-side (no server purifier), so the
        // browser downloads the ZIP directly with no extra plumbing.
        $href = 'data:application/zip;base64,' . $base64;

        $html = '<div class="p-3">'
            . '<h5><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> '
            . get_string('agent_scaffold_preview_heading', 'bookingextension_agent') . '</h5>'
            . ($skillname !== '' ? '<p class="mb-1"><strong>' . s($skillname) . '</strong></p>' : '')
            . ($relativepath !== '' ? '<p class="text-muted small mb-2"><code>' . s($relativepath) . '</code></p>' : '')
            . '<ul class="small">' . $fileitems . '</ul>'
            . $warninghtml
            . '<a class="btn btn-primary mt-2" download="' . s($filename) . '" href="' . $href . '">'
            . '<i class="fa-solid fa-download" aria-hidden="true"></i> '
            . get_string('agent_scaffold_download', 'bookingextension_agent') . '</a>'
            . '</div>';

        return [
            'type' => 'skill_scaffold',
            'html' => $html,
        ];
    }

    /**
     * Fields that must be omitted from executed_input result echoes (none are sensitive here).
     *
     * @return string[]
     */
    public function get_sensitive_input_fields(): array {
        return [];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.scaffold_skill_request',
                'description' => 'Developer wants a starter template for a new agent skill.',
                'examples' => [
                    'I want to build my own skill for the agent',
                    'give me a template for a custom skill',
                    'scaffold a skill template for my plugin',
                    'how do I write my own skill, give me a template',
                ],
            ],
        ];
    }
}
