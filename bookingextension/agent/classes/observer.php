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
 * Event observers for bookingextension_agent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;

/**
 * Deletion observers keeping the DB embeddings store in sync with the source content.
 *
 * These are a MANDATORY part of the strictly incremental site indexing model: without a
 * generation swap per run, nothing prunes implicitly any more — deleted modules/courses are
 * only ever removed from the index through these observers (plus doc-level deletes detected
 * by the indexing cron). Core's own pendant is context::delete() -> manager::context_deleted().
 *
 * Guarded cheaply: they only act when the DB embeddings store is the active backend (nothing is
 * instantiated otherwise), and they never throw — a failing index cleanup must not break the
 * actual course/module deletion.
 */
class observer {
    /**
     * A course module was deleted: prune its embedding rows by module context id.
     *
     * @param \core\event\course_module_deleted $event
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        self::with_db_store(static function (db_embeddings_store $store) use ($event): void {
            $store->delete_by_context((int)$event->contextid);
        });
    }

    /**
     * A course was deleted: prune all of its embedding rows by course id.
     *
     * Uses delete_by_course (not delete_by_context): the stored rows carry MODULE context ids,
     * but this event only provides the course context — the module contexts are no longer
     * enumerable at this point. Every site row also carries the course id.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        self::with_db_store(static function (db_embeddings_store $store) use ($event): void {
            $store->delete_by_course((int)$event->courseid);
        });
    }

    /**
     * A course's content was deleted (course reset/emptied): prune its embedding rows by course id.
     *
     * @param \core\event\course_content_deleted $event
     * @return void
     */
    public static function course_content_deleted(\core\event\course_content_deleted $event): void {
        self::with_db_store(static function (db_embeddings_store $store) use ($event): void {
            $store->delete_by_course((int)$event->courseid);
        });
    }

    /**
     * Run a cleanup against the DB store — only when it is the active backend, and never throwing.
     *
     * The config check comes first so that on CSV-backed (or unconfigured) sites no store, mapper or
     * anything else is ever instantiated on these hot core events.
     *
     * @param callable $callback Receives the db_embeddings_store.
     * @return void
     */
    private static function with_db_store(callable $callback): void {
        try {
            if (get_config('bookingextension_agent', 'embeddingsstore') !== 'db') {
                return;
            }
            $callback(new db_embeddings_store(embeddings_store_factory::mappers()));
        } catch (\Throwable $e) {
            // Never break the surrounding deletion; the stale rows are re-pruned by the next index run.
            debugging('bookingextension_agent embeddings cleanup observer failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
