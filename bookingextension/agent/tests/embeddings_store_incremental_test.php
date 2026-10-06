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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\retrieval\csv_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\site_content_row_mapper;

/**
 * Behaviour tests for the incremental document operations of the embeddings store and the
 * deletion observers that keep the DB store in sync with the source content.
 *
 * Uses the site_content area with a throwaway variant. Vector assertions use values that are
 * exactly representable in float32 so the pack/unpack round-trip is bit-exact.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\csv_embeddings_store
 * @covers     \bookingextension_agent\observer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_store_incremental_test extends advanced_testcase {
    /** Test embedding model. */
    private const MODEL = 'test-model';

    /** Test embedding dimensions. */
    private const DIMS = 4;

    /** Area under test. */
    private const AREA = site_content_row_mapper::AREA;

    /** Owner (core_search area id) used by most tests. */
    private const OWNER = 'mod_page-activity';

    /**
     * Build a fresh DB store over the registered mappers.
     *
     * @return db_embeddings_store
     */
    private function store(): db_embeddings_store {
        return new db_embeddings_store(embeddings_store_factory::mappers());
    }

    /**
     * Build one site-content chunk row.
     *
     * @param string $owner
     * @param string $docid
     * @param int $chunkno
     * @param float[] $vec
     * @param string $hash
     * @param int|null $contextid
     * @param int|null $courseid
     * @param string|null $emodel Embedding model override (defaults to the test model).
     * @return embedding_row
     */
    private function chunkrow(
        string $owner,
        string $docid,
        int $chunkno,
        array $vec,
        string $hash,
        ?int $contextid = 900,
        ?int $courseid = 77,
        ?string $emodel = null
    ): embedding_row {
        return new embedding_row(
            self::AREA,
            $owner,
            $docid,
            $chunkno,
            'Chunk ' . $chunkno . ' of doc ' . $docid,
            $emodel ?? self::MODEL,
            self::DIMS,
            $hash,
            $vec,
            null,
            (int)$docid,
            $contextid,
            $courseid,
            null
        );
    }

    /**
     * Fetch one document's stored records, keyed by chunk number.
     *
     * @param string $owner
     * @param string $docid
     * @return \stdClass[]
     */
    private function docrecords(string $owner, string $docid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('bx_agent_embeddings', ['area' => self::AREA, 'owner' => $owner]) as $rec) {
            if ((string)$rec->refkey === $docid) {
                $out[(int)$rec->refindex] = $rec;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * replace_document is diff-based: an unchanged chunk keeps the SAME row id and is physically
     * untouched, a changed hash updates in place (same id), a vanished chunk number is deleted and a
     * new one inserted — with correct stats on both calls.
     */
    public function test_replace_document_diff_semantics(): void {
        global $DB;
        $this->resetAfterTest();
        $store = $this->store();

        $stats = $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '42', [
            $this->chunkrow(self::OWNER, '42', 0, [1.0, 0.0, 0.0, 0.0], 'hash-a'),
            $this->chunkrow(self::OWNER, '42', 1, [0.0, 1.0, 0.0, 0.0], 'hash-b'),
            $this->chunkrow(self::OWNER, '42', 2, [0.0, 0.0, 1.0, 0.0], 'hash-c'),
        ]);
        $this->assertSame(['inserted' => 3, 'updated' => 0, 'deleted' => 0, 'kept' => 0], $stats);

        $before = $this->docrecords(self::OWNER, '42');
        $this->assertSame([0, 1, 2], array_keys($before));
        // Sentinel timestamp on the chunk that will stay identical: if the second call rewrites the
        // row, the sentinel is lost — this proves "physically untouched", not just "same content".
        $DB->set_field('bx_agent_embeddings', 'timemodified', 12345, ['id' => $before[0]->id]);

        // Second run: chunk 0 identical, chunk 1 changed, chunk 2 gone, chunk 3 new.
        $stats = $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '42', [
            $this->chunkrow(self::OWNER, '42', 0, [1.0, 0.0, 0.0, 0.0], 'hash-a'),
            $this->chunkrow(self::OWNER, '42', 1, [0.5, 0.25, 0.0, 0.0], 'hash-b2'),
            $this->chunkrow(self::OWNER, '42', 3, [0.0, 0.0, 0.0, 1.0], 'hash-d'),
        ]);
        $this->assertSame(['inserted' => 1, 'updated' => 1, 'deleted' => 1, 'kept' => 1], $stats);

        $after = $this->docrecords(self::OWNER, '42');
        $this->assertSame([0, 1, 3], array_keys($after));

        // Kept chunk: same DB id, physically untouched (sentinel survived).
        $this->assertSame((int)$before[0]->id, (int)$after[0]->id);
        $this->assertSame(12345, (int)$after[0]->timemodified);

        // Changed chunk: updated in place — same DB id, new hash and vector.
        $this->assertSame((int)$before[1]->id, (int)$after[1]->id);
        $this->assertSame('hash-b2', $after[1]->contenthash);

        // New chunk got a fresh row; the vanished chunk 2 row is gone.
        $this->assertSame(0, $DB->count_records('bx_agent_embeddings', ['id' => $before[2]->id]));

        $rows = [];
        foreach ($store->stream_rows(self::AREA, self::MODEL, self::DIMS) as $row) {
            $rows[$row->refindex] = $row;
        }
        $this->assertSame([0.5, 0.25, 0.0, 0.0], $rows[1]->embedding);
        $this->assertSame('hash-d', $rows[3]->contenthash);
    }

    /**
     * Generation bootstrap: on a fresh variant (even one whose meta row was pre-created at
     * committedgeneration = 0 by set_fingerprint), the first incremental write publishes
     * generation 1 — so search_top_k, which scans the committed generation, finds the rows.
     */
    public function test_replace_document_generation_bootstrap(): void {
        global $DB;
        $this->resetAfterTest();
        $store = $this->store();

        // Pre-create the meta row at committedgeneration = 0 to cover the "row exists but nothing
        // committed" branch as well as the "no row" one (a second variant below covers the latter).
        $store->set_fingerprint(self::AREA, self::MODEL, self::DIMS, 'fp-1');

        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '7', [
            $this->chunkrow(self::OWNER, '7', 0, [1.0, 0.0, 0.0, 0.0], 'hash-a'),
        ]);
        $meta = ['area' => self::AREA, 'emodel' => self::MODEL, 'edims' => self::DIMS];
        $this->assertSame(1, (int)$DB->get_field('bx_agent_embeddings_meta', 'committedgeneration', $meta));

        $hits = $store->search_top_k(self::AREA, self::MODEL, self::DIMS, [1.0, 0.0, 0.0, 0.0], 5, 0.0);
        $this->assertCount(1, $hits);
        $this->assertSame('7', $hits[0]->refkey);

        // Fresh variant without any meta row: bootstrap creates it with committedgeneration = 1.
        $store->replace_document(self::AREA, 'other-model', self::DIMS, self::OWNER, '7', [
            $this->chunkrow(self::OWNER, '7', 0, [0.0, 1.0, 0.0, 0.0], 'hash-b', 900, 77, 'other-model'),
        ]);
        $meta['emodel'] = 'other-model';
        $this->assertSame(1, (int)$DB->get_field('bx_agent_embeddings_meta', 'committedgeneration', $meta));
        $this->assertSame(1, $store->count_rows(self::AREA, 'other-model', self::DIMS));
    }

    /**
     * delete_document removes exactly one document's chunks; siblings of the same owner remain.
     */
    public function test_delete_document(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1', [
            $this->chunkrow(self::OWNER, '1', 0, [1.0, 0.0, 0.0, 0.0], 'h1'),
            $this->chunkrow(self::OWNER, '1', 1, [0.0, 1.0, 0.0, 0.0], 'h2'),
        ]);
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '2', [
            $this->chunkrow(self::OWNER, '2', 0, [0.0, 0.0, 1.0, 0.0], 'h3'),
        ]);
        $this->assertSame(3, $store->count_rows(self::AREA, self::MODEL, self::DIMS));

        $store->delete_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1');
        $this->assertSame(1, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame([], $this->docrecords(self::OWNER, '1'));
        $this->assertCount(1, $this->docrecords(self::OWNER, '2'));
    }

    /**
     * delete_owner prunes a whole sub-area (all documents of one core_search area), leaving other
     * owners of the same variant untouched.
     */
    public function test_delete_owner(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1', [
            $this->chunkrow(self::OWNER, '1', 0, [1.0, 0.0, 0.0, 0.0], 'h1'),
        ]);
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, 'mod_book-chapter', '1', [
            $this->chunkrow('mod_book-chapter', '1', 0, [0.0, 1.0, 0.0, 0.0], 'h2'),
        ]);
        $this->assertSame(2, $store->count_rows(self::AREA, self::MODEL, self::DIMS));

        $store->delete_owner(self::AREA, self::MODEL, self::DIMS, self::OWNER);
        $this->assertSame(1, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertCount(1, $this->docrecords('mod_book-chapter', '1'));
    }

    /**
     * delete_by_course removes only the rows carrying that course id; other courses and rows without
     * a course id (docs/skills) are untouched.
     */
    public function test_delete_by_course(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1', [
            $this->chunkrow(self::OWNER, '1', 0, [1.0, 0.0, 0.0, 0.0], 'h1', 900, 77),
        ]);
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '2', [
            $this->chunkrow(self::OWNER, '2', 0, [0.0, 1.0, 0.0, 0.0], 'h2', 901, 78),
        ]);
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '3', [
            $this->chunkrow(self::OWNER, '3', 0, [0.0, 0.0, 1.0, 0.0], 'h3', null, null),
        ]);

        $store->delete_by_course(77);
        $this->assertSame(2, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame([], $this->docrecords(self::OWNER, '1'));
        $this->assertCount(1, $this->docrecords(self::OWNER, '2'));
        $this->assertCount(1, $this->docrecords(self::OWNER, '3'));
    }

    /**
     * delete_owner_in_course (scope-governance prune op, §4.2) removes only the rows of ONE owner
     * within ONE course: the same owner's rows in other courses and other owners' rows in the same
     * course stay untouched.
     */
    public function test_delete_owner_in_course(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1', [
            $this->chunkrow(self::OWNER, '1', 0, [1.0, 0.0, 0.0, 0.0], 'h1', 900, 77),
        ]);
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '2', [
            $this->chunkrow(self::OWNER, '2', 0, [0.0, 1.0, 0.0, 0.0], 'h2', 901, 78),
        ]);
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, 'mod_book-chapter', '3', [
            $this->chunkrow('mod_book-chapter', '3', 0, [0.0, 0.0, 1.0, 0.0], 'h3', 902, 77),
        ]);

        $store->delete_owner_in_course(self::AREA, self::MODEL, self::DIMS, self::OWNER, 77);
        $this->assertSame(2, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame([], $this->docrecords(self::OWNER, '1'));
        $this->assertCount(1, $this->docrecords(self::OWNER, '2'));
        $this->assertCount(1, $this->docrecords('mod_book-chapter', '3'));
    }

    /**
     * The CSV backend fails closed on every incremental document operation (they are DB-only).
     */
    public function test_csv_store_throws_on_incremental_ops(): void {
        $this->resetAfterTest();
        $store = new csv_embeddings_store(embeddings_store_factory::mappers());

        $ops = [
            'replace_document' => function () use ($store): void {
                $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1', []);
            },
            'delete_document' => function () use ($store): void {
                $store->delete_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1');
            },
            'delete_owner' => function () use ($store): void {
                $store->delete_owner(self::AREA, self::MODEL, self::DIMS, self::OWNER);
            },
            'delete_owner_in_course' => function () use ($store): void {
                $store->delete_owner_in_course(self::AREA, self::MODEL, self::DIMS, self::OWNER, 77);
            },
            'delete_by_course' => function () use ($store): void {
                $store->delete_by_course(77);
            },
        ];
        foreach ($ops as $name => $op) {
            try {
                $op();
                $this->fail('csv_embeddings_store::' . $name . ' should have thrown a coding_exception.');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString(
                    'Incremental document operations require the DB embeddings store.',
                    $e->getMessage(),
                    $name
                );
            }
        }
    }

    /**
     * End to end: deleting a course module fires course_module_deleted through db/events.php and the
     * observer prunes the module's rows from the DB store (embeddingsstore = db).
     */
    public function test_observer_prunes_rows_on_course_module_deleted(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('embeddingsstore', 'db', 'bookingextension_agent');

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);

        $store = $this->store();
        $docid = (string)$page->id;
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, $docid, [
            $this->chunkrow(self::OWNER, $docid, 0, [1.0, 0.0, 0.0, 0.0], 'h1', $context->id, (int)$course->id),
            $this->chunkrow(self::OWNER, $docid, 1, [0.0, 1.0, 0.0, 0.0], 'h2', $context->id, (int)$course->id),
        ]);
        $this->assertSame(2, $store->count_rows(self::AREA, self::MODEL, self::DIMS));

        require_once($CFG->dirroot . '/course/lib.php');
        course_delete_module($page->cmid);

        $this->assertSame(0, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
    }

    /**
     * The observer is inert unless the DB store is the active backend: with embeddingsstore = csv the
     * event changes nothing (and instantiates nothing — a call-through would throw/debug).
     */
    public function test_observer_inert_when_db_store_not_active(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('embeddingsstore', 'csv', 'bookingextension_agent');

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);

        $store = $this->store();
        $docid = (string)$page->id;
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, $docid, [
            $this->chunkrow(self::OWNER, $docid, 0, [1.0, 0.0, 0.0, 0.0], 'h1', $context->id, (int)$course->id),
        ]);

        $event = \core\event\course_module_deleted::create([
            'objectid' => $page->cmid,
            'context' => $context,
            'courseid' => (int)$course->id,
            'other' => ['modulename' => 'page', 'instanceid' => $page->id],
        ]);
        observer::course_module_deleted($event);

        $this->assertSame(1, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
    }

    /**
     * course_deleted prunes by COURSE id (module contexts are gone by then; rows carry courseid), and
     * leaves other courses' rows alone.
     */
    public function test_observer_course_deleted_prunes_by_courseid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('embeddingsstore', 'db', 'bookingextension_agent');

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $store = $this->store();
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '1', [
            $this->chunkrow(self::OWNER, '1', 0, [1.0, 0.0, 0.0, 0.0], 'h1', 900, (int)$course->id),
        ]);
        $store->replace_document(self::AREA, self::MODEL, self::DIMS, self::OWNER, '2', [
            $this->chunkrow(self::OWNER, '2', 0, [0.0, 1.0, 0.0, 0.0], 'h2', 901, (int)$other->id),
        ]);

        $event = \core\event\course_deleted::create([
            'objectid' => $course->id,
            'context' => \context_course::instance($course->id),
            'other' => [
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'idnumber' => $course->idnumber,
            ],
        ]);
        $event->add_record_snapshot('course', $course);
        observer::course_deleted($event);

        $this->assertSame(1, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame([], $this->docrecords(self::OWNER, '1'));
        $this->assertCount(1, $this->docrecords(self::OWNER, '2'));
    }
}
