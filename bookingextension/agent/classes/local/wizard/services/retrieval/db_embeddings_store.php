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
 * DB-backed implementation of the embeddings store contract.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;

/**
 * Fulfils {@see embeddings_store} against two plain tables — {@see bx_agent_embeddings} (one row per
 * embedding, generic identity columns + float32 vector blob + optional site provenance) and
 * {@see bx_agent_embeddings_meta} (the committed-generation pointer + source fingerprint per variant).
 *
 * Vectors are stored as little-endian float32 ('g*') to halve the byte size of a JSON array while
 * staying architecture-portable. Rebuilds are an atomic generation swap: rows are written under a fresh
 * generation number (invisible to readers), then a single meta-pointer update publishes them and the
 * superseded generation is pruned. Retrieval streams the committed generation through the same cosine
 * engine as the CSV backend, so the ranking is identical; the SQL WHERE is the pre-narrowing seam a
 * future server-side ANN index would replace.
 */
class db_embeddings_store implements embeddings_store {
    /** Row table. */
    private const TABLE = 'bx_agent_embeddings';

    /** Per-variant metadata table (committed generation + fingerprint). */
    private const META = 'bx_agent_embeddings_meta';

    /** @var embeddings_row_mapper[] Area => mapper (used for area validation + identity keys). */
    private array $mappers;

    /** @var embeddings_retrieval_service Streaming cosine engine (shared with the CSV backend). */
    private embeddings_retrieval_service $retrieval;

    /**
     * Constructor.
     *
     * @param embeddings_row_mapper[] $mappers Per-area mappers, keyed by area.
     * @param embeddings_retrieval_service|null $retrieval Injectable cosine service.
     */
    public function __construct(array $mappers, ?embeddings_retrieval_service $retrieval = null) {
        $this->mappers = $mappers;
        $this->retrieval = $retrieval ?? new embeddings_retrieval_service();
    }

    /**
     * The mapper for an area (also validates the area is known).
     *
     * @param string $area
     * @return embeddings_row_mapper
     */
    private function mapper(string $area): embeddings_row_mapper {
        if (!isset($this->mappers[$area])) {
            throw new \coding_exception('db_embeddings_store: unknown embeddings area "' . $area . '".');
        }
        return $this->mappers[$area];
    }

    /**
     * The (area, model, dims) key params shared by every query.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return array
     */
    private function variant_params(string $area, string $emodel, int $edims): array {
        return ['area' => $area, 'emodel' => $emodel, 'edims' => $edims];
    }

    /**
     * The committed generation number for a variant (0 when none is published yet).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    private function committed_generation(string $area, string $emodel, int $edims): int {
        global $DB;
        $gen = $DB->get_field(
            self::META,
            'committedgeneration',
            $this->variant_params($area, $emodel, $edims),
            IGNORE_MISSING
        );
        return $gen === false ? 0 : (int)$gen;
    }

    /**
     * Encode a float vector as a little-endian float32 blob.
     *
     * @param float[] $vec
     * @return string
     */
    private function pack_vector(array $vec): string {
        if (empty($vec)) {
            return '';
        }
        return pack('g*', ...array_map('floatval', $vec));
    }

    /**
     * Decode a little-endian float32 blob back into a float vector.
     *
     * Returns an empty array for a truncated/corrupt blob (mismatched dimension count) so a broken row
     * is skipped rather than silently mis-scored.
     *
     * @param string $blob
     * @param int $edims
     * @return float[]
     */
    private function unpack_vector(string $blob, int $edims): array {
        if ($blob === '') {
            return [];
        }
        $vals = unpack('g*', $blob);
        if ($vals === false) {
            return [];
        }
        $vec = array_values($vals);
        if ($edims > 0 && count($vec) !== $edims) {
            return [];
        }
        return $vec;
    }

