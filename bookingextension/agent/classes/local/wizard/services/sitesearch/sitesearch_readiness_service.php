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
 * Hard readiness gate for the semantic site-search feature (blueprint §16).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

use bookingextension_agent\local\wizard\wb_action_names;

/**
 * The one hard gate of the site-search feature (blueprint §16): semantic site search exists only
 * with the Wunderbyte embeddings provider, on Moodle 5+, and on the database embeddings backend
 * (the CSV backend ignores the access filter). No fallback — without embeddings there is no
 * semantics (keyword search would be Moodle's native search).
 *
 * Extracted into its own tiny service so the governance page's gate branch is unit-testable; the
 * indexer/retrieval services carry the same checks internally and stay untouched here.
 */
class sitesearch_readiness_service {
    /**
     * Whether the site-search feature may be offered at all.
     *
     * @return array ['ready' => bool, 'reason' => string] Reason is one of
     *               'embeddings_provider_unavailable' | 'requires_moodle_5' | 'requires_db_backend' | ''.
     */
    public function is_ready(): array {
        global $CFG;

        if (!class_exists(wb_action_names::GENERATE_EMBEDDINGS)) {
            return ['ready' => false, 'reason' => 'embeddings_provider_unavailable'];
        }
        if ((int)($CFG->branch ?? 0) < 500) {
            return ['ready' => false, 'reason' => 'requires_moodle_5'];
        }
        if (get_config('bookingextension_agent', 'embeddingsstore') !== 'db') {
            return ['ready' => false, 'reason' => 'requires_db_backend'];
        }
        return ['ready' => true, 'reason' => ''];
    }
}
