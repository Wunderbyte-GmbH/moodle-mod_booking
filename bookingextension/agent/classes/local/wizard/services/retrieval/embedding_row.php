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
 * One stored embedding row (generic identity + optional site provenance).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * A single row written to / read from an {@see embeddings_store}, decoupled from the storage columns.
 *
 * The identity triple (owner, refkey, refindex) means different things per area — for docs it is
 * (corpus, chunk path, start line); for skills (skill, anchor kind, anchor index); for site content
 * (search area id, doc id, chunk number). The area's row mapper translates between this DTO and the
 * concrete columns, so callers never touch storage internals. The site-provenance fields are null for
 * docs/skills and only populated for site content.
 */
final class embedding_row {
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

    /** @var string Embedding model. */
    public string $emodel;

    /** @var int Embedding dimensions. */
    public int $edims;

    /** @var string Change-detection hash (content + model + dims). */
    public string $contenthash;

    /** @var float[] The embedding vector. */
    public array $embedding;

    /** @var int|null Optional end index (docs: end line); null otherwise. */
    public ?int $endindex;

    /** @var int|null Site provenance: source document id; null for docs/skills. */
    public ?int $docid;

    /** @var int|null Site provenance: context id; null for docs/skills. */
    public ?int $contextid;

    /** @var int|null Site provenance: course id; null for docs/skills. */
    public ?int $courseid;

    /** @var int|null Site provenance: authoring user id; null for docs/skills. */
    public ?int $owneruserid;

    /**
     * Constructor.
     *
     * @param string $area
     * @param string $owner Identity part 1 (corpus / skill / search-area-id).
     * @param string $refkey Identity part 2 (chunk path / anchor kind / doc id).
     * @param int $refindex Identity part 3 (start line / anchor index / chunk number).
     * @param string $title Human-readable title for display.
     * @param string $emodel Embedding model.
     * @param int $edims Embedding dimensions.
     * @param string $contenthash Change-detection hash (content + model + dims).
     * @param float[] $embedding The embedding vector.
     * @param int|null $endindex Optional end index (docs: end line); null otherwise.
     * @param int|null $docid Site provenance: source document id; null for docs/skills.
     * @param int|null $contextid Site provenance: context id; null for docs/skills.
     * @param int|null $courseid Site provenance: course id; null for docs/skills.
     * @param int|null $owneruserid Site provenance: authoring user id; null for docs/skills.
     */
    public function __construct(
        string $area,
        string $owner,
        string $refkey,
        int $refindex,
        string $title,
        string $emodel,
        int $edims,
        string $contenthash,
        array $embedding,
        ?int $endindex = null,
        ?int $docid = null,
        ?int $contextid = null,
        ?int $courseid = null,
        ?int $owneruserid = null
    ) {
        $this->area = $area;
        $this->owner = $owner;
        $this->refkey = $refkey;
        $this->refindex = $refindex;
        $this->title = $title;
        $this->emodel = $emodel;
        $this->edims = $edims;
        $this->contenthash = $contenthash;
        $this->embedding = $embedding;
        $this->endindex = $endindex;
        $this->docid = $docid;
        $this->contextid = $contextid;
        $this->courseid = $courseid;
        $this->owneruserid = $owneruserid;
    }
}
