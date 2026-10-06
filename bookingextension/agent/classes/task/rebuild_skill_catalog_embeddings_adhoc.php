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
 * Adhoc task to rebuild the skill-catalog embeddings index.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\services\embeddings\family_embeddings_index_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Rebuilds embeddings for the full skill catalog.
 */
class rebuild_skill_catalog_embeddings_adhoc extends \core\task\adhoc_task {
    /**
     * Execute task.
     *
     * @return void
     */
    public function execute(): void {
        if (!class_exists(\bookingextension_agent\local\wizard\wb_action_names::GENERATE_EMBEDDINGS)) {
            return;
        }

        $customdata = (array)$this->get_custom_data();
        $registry = skill_registry_factory::get_default();
        $service = new family_embeddings_index_service();
        $summary = $service->rebuild_catalog(
            $registry,
            isset($customdata['model']) ? (string)$customdata['model'] : null,
            isset($customdata['dimensions']) ? (int)$customdata['dimensions'] : null,
            !empty($customdata['force'])
        );

        mtrace('bookingextension_agent embeddings rebuild status: ' . (string)($summary['status'] ?? 'unknown'));
        mtrace('bookingextension_agent embeddings rebuild: generated=' . (int)($summary['embedded'] ?? 0)
            . ', reused=' . (int)($summary['reused'] ?? 0)
            . ', deleted=' . (int)($summary['deleted'] ?? 0)
            . ', written=' . (int)($summary['written'] ?? 0));

        $skillstates = (array)($summary['skillstates'] ?? []);
        if (!empty($skillstates)) {
            $statecounts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'untouched' => 0];
            foreach ($skillstates as $state) {
                if (isset($statecounts[$state])) {
                    $statecounts[$state]++;
                }
            }
            mtrace('bookingextension_agent embeddings rebuild states summary: '
                . 'created=' . $statecounts['created']
                . ', updated=' . $statecounts['updated']
                . ', deleted=' . $statecounts['deleted']
                . ', untouched=' . $statecounts['untouched']);
            ksort($skillstates);
            mtrace('bookingextension_agent embeddings rebuild skill states:');
            foreach ($skillstates as $skillname => $state) {
                mtrace(' - ' . $state . ' ' . $skillname);
            }
        }

        // Sanity check: the rebuild must leave a complete, valid catalog. The embeddings store
        // already publishes atomically (generation swap), but we additionally re-evaluate the
        // canonical readiness status here, which also catches missing skills and content-hash drift.
        // Failing the task on a not-ready result lets Moodle's scheduler apply faildelay backoff
        // instead of looping expensive embeddings rebuilds on a persistent defect.
        $settings = (new embeddings_action_config_resolver())->resolve();
        $status = (new embeddings_readiness_service())->get_catalog_status(
            $registry,
            isset($customdata['model']) ? (string)$customdata['model'] : (string)$settings['model'],
            isset($customdata['dimensions']) ? (int)$customdata['dimensions'] : (int)$settings['dimensions']
        );
        if (empty($status['ready'])) {
            throw new \moodle_exception(
                'embeddingscatalogrebuildfailed',
                'bookingextension_agent',
                '',
                (string)($status['status'] ?? 'unknown')
            );
        }

        mtrace('bookingextension_agent embeddings rebuild: catalog verified ready.');

        // Refresh the persisted collision analysis while the catalog is hot: the O(N²) pairwise
        // cosine pass only changes when the embeddings change, so it runs here (and on the debug
        // page's explicit recompute) instead of on every governance page load. Never fail the
        // task over a diagnostics computation.
        try {
            $collisions = (new skill_selection_debug_service())->compute_and_cache_collisions(250);
            mtrace('bookingextension_agent embeddings rebuild: collision analysis cached ('
                . count((array)($collisions['pairs'] ?? [])) . ' pairs).');
        } catch (\Throwable $e) {
            mtrace('bookingextension_agent embeddings rebuild: collision analysis skipped: ' . $e->getMessage());
        }
    }
}
