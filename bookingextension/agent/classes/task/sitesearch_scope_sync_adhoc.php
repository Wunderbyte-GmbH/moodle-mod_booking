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
 * Adhoc task: targeted backfill/prune sync after a site-search scope rule change.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;

/**
 * Executes the DELTA SYNC of the context-scoped site-search governance (context-governance
 * blueprint §4.1): queued IMMEDIATELY by the scope repository's mutation chokepoint whenever a
 * rule change alters an area's allowed course coverage — never a site rebuild.
 *
 * Customdata: {area: string, backfill: int[], prune: int[]}. Backfill courses (newly allowed, or
 * effective includefiles flipped) run a context-scoped recordset from cursor 0 through the NORMAL
 * indexing pipeline ({@see site_content_index_service::update_course()} — `replace_document()` is
 * idempotent/diff-based, so a files-flag change corrects each document's chunk set by itself).
 * Prune courses (no longer allowed) get their area rows deleted via the course-scoped store op
 * ({@see site_content_index_service::prune_course()}).
 *
 * Guards like the scheduled indexing task (is_ready: DB backend, provider, Moodle 5) — on a
 * not-ready site the queued task degrades to a traced no-op. Per-course failures NEVER abort the
 * task: they are collected and traced, the remaining courses still get synchronized (the hourly
 * incremental task and check-on-read gates cover any remainder).
 */
class sitesearch_scope_sync_adhoc extends \core\task\adhoc_task {
    /**
     * The index service used for backfill/prune — a seam so tests can inject a fake embedder
     * through a subclass, mirroring the service's own injectable-embedder pattern.
     *
     * @return site_content_index_service
     */
    protected function create_index_service(): site_content_index_service {
        return new site_content_index_service();
    }

    /**
     * Run the backfill/prune sync described by the customdata.
     *
     * @return void
     */
    public function execute(): void {
        $data = (array)$this->get_custom_data();
        $area = isset($data['area']) ? (string)$data['area'] : '';
        $backfill = array_map('intval', (array)($data['backfill'] ?? []));
        $prune = array_map('intval', (array)($data['prune'] ?? []));

        if ($area === '' || ($backfill === [] && $prune === [])) {
            mtrace('bookingextension_agent sitesearch scope sync: nothing to do (empty customdata)');
            return;
        }

        $service = $this->create_index_service();
        $ready = $service->is_ready();
        if (!$ready['ready']) {
            mtrace('bookingextension_agent sitesearch scope sync: skipped (' . $ready['reason'] . ')');
            return;
        }

        $backfilled = 0;
        $pruned = 0;
        $failures = [];

        foreach ($backfill as $courseid) {
            try {
                $stats = $service->update_course($area, $courseid);
                if (($stats['status'] ?? '') === 'ok') {
                    $backfilled++;
                } else {
                    $failures[] = 'backfill ' . $courseid . ': ' . (string)($stats['reason'] ?? $stats['status']);
                }
            } catch (\Throwable $e) {
                // Never abort on a per-course failure: collect, trace, continue.
                $failures[] = 'backfill ' . $courseid . ': ' . $e->getMessage();
            }
        }

        foreach ($prune as $courseid) {
            try {
                $service->prune_course($area, $courseid);
                $pruned++;
            } catch (\Throwable $e) {
                $failures[] = 'prune ' . $courseid . ': ' . $e->getMessage();
            }
        }

        mtrace('bookingextension_agent sitesearch scope sync (' . $area . '):'
            . ' backfilled=' . $backfilled . '/' . count($backfill)
            . ', pruned=' . $pruned . '/' . count($prune)
            . ', failures=' . count($failures));
        foreach ($failures as $failure) {
            mtrace('  - ' . $failure);
        }
    }
}
