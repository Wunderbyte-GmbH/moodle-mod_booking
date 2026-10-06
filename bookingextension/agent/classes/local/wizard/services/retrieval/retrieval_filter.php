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
 * Access/context narrowing for a retrieval query.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * Pre-narrowing for {@see embeddings_store::search_top_k()} — a SQL-level restriction that keeps the
 * scan small for large corpora.
 *
 * It is NOT an authoritative access decision: the caller still runs the search area's own
 * per-document access check on the returned candidates. A global (null) filter means "no narrowing"
 * and is what docs/skills always pass; site content passes the user's allowed context ids.
 */
final class retrieval_filter {
    /** @var int[]|null Allowed context ids; null means no narrowing (global visibility). */
    private ?array $contextids;

    /** @var int|null Restrict to this owner user id; null means any owner. */
    private ?int $owneruserid;

    /**
     * Constructor.
     *
     * @param int[]|null $contextids Allowed context ids, or null for no narrowing.
     * @param int|null $owneruserid Restrict to this owner user id, or null for any.
     */
    public function __construct(?array $contextids = null, ?int $owneruserid = null) {
        $this->contextids = $contextids === null ? null : array_values(array_unique(array_map('intval', $contextids)));
        $this->owneruserid = $owneruserid;
    }

    /**
     * The allowed context ids, or null for no narrowing.
     *
     * @return int[]|null
     */
    public function contextids(): ?array {
        return $this->contextids;
    }

    /**
     * The owner user id restriction, or null for any owner.
     *
     * @return int|null
     */
    public function owneruserid(): ?int {
        return $this->owneruserid;
    }

    /**
     * Whether this filter narrows nothing (global visibility).
     *
     * @return bool
     */
    public function is_global(): bool {
        return $this->contextids === null && $this->owneruserid === null;
    }
}
