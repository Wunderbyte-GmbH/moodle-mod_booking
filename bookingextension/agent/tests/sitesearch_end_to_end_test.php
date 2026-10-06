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
use bookingextension_agent\local\wizard\core\skills\find_content_skill;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver;

/**
 * Whole-pipeline pin for the semantic site search: scope -> index -> search -> access gate.
 *
 * Uses an injected deterministic keyword embedder (no LLM): a document and a query that share
 * a keyword get near-parallel vectors, everything else is orthogonal noise. This pins the
 * mechanics end to end — chunking, generation commit, per-owner counts, cosine retrieval, the
 * two-gate access model (ch. 18 §2) and the freshness surface (#2341) — deterministically.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sitesearch_end_to_end_test extends advanced_testcase {
    /** @var string The label content area key. */
    private const AREA_LABEL = 'mod_label-activity';

    protected function setUp(): void {
        parent::setUp();
        sitesearch_scope_resolver::reset_request_cache();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
    }

    /**
     * Keyword embedder: dimension 0 fires on "waschbär", 1 on "segelboot", 2 is baseline noise.
     *
     * @return callable
     */
    private function embedder(): callable {
        return static function (string $text, int $contextid, int $userid, int $dims): array {
            $t = \core_text::strtolower($text);
            $vec = array_fill(0, max(3, $dims), 0.0);
            $vec[0] = (strpos($t, 'waschbär') !== false) ? 1.0 : 0.0;
            $vec[1] = (strpos($t, 'segelboot') !== false) ? 1.0 : 0.0;
            $vec[2] = 0.05;
            return array_slice($vec, 0, $dims);
        };
    }

    /**
     * Seed two courses with one label each and enable the label area site-wide.
     *
     * @return array{0:\stdClass,1:\stdClass} [course with raccoon label, course with sailboat label]
     */
    private function seed_world(): array {
        $gen = $this->getDataGenerator();
        $coursea = $gen->create_course();
        $courseb = $gen->create_course();
        $gen->create_module('label', ['course' => $coursea->id, 'intro' => 'Waschbären sind wunderbare Tiere.']);
        $gen->create_module('label', ['course' => $courseb->id, 'intro' => 'Segelboote gleiten über den See.']);
        (new sitesearch_scope_repository())->set_enabled(self::AREA_LABEL, true);
        sitesearch_scope_resolver::reset_request_cache();
        return [$coursea, $courseb];
    }

    /**
     * Scope -> index -> search: the indexed keyword content is found, ranked first, and the
     * per-owner count matches what was indexed.
     */
    public function test_index_and_search_roundtrip(): void {
        global $DB;
        $this->seed_world();

        $report = (new site_content_index_service($this->embedder()))->update();
        $this->assertSame('ok', (string)($report['status'] ?? ''), json_encode($report));
        $this->assertGreaterThanOrEqual(2, (int)($report['embedded'] ?? 0));

        $labelchunks = $DB->count_records('bx_agent_embeddings', ['area' => 'site_content', 'owner' => self::AREA_LABEL]);
        $this->assertSame(2, $labelchunks, 'both labels must be committed under the label owner');

        $search = new site_content_search_service($this->embedder());
        $hits = $search->search('Wo lese ich etwas über Waschbären?', 0, 5);
        $this->assertNotEmpty($hits, 'the indexed keyword content must be findable');
        $this->assertStringContainsString('Waschbär', (string)$hits[0]['title'], 'keyword match must rank first');

        $hits = $search->search('Etwas über Segelboote bitte', 0, 5);
        $this->assertStringContainsString('Segelboot', (string)$hits[0]['title']);
    }

    /**
     * Two-gate access model: a user without access to the course never receives the hit;
     * enrolling them makes the same search succeed.
     */
    public function test_access_gate_follows_enrolment(): void {
        [$coursea] = $this->seed_world();
        (new site_content_index_service($this->embedder()))->update();
        $search = new site_content_search_service($this->embedder());

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $titles = array_map(
            static fn(array $h): string => (string)$h['title'],
            $search->search('Waschbären', 0, 5)
        );
        $this->assertSame(
            [],
            preg_grep('/Waschbär/', $titles) ?: [],
            'content in an inaccessible course must never surface'
        );

        $this->getDataGenerator()->enrol_user($user->id, $coursea->id, 'student');
        $this->setUser($user);
        $titles = array_map(
            static fn(array $h): string => (string)$h['title'],
            $search->search('Waschbären', 0, 5)
        );
        $this->assertNotEmpty(
            preg_grep('/Waschbär/', $titles),
            'after enrolment the same search must succeed'
        );
    }

    /**
     * Decision on #2341: freshness lives on the governance banner ONLY — the chat answer of
     * find_content stays clean (no index-timestamp sentence appended by the skill).
     */
    public function test_chat_answer_stays_free_of_freshness_boilerplate(): void {
        $this->seed_world();
        (new site_content_index_service($this->embedder()))->update();

        $skill = new find_content_skill(new site_content_search_service($this->embedder()));
        $result = $skill->execute(
            ['query' => 'Gibt es hier etwas über Ufos?'],
            (int)\context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertStringNotContainsString('Search index as of', (string)($result['usermessage'] ?? ''));
        $this->assertStringNotContainsString('Search index as of', (string)($result['observation_full'] ?? ''));
    }
}
