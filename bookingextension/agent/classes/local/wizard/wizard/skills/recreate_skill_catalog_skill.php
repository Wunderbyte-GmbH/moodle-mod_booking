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
use core\task\manager;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\task\rebuild_skill_catalog_embeddings_adhoc;
use bookingextension_agent\local\wizard\services\preview_support;

/**
 * Skill definition for wizard.recreate_skill_catalog.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recreate_skill_catalog_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.recreate_skill_catalog';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
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
     * Human-readable preview of the catalog rebuild (tier-3).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = preview_support::lang($input);
        $rows = [];
        if (preview_support::truthy($input['force'] ?? null)) {
            $yes = preview_support::str('yes', $lang, null, 'core');
            preview_support::push($rows, preview_support::str('previewlabel_force', $lang), $yes);
        }
        $model = preview_support::text($input['model'] ?? null);
        preview_support::push($rows, preview_support::str('previewlabel_model', $lang), $model);
        return [
            'title' => preview_support::str('previewtitle_recreatecatalog', $lang),
            'summary' => preview_support::str('previewsummary_recreatecatalog', $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = [
            'version' => 1,
            'description' => 'Recreate the embeddings skill catalog CSV used for vector skill retrieval.'
                . ' Queues an adhoc rebuild job and can be used when the catalog is stale or missing.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_recreate_skill_catalog',
            'fallback_skillcall_string_key' => 'ai_status_skillcall_booking_recreate_skill_catalog',
            'properties' => [
                'force' => [
                    'type' => 'boolean',
                    'description' => 'If true, force regeneration for all skill embeddings '
                        . '(skip incremental reuse). '
                        . 'Don\'t set if we talk of update '
                        . 'or newly added skills only.',
                    'required' => false,
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional embeddings model override for this rebuild run.',
                    'required' => false,
                ],
                'dimensions' => [
                    'type' => 'integer',
                    'description' => 'Optional embedding dimensions override (> 0).',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
                // Rebuilding the global skill-catalog embeddings CSV is a site-wide action,
                // not module-scoped — declare the real blast radius (audit 12-F02).
                'context_scopes' => ['system'],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'force' => true,
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.recreate_skill_catalog_requested',
                'description' => 'User asks to rebuild/recreate skill catalog embeddings.',
            ],
            [
                'id' => 'booking.recrate_skill_catalog_requested',
                'description' => 'User asks with typo: "recrate the skill catalog".',
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];

        if (isset($input['dimensions'])) {
            $dimensions = (int)$input['dimensions'];
            if ($dimensions < 1) {
                $errors[] = get_string('agent_booking_recreate_skill_catalog_invalid_dimensions', 'bookingextension_agent');
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $force = !empty($input['force']);
        $model = trim((string)($input['model'] ?? ''));
        $dimensions = isset($input['dimensions']) ? (int)$input['dimensions'] : 0;

        $customdata = [];
        if ($force) {
            $customdata['force'] = true;
        }
        if ($model !== '') {
            $customdata['model'] = $model;
        }
        if ($dimensions > 0) {
            $customdata['dimensions'] = $dimensions;
        }

        $task = new rebuild_skill_catalog_embeddings_adhoc();
        if (!empty($customdata)) {
            $task->set_custom_data($customdata);
        }

        manager::reschedule_or_queue_adhoc_task($task);

        return [
            'status' => 'executed',
            'detail' => get_string('agent_booking_recreate_skill_catalog_queued', 'bookingextension_agent'),
            'resultid' => null,
            'queued_task_class' => rebuild_skill_catalog_embeddings_adhoc::class,
            'force' => $force,
            'model' => $model,
            'dimensions' => $dimensions > 0 ? $dimensions : null,
        ];
    }
}
