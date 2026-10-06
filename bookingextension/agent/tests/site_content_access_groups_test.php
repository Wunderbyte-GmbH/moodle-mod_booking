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
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;

/**
 * Site-content search access model, blueprint §10 Phase-3 cases (a)/(b) on a separate-groups forum.
 *
 * The forum module is the make-or-break case for the two-gate model: for an enrolled user of the
 * OTHER group the forum is uservisible, so the engine-free context PREFILTER lets the candidate
 * through — only the authoritative per-hit `check_access()` (forum_user_can_see_post) may remove
 * it. These tests prove that gate, plus the unenrolled/guest zero-result case.
 *
 * Requires the DYNAMIC search-area registry: `mod_forum-post` is enabled purely via governance
 * ({@see sitesearch_scope_repository}), the registry must enumerate it from core_search. Uses an
 * injected deterministic embedder so the whole path runs without the LLM provider; the embeddings
 * provider CLASS still has to exist for the readiness gate, so the suite skips where it is absent.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_content_access_groups_test extends advanced_testcase {
    /** The forum-posts search area id. */
    private const AREAKEY = 'mod_forum-post';

    /**
     * A deterministic embedder returning a fixed unit vector of the requested dimensionality.
     *
     * @return callable
     */
    private function fake_embedder(): callable {
        return function (string $text, int $contextid, int $userid, int $dims): array {
            unset($text, $contextid, $userid);
            $vector = array_fill(0, max(1, $dims), 0.0);
            $vector[0] = 1.0;
            return $vector;
        };
    }

    /**
     * Enable the DB backend and the forum-posts area; skip if the provider class is absent.
     *
     * @return void
     */
    private function enable_site_search(): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        (new sitesearch_scope_repository())->set_enabled(self::AREAKEY, true);
    }

    /**
     * Build the separate-groups forum fixture and index it.
     *
     * Course with a SEPARATEGROUPS forum, two groups, and one discussion posted into group 1 by the
     * group-1 member. Both users are enrolled students, so for BOTH the forum is uservisible (the
     * context prefilter passes) — group separation is enforced by `check_access()` only.
     *
     * @return array [course, group-1 user, group-2 user, first post id]
     */
    private function build_and_index_groups_fixture(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user1 = $gen->create_user();
        $user2 = $gen->create_user();
        $gen->enrol_user($user1->id, $course->id, 'student');
        $gen->enrol_user($user2->id, $course->id, 'student');

        $group1 = $gen->create_group(['courseid' => $course->id]);
        $group2 = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $group1->id, 'userid' => $user1->id]);
        $gen->create_group_member(['groupid' => $group2->id, 'userid' => $user2->id]);

        $forum = $gen->create_module('forum', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $discussion = $gen->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user1->id,
            'groupid' => $group1->id,
            'name' => 'Group one planning',
            'message' => 'Secret enrolment planning notes for group one only.',
        ]);
        $postid = (int)$discussion->firstpost;

        // Index with the deterministic embedder (indexing is deliberately group-agnostic: the
        // recordset contains everything, only retrieval filters).
        $this->setAdminUser();
        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertSame('ok', $result['status']);
        // DEPENDS ON THE DYNAMIC AREA REGISTRY (blueprint §11.27): the area was enabled through
        // governance only, so it must have been enumerated from core_search and indexed.
        $this->assertArrayHasKey(
            self::AREAKEY,
            $result['areas'],
            'The registry did not enumerate mod_forum-post - dynamic area registry not in place?'
        );
        $this->assertSame('ok', $result['areas'][self::AREAKEY]['status']);
        $this->assertGreaterThanOrEqual(1, $result['embedded']);

        return [$course, $user1, $user2, $postid];
    }

    /**
     * The docids returned by a search for the current user.
     *
     * @param string $query
     * @return int[]
     */
    private function search_docids(string $query): array {
        $hits = (new site_content_search_service($this->fake_embedder()))->search($query, 0, 5);
        return array_map(static fn(array $r): int => $r['docid'], $hits);
    }

    /**
     * Blueprint §10 case (b): the group-1 member finds the post; the enrolled group-2 member passes
     * the prefilter (uservisible forum) but is removed by the authoritative check_access gate.
     */
    public function test_separate_groups_post_hidden_from_other_group(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        [$course, $user1, $user2, $postid] = $this->build_and_index_groups_fixture();

        // The group-1 member gets the hit, with a working forum deep link.
        $this->setUser($user1);
        $hits = (new site_content_search_service($this->fake_embedder()))->search('enrolment', 0, 5);
        $docids = array_map(static fn(array $r): int => $r['docid'], $hits);
        $this->assertContains($postid, $docids);
        $match = array_values(array_filter($hits, static fn(array $r): bool => $r['docid'] === $postid))[0];
        $this->assertStringContainsString('/mod/forum/discuss.php', $match['url']);

        // The group-2 member: the forum IS uservisible (prefilter passes)...
        $this->setUser($user2);
        $modinfo = get_fast_modinfo($course, (int)$user2->id);
        $cms = $modinfo->get_instances_of('forum');
        $this->assertTrue(reset($cms)->uservisible);
        // ...so ONLY check_access (ACCESS_DENIED via forum_user_can_see_post) can remove the hit.
        $this->assertNotContains($postid, $this->search_docids('enrolment'));
    }

    /**
     * Blueprint §10 case (a): an unenrolled user — and the guest — get nothing from that course.
     */
    public function test_unenrolled_and_guest_get_nothing(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->build_and_index_groups_fixture();

        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->assertSame([], $this->search_docids('enrolment'));

        $this->setGuestUser();
        $this->assertSame([], $this->search_docids('enrolment'));
    }
}
