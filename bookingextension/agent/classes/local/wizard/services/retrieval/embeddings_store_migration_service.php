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
 * One-shot CSV → DB migration for the embeddings store.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;

/**
 * Copies a committed CSV index into the DB backend without re-embedding.
 *
 * Because the CSV rows already carry their vectors, migration is a plain stream + upsert through the
 * shared {@see embeddings_store} contract — one generation swap, then the source fingerprint is
 * carried across so readiness sees the DB index as in-sync (no needless rebuild). This is the "trivial
 * import" path; if it is ever skipped, the readiness rebuild fallback re-embeds instead.
 */
class embeddings_store_migration_service {
    /**
     * Copy the committed CSV index for one area/variant into the DB backend via a generation swap.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return array Status: ['status' => ok|skipped, 'reason' => ?string, 'migrated' => int].
     */
    public function migrate_csv_to_db(string $area, string $emodel, int $edims): array {
        $mappers = embeddings_store_factory::mappers();
        $csv = new csv_embeddings_store($mappers);
        $db = new db_embeddings_store($mappers);

        if (!$csv->exists($area, $emodel, $edims)) {
            return ['status' => 'skipped', 'reason' => 'no_csv_index', 'migrated' => 0];
        }

        $gen = $db->begin_generation($area, $emodel, $edims);
        try {
            foreach ($csv->stream_rows($area, $emodel, $edims) as $row) {
                $db->upsert($area, $gen, $row);
            }
            $written = $db->commit_generation($area, $emodel, $edims, $gen);
            // Carry the source fingerprint so the DB index reads as in-sync (no needless rebuild).
            $db->set_fingerprint($area, $emodel, $edims, $csv->fingerprint($area, $emodel, $edims));
        } catch (\Throwable $e) {
            $db->discard_generation($area, $emodel, $edims, $gen);
            throw $e;
        }

        return ['status' => 'ok', 'reason' => null, 'migrated' => $written];
    }

    /**
     * Import CSV → DB only when the DB backend has no committed index yet (idempotent, never clobbers).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return array
     */
    public function migrate_csv_to_db_if_needed(string $area, string $emodel, int $edims): array {
        $db = new db_embeddings_store(embeddings_store_factory::mappers());
        if ($db->exists($area, $emodel, $edims)) {
            return ['status' => 'skipped', 'reason' => 'db_already_populated', 'migrated' => 0];
        }
        return $this->migrate_csv_to_db($area, $emodel, $edims);
    }

    /**
     * Settings updated-callback: on switching the backend to DB, import the CSV indexes once.
     *
     * Covers every CSV-backed global area (docs AND skills). Runs synchronously (both indexes are
     * small and no embedding calls are made, only row copies) and fail-open — per area, so a broken
     * import of one area never blocks the other; the readiness rebuild fallback still populates
     * whatever is missing in the DB.
     *
     * @param string $name The full setting name Moodle passes to updated-callbacks (unused).
     * @return void
     */
    public static function on_embeddingsstore_setting_updated(string $name = ''): void {
        unset($name);
        if (get_config('bookingextension_agent', 'embeddingsstore') !== 'db') {
            return;
        }
        try {
            $resolved = (new embeddings_action_config_resolver())->resolve();
        } catch (\Throwable $e) {
            debugging('embeddingsstore CSV→DB migration failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }
        foreach ([docs_row_mapper::AREA, skill_row_mapper::AREA] as $area) {
            try {
                (new self())->migrate_csv_to_db_if_needed(
                    $area,
                    (string)$resolved['model'],
                    (int)$resolved['dimensions']
                );
            } catch (\Throwable $e) {
                debugging(
                    'embeddingsstore CSV→DB migration failed (' . $area . '): ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }
}
