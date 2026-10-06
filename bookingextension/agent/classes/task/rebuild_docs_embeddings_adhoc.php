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
 * Adhoc task to rebuild documentation chunk embeddings CSV.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_gate;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service;

/**
 * Rebuilds embeddings for the registered documentation corpora.
 */
class rebuild_docs_embeddings_adhoc extends \core\task\adhoc_task {
    /**
     * Execute task.
     *
     * @return void
     */
    public function execute(): void {
        // E2 gate: opt out before doing any work, in case the task was queued before the docs skill
        // was disabled (legacy queue / manual enqueue / CLI). No embedding call is made.
        if (!docs_embeddings_gate::is_docs_skill_active()) {
            mtrace('bookingextension_agent docs embeddings rebuild: skipped (docs skill inactive)');
            return;
        }

        if (!class_exists(\bookingextension_agent\local\wizard\wb_action_names::GENERATE_EMBEDDINGS)) {
            mtrace('bookingextension_agent docs embeddings rebuild: skipped (embeddings provider unavailable)');
            return;
        }

        $customdata = (array)$this->get_custom_data();
        $service = new docs_embeddings_index_service();
        try {
            $summary = $service->rebuild(
                isset($customdata['corpus_id']) ? (string)$customdata['corpus_id'] : null,
                isset($customdata['model']) ? (string)$customdata['model'] : null,
                isset($customdata['dimensions']) ? (int)$customdata['dimensions'] : null,
                !empty($customdata['force']),
                static function (string $line): void {
                    mtrace('bookingextension_agent docs embeddings rebuild: ' . $line);
                }
            );
        } catch (\Throwable $e) {
            // The task log must never show a bare "failed" (#2343).
            mtrace('bookingextension_agent docs embeddings rebuild FAILED: '
                . get_class($e) . ': ' . $e->getMessage());
            throw $e;
        }

        mtrace('bookingextension_agent docs embeddings rebuild status: ' . (string)($summary['status'] ?? 'unknown'));
        mtrace('bookingextension_agent docs embeddings rebuild:'
            . ' embedded=' . (int)($summary['embedded'] ?? 0)
            . ', reused=' . (int)($summary['reused'] ?? 0)
            . ', deleted=' . (int)($summary['deleted'] ?? 0)
            . ', written=' . (int)($summary['written'] ?? 0)
            . ', failed=' . (int)($summary['failed'] ?? 0));

        // Chunks whose embedding call failed (provider busy, rate limit) are missing from the index
        // (#2549). Fail the task so that Moodle's scheduler retries it with faildelay backoff; the
        // retry reuses every chunk that exists and embeds only the missing ones.
        if ((string)($summary['status'] ?? '') === 'partial') {
            $failed = (int)($summary['failed'] ?? 0);
            mtrace('bookingextension_agent docs embeddings rebuild: ' . $failed
                . ' chunk(s) failed to embed — index incomplete, the task will be retried.');
            throw new \moodle_exception(
                'embeddingsdocsrebuildfailed',
                'bookingextension_agent',
                '',
                'partial (' . $failed . ' chunks failed)'
            );
        }

        // Sanity check (parity with the skill-catalog task): after a successful FULL rebuild the docs
        // index must evaluate ready — schema valid, every resolvable corpus covered, and the freshly
        // stamped source fingerprint matching the live one. A not-ready result signals a persistent
        // defect; failing the task lets Moodle's scheduler apply faildelay backoff instead of looping
        // expensive rebuilds. Scoped (single-corpus) and skipped/empty runs do not stamp the full
        // fingerprint, so they are exempt.
        $isfullrebuild = !isset($customdata['corpus_id']) || trim((string)$customdata['corpus_id']) === '';
        if ($isfullrebuild && (string)($summary['status'] ?? '') === 'ok') {
            $status = (new docs_embeddings_readiness_service())->get_status();
            if (empty($status['ready'])) {
                mtrace('bookingextension_agent docs embeddings rebuild: index NOT ready — status='
                    . (string)($status['status'] ?? 'unknown')
                    . ((string)($status['reason'] ?? '') !== '' ? ', reason=' . (string)$status['reason'] : ''));
                throw new \moodle_exception(
                    'embeddingsdocsrebuildfailed',
                    'bookingextension_agent',
                    '',
                    (string)($status['status'] ?? 'unknown')
                        . ((string)($status['reason'] ?? '') !== '' ? ' (' . (string)$status['reason'] . ')' : '')
                );
            }
            mtrace('bookingextension_agent docs embeddings rebuild: index verified ready.');
        }
    }
}