    /**
     * Return the top-k rows for one area/variant, scored by cosine and above the minimum score.
     *
     * Searches only the committed generation; the optional filter narrows the SQL by allowed context ids
     * (site content) — for docs/skills the filter is null (global). Never returns the raw vector.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param float[] $queryvector
     * @param int $k
     * @param float $minscore
     * @param retrieval_filter|null $filter
     * @return embedding_hit[]
     */
    public function search_top_k(
        string $area,
        string $emodel,
        int $edims,
        array $queryvector,
        int $k,
        float $minscore,
        ?retrieval_filter $filter = null
    ): array {
        $this->mapper($area);
        $committed = $this->committed_generation($area, $emodel, $edims);
        if ($committed <= 0 || empty($queryvector)) {
            return [];
        }

        $rows = $this->stream_scored_rows($area, $emodel, $edims, $committed, $filter);
        $toprows = $this->retrieval->search_top_k_streaming($queryvector, $rows, max(1, $k));

        $hits = [];
        foreach ($toprows as $row) {
            $score = (float)($row['score'] ?? 0.0);
            if ($minscore > 0.0 && $score < $minscore) {
                continue;
            }
            $hits[] = $this->row_to_hit($row, $score);
        }
        return $hits;
    }

    /**
     * Build the committed-generation WHERE clause + params, applying the retrieval filter.
     *
     * Returns [null, []] when the filter narrows to no allowed context (so the caller yields nothing).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $committed
     * @param retrieval_filter|null $filter
     * @return array [string|null $where, array $params]
     */
    private function committed_where(
        string $area,
        string $emodel,
        int $edims,
        int $committed,
        ?retrieval_filter $filter
    ): array {
        global $DB;
        $where = 'area = :area AND emodel = :emodel AND edims = :edims AND generation = :generation';
        $params = $this->variant_params($area, $emodel, $edims);
        $params['generation'] = $committed;

        if ($filter !== null && !$filter->is_global()) {
            $contextids = $filter->contextids();
            if ($contextids !== null) {
                if (empty($contextids)) {
                    return [null, []];
                }
                [$insql, $inparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
                $where .= ' AND contextid ' . $insql;
                $params += $inparams;
            }
            $owner = $filter->owneruserid();
            if ($owner !== null) {
                $where .= ' AND owneruserid = :fowner';
                $params['fowner'] = $owner;
            }
        }
        return [$where, $params];
    }

    /**
     * Yield committed rows as scoring records (decoded vector under 'embedding' + hit metadata).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $committed
     * @param retrieval_filter|null $filter
     * @return \Generator
     */
    private function stream_scored_rows(
        string $area,
        string $emodel,
        int $edims,
        int $committed,
        ?retrieval_filter $filter
    ): \Generator {
        global $DB;
        [$where, $params] = $this->committed_where($area, $emodel, $edims, $committed, $filter);
        if ($where === null) {
            return;
        }
        $rs = $DB->get_recordset_select(self::TABLE, $where, $params);
        foreach ($rs as $rec) {
            yield [
                'embedding' => $this->unpack_vector((string)$rec->embedding, (int)$rec->edims),
                'area' => $rec->area,
                'owner' => $rec->owner,
                'refkey' => $rec->refkey,
                'refindex' => (int)$rec->refindex,
                'title' => (string)($rec->title ?? ''),
                'docid' => $rec->docid !== null ? (int)$rec->docid : null,
                'contextid' => $rec->contextid !== null ? (int)$rec->contextid : null,
                'courseid' => $rec->courseid !== null ? (int)$rec->courseid : null,
                'owneruserid' => $rec->owneruserid !== null ? (int)$rec->owneruserid : null,
                'contenthash' => $rec->contenthash !== null ? (string)$rec->contenthash : null,
            ];
        }
        $rs->close();
    }

    /**
     * Build a retrieval hit from a scored row (generic columns map straight onto the DTO).
     *
     * @param array $row
     * @param float $score
     * @return embedding_hit
     */
    private function row_to_hit(array $row, float $score): embedding_hit {
        return new embedding_hit(
            (string)($row['area'] ?? ''),
            (string)($row['owner'] ?? ''),
            (string)($row['refkey'] ?? ''),
            (int)($row['refindex'] ?? 0),
            (string)($row['title'] ?? ''),
            $score,
            $row['docid'] ?? null,
            $row['contextid'] ?? null,
            $row['courseid'] ?? null,
            $row['owneruserid'] ?? null,
            $row['contenthash'] ?? null
        );
    }

    /**
     * Rebuild a stored record into an embedding_row (decoding the vector).
     *
     * @param \stdClass $rec
     * @return embedding_row
     */
    private function record_to_row(\stdClass $rec): embedding_row {
        return new embedding_row(
            (string)$rec->area,
            (string)$rec->owner,
            (string)$rec->refkey,
            (int)$rec->refindex,
            (string)($rec->title ?? ''),
            (string)$rec->emodel,
            (int)$rec->edims,
            (string)($rec->contenthash ?? ''),
            $this->unpack_vector((string)$rec->embedding, (int)$rec->edims),
            $rec->endindex !== null ? (int)$rec->endindex : null,
            $rec->docid !== null ? (int)$rec->docid : null,
            $rec->contextid !== null ? (int)$rec->contextid : null,
            $rec->courseid !== null ? (int)$rec->courseid : null,
            $rec->owneruserid !== null ? (int)$rec->owneruserid : null
        );
    }

    /**
     * Whether a committed index exists for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return bool
     */
    public function exists(string $area, string $emodel, int $edims): bool {
        return $this->count_rows($area, $emodel, $edims) > 0;
    }

    /**
     * Number of committed rows for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function count_rows(string $area, string $emodel, int $edims): int {
        global $DB;
        $this->mapper($area);
        $committed = $this->committed_generation($area, $emodel, $edims);
        if ($committed <= 0) {
            return 0;
        }
        $params = $this->variant_params($area, $emodel, $edims);
        $params['generation'] = $committed;
        return $DB->count_records(self::TABLE, $params);
    }

    /**
     * Committed row counts grouped by owner (#2342: the governance page shows per-area figures).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return array<string,int>
     */
    public function count_rows_by_owner(string $area, string $emodel, int $edims): array {
        global $DB;
        $this->mapper($area);
        $committed = $this->committed_generation($area, $emodel, $edims);
        if ($committed <= 0) {
            return [];
        }
        $rows = $DB->get_records_sql(
            "SELECT owner, COUNT(*) AS cnt FROM {" . self::TABLE . "}
              WHERE area = :area AND emodel = :emodel AND edims = :edims AND generation = :generation
           GROUP BY owner",
            $this->variant_params($area, $emodel, $edims) + ['generation' => $committed]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row->owner] = (int)$row->cnt;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Read the stored source fingerprint the index was last built from (empty when unknown).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return string
     */
    public function fingerprint(string $area, string $emodel, int $edims): string {
        global $DB;
        $this->mapper($area);
        $fp = $DB->get_field(
            self::META,
            'fingerprint',
            $this->variant_params($area, $emodel, $edims),
            IGNORE_MISSING
        );
        return ($fp === false || $fp === null) ? '' : (string)$fp;
    }

    /**
     * Store the source fingerprint the index was just built from.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $fingerprint
     * @return void
     */
    public function set_fingerprint(string $area, string $emodel, int $edims, string $fingerprint): void {
        global $DB;
        $this->mapper($area);
        $params = $this->variant_params($area, $emodel, $edims);
        $meta = $DB->get_record(self::META, $params);
        if ($meta) {
            $meta->fingerprint = $fingerprint;
            $meta->timemodified = time();
            $DB->update_record(self::META, $meta);
            return;
        }
        $rec = (object)$params;
        $rec->committedgeneration = 0;
        $rec->fingerprint = $fingerprint;
        $rec->timemodified = time();
        $DB->insert_record(self::META, $rec);
    }

    /**
     * Open a new (uncommitted) generation for this area/variant and return its id.
     *
     * The id is one past the highest generation present for the variant, so an in-flight rebuild never
     * collides with the live generation or a discarded remnant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function begin_generation(string $area, string $emodel, int $edims): int {
        global $DB;
        $this->mapper($area);
        $sql = "SELECT COALESCE(MAX(generation), 0)
                  FROM {" . self::TABLE . "}
                 WHERE area = :area AND emodel = :emodel AND edims = :edims";
        $max = (int)$DB->get_field_sql($sql, $this->variant_params($area, $emodel, $edims));
        return $max + 1;
    }

    /**
     * Add one row to an open generation (single insert; the vector is packed to float32).
     *
     * @param string $area
     * @param int $generation
     * @param embedding_row $row
     * @return void
     */
    public function upsert(string $area, int $generation, embedding_row $row): void {
        global $DB;
        $mapper = $this->mapper($area);
        embeddings_dimension_guard::assert_row_matches_dims($row);
        $record = new \stdClass();
        $record->area = $area;
        $record->owner = $row->owner;
        $record->refkey = $row->refkey;
        $record->refindex = $row->refindex;
        $record->endindex = $row->endindex;
        $record->title = $row->title;
        $record->emodel = $row->emodel;
        $record->edims = $row->edims;
        $record->contenthash = $row->contenthash;
        $record->identityhash = sha1($mapper->identity_key_for_row($row));
        $record->generation = $generation;
        $record->embedding = $this->pack_vector($row->embedding);
        $record->docid = $row->docid;
        $record->contextid = $row->contextid;
        $record->courseid = $row->courseid;
        $record->owneruserid = $row->owneruserid;
        $record->timemodified = time();
        // One row at a time (insert_record, not insert_records): a single binary blob per insert
        // keeps every DB dialect happy.
        $DB->insert_record(self::TABLE, $record, false);
    }

    /**
     * Return an existing committed row by its identity key (for hash-based reuse on rebuild), or null.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $key
     * @return embedding_row|null
     */
    public function reuse_existing(string $area, string $emodel, int $edims, string $key): ?embedding_row {
        global $DB;
        if ($key === '') {
            return null;
        }
        $this->mapper($area);
        $committed = $this->committed_generation($area, $emodel, $edims);
        if ($committed <= 0) {
            return null;
        }
        $params = $this->variant_params($area, $emodel, $edims);
        $params['generation'] = $committed;
        $params['identityhash'] = sha1($key);
        $rec = $DB->get_record(self::TABLE, $params, '*', IGNORE_MULTIPLE);
        return $rec ? $this->record_to_row($rec) : null;
    }

    /**
     * Commit an open generation: publish it via the meta pointer, then prune superseded generations.
     *
     * The pointer flip is transactional (readers switch atomically); pruning runs afterwards because the
     * older rows are unreferenced garbage the moment the pointer moves.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return int Number of committed rows.
     */
    public function commit_generation(string $area, string $emodel, int $edims, int $generation): int {
        global $DB;
        $this->mapper($area);
        $params = $this->variant_params($area, $emodel, $edims);
        $params['generation'] = $generation;
        $count = $DB->count_records(self::TABLE, $params);

        $transaction = $DB->start_delegated_transaction();
        $meta = $DB->get_record(self::META, $this->variant_params($area, $emodel, $edims));
        if ($meta) {
            $meta->committedgeneration = $generation;
            $meta->timemodified = time();
            $DB->update_record(self::META, $meta);
        } else {
            $rec = (object)$this->variant_params($area, $emodel, $edims);
            $rec->committedgeneration = $generation;
            $rec->fingerprint = null;
            $rec->timemodified = time();
            $DB->insert_record(self::META, $rec);
        }
        $transaction->allow_commit();

        // Prune superseded generations; the just-committed one is the only valid state now.
        $DB->delete_records_select(
            self::TABLE,
            'area = :area AND emodel = :emodel AND edims = :edims AND generation < :generation',
            ['area' => $area, 'emodel' => $emodel, 'edims' => $edims, 'generation' => $generation]
        );

        // Self-heal: a dims change leaves a dead meta row (same area+model, other dims) behind —
        // a get_record()-on-duplicates trap for area+model readers (#2340).
        $DB->delete_records_select(
            self::META,
            'area = :area AND emodel = :emodel AND edims <> :edims',
            ['area' => $area, 'emodel' => $emodel, 'edims' => $edims]
        );
        return $count;
    }

    /**
     * Discard an open, uncommitted generation without publishing it.
     *
     * Refuses to touch the live generation as a safety guard.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return void
     */
    public function discard_generation(string $area, string $emodel, int $edims, int $generation): void {
        global $DB;
        $this->mapper($area);
        if ($generation === $this->committed_generation($area, $emodel, $edims)) {
            return;
        }
        $params = $this->variant_params($area, $emodel, $edims);
        $params['generation'] = $generation;
        $DB->delete_records(self::TABLE, $params);
    }

    /**
     * The committed generation for a variant, bootstrapping the meta row when none is published yet.
     *
     * The incremental document operations write into the currently committed generation. On a fresh
     * variant (no meta row, or a meta row left at committedgeneration = 0 e.g. by set_fingerprint)
     * there is no committed generation — and {@see search_top_k()} scans WHERE generation = committed
     * and returns [] while committed <= 0, so incrementally written rows would never become visible.
     * Therefore the first incremental write publishes generation 1 up front; the initial build runs
     * doc-wise over the same path (cursor 0), without a swap.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int The committed generation (>= 1).
     */
    private function bootstrap_committed_generation(string $area, string $emodel, int $edims): int {
        global $DB;
        $params = $this->variant_params($area, $emodel, $edims);
        $meta = $DB->get_record(self::META, $params);
        if ($meta && (int)$meta->committedgeneration > 0) {
            return (int)$meta->committedgeneration;
        }
        if ($meta) {
            $meta->committedgeneration = 1;
            $meta->timemodified = time();
            $DB->update_record(self::META, $meta);
        } else {
            $rec = (object)$params;
            $rec->committedgeneration = 1;
            $rec->fingerprint = null;
            $rec->timemodified = time();
            $DB->insert_record(self::META, $rec);
        }
        return 1;
    }

    /**
     * Load one document's committed rows, keyed by chunk number (refindex).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @param string $owner
     * @param string $docid
     * @return \stdClass[] Raw records keyed by refindex.
     */
    private function load_document_records(
        string $area,
        string $emodel,
        int $edims,
        int $generation,
        string $owner,
        string $docid
    ): array {
        global $DB;
        $select = 'area = :area AND emodel = :emodel AND edims = :edims AND generation = :generation'
            . ' AND owner = :owner AND ' . $DB->sql_compare_text('refkey', 255) . ' = :refkey';
        $params = $this->variant_params($area, $emodel, $edims);
        $params['generation'] = $generation;
        $params['owner'] = $owner;
        $params['refkey'] = $docid;
        $byindex = [];
        foreach ($DB->get_records_select(self::TABLE, $select, $params) as $rec) {
            $byindex[(int)$rec->refindex] = $rec;
        }
        return $byindex;
    }

    /**
     * Replace one document's chunk set in the committed generation (doc-atomic, diff-based).
     *
     * Diffs the given complete chunk set against the stored one per (refindex, contenthash):
     * identical chunks are left physically untouched (same DB id), changed chunks are updated in
     * place (vector, hash, title, provenance), new chunk numbers are inserted and vanished chunk
     * numbers are deleted. A read per changed document is cheap; a blind delete+reinsert would be
     * pointless churn (strictly incremental site indexing forbids it).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @param string $docid
     * @param embedding_row[] $rows The document's complete new chunk set (refindex = chunk number).
     * @return array Write stats: ['inserted' => int, 'updated' => int, 'deleted' => int, 'kept' => int].
     */
    public function replace_document(
        string $area,
        string $emodel,
        int $edims,
        string $owner,
        string $docid,
        array $rows
    ): array {
        global $DB;
        $this->mapper($area);
        if ($owner === '' || $docid === '') {
            throw new \coding_exception('db_embeddings_store: replace_document requires owner and docid.');
        }
        $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'kept' => 0];

        $transaction = $DB->start_delegated_transaction();
        $committed = $this->bootstrap_committed_generation($area, $emodel, $edims);
        $existing = $this->load_document_records($area, $emodel, $edims, $committed, $owner, $docid);

        $seen = [];
        foreach ($rows as $row) {
            if (!$row instanceof embedding_row) {
                throw new \coding_exception('db_embeddings_store: replace_document expects embedding_row[].');
            }
            if ($row->owner !== $owner || $row->refkey !== $docid) {
                throw new \coding_exception(
                    'db_embeddings_store: replace_document row identity must match the given owner/docid.'
                );
            }
            if ($row->emodel !== $emodel || $row->edims !== $edims) {
                throw new \coding_exception(
                    'db_embeddings_store: replace_document row variant must match the given emodel/edims.'
                );
            }
            if (isset($seen[$row->refindex])) {
                throw new \coding_exception(
                    'db_embeddings_store: replace_document received duplicate chunk number ' . $row->refindex . '.'
                );
            }
            $seen[$row->refindex] = true;

            $current = $existing[$row->refindex] ?? null;
            if ($current === null) {
                $this->upsert($area, $committed, $row);
                $stats['inserted']++;
            } else if ((string)$current->contenthash === $row->contenthash) {
                // Identical chunk: leave the row physically untouched (same DB id, zero write cost).
                $stats['kept']++;
            } else {
                $current->embedding = $this->pack_vector($row->embedding);
                $current->contenthash = $row->contenthash;
                $current->title = $row->title;
                $current->endindex = $row->endindex;
                $current->docid = $row->docid;
                $current->contextid = $row->contextid;
                $current->courseid = $row->courseid;
                $current->owneruserid = $row->owneruserid;
                $current->timemodified = time();
                $DB->update_record(self::TABLE, $current);
                $stats['updated']++;
            }
        }

        // Chunk numbers no longer present in the new set are gone from the source: delete them.
        foreach ($existing as $refindex => $rec) {
            if (!isset($seen[$refindex])) {
                $DB->delete_records(self::TABLE, ['id' => $rec->id]);
                $stats['deleted']++;
            }
        }

        $transaction->allow_commit();
        return $stats;
    }

    /**
     * Delete every chunk of one document (events path: the source item was deleted).
     *
     * Deletes across all generations of the variant, so stale remnants of an aborted rebuild are
     * cleaned up alongside the committed rows.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @param string $docid
     * @return void
     */
    public function delete_document(string $area, string $emodel, int $edims, string $owner, string $docid): void {
        global $DB;
        $this->mapper($area);
        $select = 'area = :area AND emodel = :emodel AND edims = :edims AND owner = :owner'
            . ' AND ' . $DB->sql_compare_text('refkey', 255) . ' = :refkey';
        $params = $this->variant_params($area, $emodel, $edims);
        $params['owner'] = $owner;
        $params['refkey'] = $docid;
        $DB->delete_records_select(self::TABLE, $select, $params);
    }

    /**
     * Delete every row of one owner (sub-area) — the "disable = prune" path, context-independent.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @return void
     */
    public function delete_owner(string $area, string $emodel, int $edims, string $owner): void {
        global $DB;
        $this->mapper($area);
        $params = $this->variant_params($area, $emodel, $edims);
        $params['owner'] = $owner;
        $DB->delete_records(self::TABLE, $params);
    }

    /**
     * Delete the rows of one owner (sub-area) within one course — the scope-governance prune path
     * (delta sync, context-governance blueprint §4.2).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @param int $courseid
     * @return void
     */
    public function delete_owner_in_course(string $area, string $emodel, int $edims, string $owner, int $courseid): void {
        global $DB;
        $this->mapper($area);
        $params = $this->variant_params($area, $emodel, $edims);
        $params['owner'] = $owner;
        $params['courseid'] = $courseid;
        $DB->delete_records(self::TABLE, $params);
    }

    /**
     * Yield each committed row for this area/variant as an embedding_row (bounded memory).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return \Generator
     */
    public function stream_rows(string $area, string $emodel, int $edims): \Generator {
        global $DB;
        $this->mapper($area);
        $committed = $this->committed_generation($area, $emodel, $edims);
        if ($committed <= 0) {
            return;
        }
        $params = $this->variant_params($area, $emodel, $edims);
        $params['generation'] = $committed;
        $rs = $DB->get_recordset(self::TABLE, $params);
        foreach ($rs as $rec) {
            yield $this->record_to_row($rec);
        }
        $rs->close();
    }

    /**
     * Delete all rows for a context (course/module deleted); only site content carries a context id.
     *
     * @param int $contextid
     * @return void
     */
    public function delete_by_context(int $contextid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['contextid' => $contextid]);
    }

    /**
     * Delete all rows for a course (course deleted / course content reset).
     *
     * Mirror of {@see delete_by_context()}: embedding rows carry MODULE context ids, but a
     * course_deleted event only provides the COURSE context — by the time it fires, the per-module
     * contexts are no longer enumerable, so matching on contextid would miss every module row. Rows
     * also carry a courseid column, which this matches instead. Docs/skills rows have a null course
     * id and are never matched.
     *
     * @param int $courseid
     * @return void
     */
    public function delete_by_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
