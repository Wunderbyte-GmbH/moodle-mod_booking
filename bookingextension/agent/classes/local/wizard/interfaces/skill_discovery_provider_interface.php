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
 * Contract for semantic skill discovery (embedding retrieval over the skill catalog).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Engine-provided semantic discovery: embed a capability query and retrieve the most relevant
 * registered skills from the catalog, returning plain data.
 *
 * Skills depend only on this contract (never on the embeddings/LLM services directly), so the RAG
 * machinery can be injected and the skill layer stays free of engine services.
 */
interface skill_discovery_provider_interface {
    /** Discovery succeeded; discovered_skills holds the matches. */
    public const STATUS_OK = 'ok';

    /** The embeddings provider is not available. */
    public const STATUS_EMBEDDINGS_UNAVAILABLE = 'embeddings_unavailable';

    /** The skill-catalog embeddings index is not ready. */
    public const STATUS_CATALOG_NOT_READY = 'catalog_not_ready';

    /** Generating the query embedding failed. */
    public const STATUS_EMBEDDING_FAILED = 'embedding_failed';

    /**
     * Discover registered skills matching a capability query.
     *
     * @param string $query     The capability the user needs, in natural language.
     * @param int    $contextid
     * @param int    $userid
     * @param int    $topk      Maximum matches to return.
     * @return array{status: string, discovered_skills: array<int,array{skill:string,schema:array<string,mixed>}>}
     */
    public function discover(string $query, int $contextid, int $userid, int $topk = 5): array;
}
