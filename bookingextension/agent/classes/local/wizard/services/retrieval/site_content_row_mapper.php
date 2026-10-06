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
 * Row mapper for the site-content embeddings area (DB-only).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

use bookingextension_agent\local\wizard\embeddings_csv_repository_base;

/**
 * Identity + area knowledge for the `site_content` area — chunks of Moodle site content indexed from
 * core_search Areas.
 *
 * Unlike docs/skills this area is **DB-only**: the CSV backend ignores {@see retrieval_filter} and so
 * cannot safely serve access-narrowed content, therefore {@see repo_for_variant()} (and the other
 * CSV-shaped helpers) throw. The DB store only ever calls {@see area()} and
 * {@see identity_key_for_row()}, building its DTOs straight from the generic columns.
 *
 * Identity triple: owner = the core_search area id (e.g. `mod_page-activity`), refkey = the area's
 * internal item/instance id as a string, refindex = the chunk number within that item. The provenance
 * columns (docid = the area-internal instance id used by check_access, contextid, courseid,
 * owneruserid) drive the access prefilter and the per-hit authorisation.
 */
class site_content_row_mapper implements embeddings_row_mapper {
    /** Area discriminator. */
    public const AREA = 'site_content';

    /**
     * The area discriminator this mapper handles.
     *
     * @return string
     */
    public function area(): string {
        return self::AREA;
    }

    /**
     * Site content is DB-only; there is no CSV repository for it.
     *
     * @param string $emodel
     * @param int $edims
     * @return embeddings_csv_repository_base
     */
    public function repo_for_variant(string $emodel, int $edims): embeddings_csv_repository_base {
        unset($emodel, $edims);
        throw new \coding_exception('site_content is a DB-only area; it has no CSV repository.');
    }

    /**
     * Not supported: site content never round-trips through CSV.
     *
     * @param string[] $csvrow
     * @return embedding_row
     */
    public function to_row(array $csvrow): embedding_row {
        unset($csvrow);
        throw new \coding_exception('site_content is a DB-only area; to_row is unsupported.');
    }

    /**
     * Not supported: site content never round-trips through CSV.
     *
     * @param embedding_row $row
     * @return string[]
     */
    public function to_csv(embedding_row $row): array {
        unset($row);
        throw new \coding_exception('site_content is a DB-only area; to_csv is unsupported.');
    }

    /**
     * Not supported: the DB store builds hits directly from its columns.
     *
     * @param string[] $csvrow
     * @param float $score
     * @return embedding_hit
     */
    public function to_hit(array $csvrow, float $score): embedding_hit {
        unset($csvrow, $score);
        throw new \coding_exception('site_content is a DB-only area; to_hit is unsupported.');
    }

    /**
     * Identity key (area id | item id | chunk number) for a raw row.
     *
     * @param string[] $csvrow
     * @return string
     */
    public function identity_key(array $csvrow): string {
        $owner = trim((string)($csvrow['owner'] ?? ''));
        $refkey = trim((string)($csvrow['refkey'] ?? ''));
        if ($owner === '' || $refkey === '') {
            return '';
        }
        return $owner . '|' . $refkey . '|' . (int)($csvrow['refindex'] ?? 0);
    }

    /**
     * Identity key (area id | item id | chunk number) from an embedding_row.
     *
     * @param embedding_row $row
     * @return string
     */
    public function identity_key_for_row(embedding_row $row): string {
        $owner = trim($row->owner);
        $refkey = trim($row->refkey);
        if ($owner === '' || $refkey === '') {
            return '';
        }
        return $owner . '|' . $refkey . '|' . $row->refindex;
    }
}
