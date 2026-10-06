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
 * Privacy API coverage of the site-content rows in {bx_agent_embeddings}.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\site_content_row_mapper;
use bookingextension_agent\privacy\provider;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;

/**
 * Verifies the provider covers the site-content embedding rows: context/user discovery, a minimal
 * human-meaningful export (no raw vectors), per-user deletion scoped to area + context + owner, and
 * context-wide deletion that never touches docs/skills rows (NULL contextid).
 *
 * Rows are fabricated straight through the DB store (the real write path of the indexer), so the
 * columns under test are exactly what production writes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\privacy\provider
 */
final class site_content_privacy_test extends \core_privacy\tests\provider_testcase {
    /** A user-content search area id, as the indexer would store it. */
    private const AREAKEY = 'mod_forum-post';

    /** Embedding model of the fabricated rows (the provider is variant-agnostic). */
    private const MODEL = 'fake-model';

    /** Embedding dimensions of the fabricated rows. */
    private const DIMS = 4;

    /**
     * A DB store wired with the site-content mapper only (all this test writes).
     *
     * @return db_embeddings_store
     */
    private function store(): db_embeddings_store {
        return new db_embeddings_store([site_content_row_mapper::AREA => new site_content_row_mapper()]);
    }

    /**
     * Write one site-content document (two chunks) through the store's real incremental write op.
     *
     * @param int $docid Source document id.
     * @param \context $context Context the content was indexed from.
     * @param int $courseid Course id.
     * @param int $owneruserid Authoring user id (0 = no personal author).
     * @param string $title Document title.
     * @return void
     */
    private function seed_site_doc(int $docid, \context $context, int $courseid, int $owneruserid, string $title): void {
        $rows = [];
        for ($chunkno = 0; $chunkno < 2; $chunkno++) {
            $rows[] = new embedding_row(
                site_content_row_mapper::AREA,
                self::AREAKEY,
                (string)$docid,
                $chunkno,
                $title,
                self::MODEL,
                self::DIMS,
                sha1($title . '#' . $chunkno),
                [1.0, 0.0, 0.0, 0.0],
                null,
                $docid,
                (int)$context->id,
                $courseid,
                $owneruserid
            );
        }
        $this->store()->replace_document(
            site_content_row_mapper::AREA,
            self::MODEL,
            self::DIMS,
            self::AREAKEY,
            (string)$docid,
            $rows
        );
    }

    /**
     * Insert one docs-area row (NULL contextid/courseid/owneruserid) the site handling must never touch.
     *
     * @return int The inserted row id.
     */
    private function seed_docs_row(): int {
        global $DB;
        return (int)$DB->insert_record('bx_agent_embeddings', (object)[
            'area' => 'docs',
            'owner' => 'user',
            'refkey' => 'guide.md#intro',
            'refindex' => 1,
            'endindex' => 20,
            'title' => 'Docs chunk',
            'emodel' => self::MODEL,
            'edims' => self::DIMS,
            'contenthash' => sha1('docs'),
            'identityhash' => sha1('user|guide.md#intro|1'),
            'generation' => 1,
            'embedding' => pack('g*', 1.0, 0.0, 0.0, 0.0),
            'docid' => null,
            'contextid' => null,
            'courseid' => null,
            'owneruserid' => null,
            'timemodified' => time(),
        ]);
    }

    /**
     * Number of stored site-content rows for a context/owner combination.
     *
     * @param int $contextid Context id.
     * @param int $owneruserid Owner user id.
     * @return int
     */
    private function count_site_rows(int $contextid, int $owneruserid): int {
        global $DB;
        return $DB->count_records('bx_agent_embeddings', [
            'area' => site_content_row_mapper::AREA,
            'contextid' => $contextid,
            'owneruserid' => $owneruserid,
        ]);
    }

    /**
     * Shared fixture: two module contexts, three docs (user A in both, user B in the first) plus one
     * docs-area row.
     *
     * @return array [user A, user B, context 1, context 2, course, docs row id]
     */
    private function seed_fixture(): array {
        $gen = $this->getDataGenerator();
        $usera = $gen->create_user();
        $userb = $gen->create_user();
        $course = $gen->create_course();
        $page1 = $gen->create_module('page', ['course' => $course->id]);
        $page2 = $gen->create_module('page', ['course' => $course->id]);
        $ctx1 = \context_module::instance($page1->cmid);
        $ctx2 = \context_module::instance($page2->cmid);

        $this->seed_site_doc(101, $ctx1, (int)$course->id, (int)$usera->id, 'Post by A in ctx1');
        $this->seed_site_doc(102, $ctx1, (int)$course->id, (int)$userb->id, 'Post by B in ctx1');
        $this->seed_site_doc(201, $ctx2, (int)$course->id, (int)$usera->id, 'Post by A in ctx2');
        $docsrowid = $this->seed_docs_row();

        return [$usera, $userb, $ctx1, $ctx2, $course, $docsrowid];
    }

