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
 * One retrieval hit returned by the embeddings store (row metadata + score, no vector).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * A scored search result. Carries the identity + display metadata and the cosine score, but never the
 * embedding vector (which stays in the store). The site-provenance fields are null for docs/skills and
 * only populated for site content (so the caller can run the authoritative access check and build a
 * deep link).
 */
final class embedding_hit {
    /** @var string Area discriminator ('docs' | 'skills' | 'site_content'). */
    public string $area;

    /** @var string Identity part 1 (corpus / skill / search-area-id). */
    public string $owner;

    /** @var string Identity part 2 (chunk path / anchor kind / doc id). */
    public string $refkey;

    /** @var int Identity part 3 (start line / anchor index / chunk number). */
    public int $refindex;

    /** @var string Human-readable title for display. */
    public string $title;

    /** @var float Cosine similarity. */
    public float $score;

    /** @var int|null Site provenance: source document id; null for docs/skills. */
    public ?int $docid;

    /** @var int|null Site provenance: context id; null for docs/skills. */
    public ?int $contextid;

    /** @var int|null Site provenance: course id; null for docs/skills. */
    public ?int $courseid;

    /** @var int|null Site provenance: authoring user id; null for docs/skills. */
    public ?int $owneruserid;

    /**
     * @var string|null Stored content hash of the embedded chunk (site content: input for the
     * query-time snippet self-heal comparison); null when the backing row carries none.
     */
    public ?string $contenthash;

    /**
     * Constructor.
     *
     * @param string $area
     * @param string $owner Identity part 1 (corpus / skill / search-area-id).
     * @param string $refkey Identity part 2 (chunk path / anchor kind / doc id).
     * @param int $refindex Identity part 3 (start line / anchor index / chunk number).
     * @param string $title Human-readable title for display.
     * @param float $score Cosine similarity.
     * @param int|null $docid Site provenance: source document id; null for docs/skills.
     * @param int|null $contextid Site provenance: context id; null for docs/skills.
     * @param int|null $courseid Site provenance: course id; null for docs/skills.
     * @param int|null $owneruserid Site provenance: authoring user id; null for docs/skills.
     * @param string|null $contenthash Stored content hash of the embedded chunk; null if unknown.
     */
    public function __construct(
        string $area,
        string $owner,
        string $refkey,
        int $refindex,
        string $title,
        float $score,
        ?int $docid = null,
        ?int $contextid = null,
        ?int $courseid = null,
        ?int $owneruserid = null,
        ?string $contenthash = null
    ) {
        $this->area = $area;
        $this->owner = $owner;
        $this->refkey = $refkey;
        $this->refindex = $refindex;
        $this->title = $title;
        $this->score = $score;
        $this->docid = $docid;
        $this->contextid = $contextid;
        $this->courseid = $courseid;
        $this->owneruserid = $owneruserid;
        $this->contenthash = $contenthash;
    }
}