    /**
     * get_metadata declares the embeddings table with the owner and provenance fields.
     */
    public function test_metadata_declares_embeddings_table(): void {
        $collection = provider::get_metadata(new collection('bookingextension_agent'));
        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
        }
        $this->assertContains('bx_agent_embeddings', $names);
    }

    /**
     * Context discovery returns exactly the contexts holding the user's site rows, and the userlist
     * finds the authoring users of a context — docs rows (NULL context/owner) never surface.
     */
    public function test_contexts_and_users_discovery(): void {
        $this->resetAfterTest();
        [$usera, $userb, $ctx1, $ctx2] = $this->seed_fixture();
        $stranger = $this->getDataGenerator()->create_user();

        $contextids = array_map('intval', provider::get_contexts_for_userid((int)$usera->id)->get_contextids());
        $this->assertContains((int)$ctx1->id, $contextids);
        $this->assertContains((int)$ctx2->id, $contextids);

        $contextidsb = array_map('intval', provider::get_contexts_for_userid((int)$userb->id)->get_contextids());
        $this->assertContains((int)$ctx1->id, $contextidsb);
        $this->assertNotContains((int)$ctx2->id, $contextidsb);

        $this->assertSame([], provider::get_contexts_for_userid((int)$stranger->id)->get_contextids());

        $userlist = new \core_privacy\local\request\userlist($ctx1, 'bookingextension_agent');
        provider::get_users_in_context($userlist);
        $found = array_map('intval', $userlist->get_userids());
        $this->assertContains((int)$usera->id, $found);
        $this->assertContains((int)$userb->id, $found);
        $this->assertNotContains((int)$stranger->id, $found);
    }

    /**
     * The export contains the human-meaningful reference data (area, title, chunk number, time) and
     * a derived-data note — never the raw vector, and never another user's rows.
     */
    public function test_export_shape(): void {
        $this->resetAfterTest();
        [$usera, $userb, $ctx1] = $this->seed_fixture();
        unset($userb);

        $this->export_context_data_for_user((int)$usera->id, $ctx1, 'bookingextension_agent');
        $writer = writer::with_context($ctx1);
        $this->assertTrue($writer->has_any_data());

        $subcontext = [get_string('privacy:metadata:bx_agent_embeddings', 'bookingextension_agent')];
        $data = $writer->get_data($subcontext);
        $this->assertNotEmpty($data->entries);
        // Only user A's document (two chunks), not user B's rows in the same context.
        $this->assertCount(2, $data->entries);
        foreach ($data->entries as $entry) {
            $this->assertSame(self::AREAKEY, $entry->searcharea);
            $this->assertSame('Post by A in ctx1', $entry->title);
            $this->assertObjectNotHasProperty('embedding', $entry);
        }
        // The vector is exported as a note about derived data, not as raw floats.
        $this->assertNotEmpty($data->note);
    }

    /**
     * delete_data_for_user removes only that user's site rows in the approved contexts.
     */
    public function test_delete_data_for_user_removes_only_own_rows(): void {
        global $DB;
        $this->resetAfterTest();
        [$usera, $userb, $ctx1, $ctx2, $course, $docsrowid] = $this->seed_fixture();
        unset($course);

        // Approve only ctx1: A's rows in ctx2 must survive.
        $approved = new approved_contextlist($usera, 'bookingextension_agent', [$ctx1->id]);
        provider::delete_data_for_user($approved);

        $this->assertSame(0, $this->count_site_rows((int)$ctx1->id, (int)$usera->id));
        $this->assertSame(2, $this->count_site_rows((int)$ctx1->id, (int)$userb->id));
        $this->assertSame(2, $this->count_site_rows((int)$ctx2->id, (int)$usera->id));
        $this->assertTrue($DB->record_exists('bx_agent_embeddings', ['id' => $docsrowid]));
    }

    /**
     * delete_data_for_users removes the approved users' site rows in the given context only.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        [$usera, $userb, $ctx1, $ctx2, $course, $docsrowid] = $this->seed_fixture();
        unset($course);

        $userlist = new approved_userlist($ctx1, 'bookingextension_agent', [(int)$usera->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $this->count_site_rows((int)$ctx1->id, (int)$usera->id));
        $this->assertSame(2, $this->count_site_rows((int)$ctx1->id, (int)$userb->id));
        $this->assertSame(2, $this->count_site_rows((int)$ctx2->id, (int)$usera->id));
        $this->assertTrue($DB->record_exists('bx_agent_embeddings', ['id' => $docsrowid]));
    }

    /**
     * A context-wide delete clears every embedding row of that context and leaves docs/skills rows
     * (NULL contextid) and other contexts untouched.
     */
    public function test_delete_all_users_in_context_spares_null_context_rows(): void {
        global $DB;
        $this->resetAfterTest();
        [$usera, $userb, $ctx1, $ctx2, $course, $docsrowid] = $this->seed_fixture();
        unset($userb, $course);

        provider::delete_data_for_all_users_in_context($ctx2);

        $this->assertSame(0, $DB->count_records('bx_agent_embeddings', ['contextid' => $ctx2->id]));
        $this->assertSame(4, $DB->count_records('bx_agent_embeddings', ['contextid' => $ctx1->id]));
        $this->assertSame(2, $this->count_site_rows((int)$ctx1->id, (int)$usera->id));
        $this->assertTrue($DB->record_exists('bx_agent_embeddings', ['id' => $docsrowid]));
    }
}
