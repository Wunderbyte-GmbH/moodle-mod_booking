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
use bookingextension_agent\local\wizard\services\sitesearch\index_scope_estimator;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver;

/**
 * Scope-aware estimates (context governance K3): course-scoped counts vs site-wide, the bounded
 * category sum over the category path, per-scope MUC caching, the ">N" abort bound, and the
 * effective coverage over the active rule cascade.
 *
 * The estimator never embeds — no provider is required for any of these tests.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\index_scope_estimator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sitesearch_scope_estimate_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        // The resolver memoizes per request; in PHPUnit one request spans every test (#2339).
        \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver::reset_request_cache();
    }

    /** The reference module area used throughout. */
    private const AREAKEY = 'mod_page-activity';

    /**
     * A course-scoped estimate counts ONLY that course's documents; the site scope keeps counting
     * everything, and the explicit site scope equals the estimate() shorthand.
     */
    public function test_course_scope_counts_only_that_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course1 = $generator->create_course();
        $course2 = $generator->create_course();
        foreach (['One', 'Two', 'Three'] as $name) {
            $generator->create_module('page', ['course' => $course1->id, 'name' => $name, 'content' => 'Body.']);
        }
        foreach (['Four', 'Five'] as $name) {
            $generator->create_module('page', ['course' => $course2->id, 'name' => $name, 'content' => 'Body.']);
        }

        $estimator = new index_scope_estimator();

        $incourse1 = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course1->id,
            false
        );
        $this->assertNotNull($incourse1);
        $this->assertSame(3, $incourse1['doccount']);
        $this->assertFalse($incourse1['capped']);

        $incourse2 = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course2->id,
            false
        );
        $this->assertNotNull($incourse2);
        $this->assertSame(2, $incourse2['doccount']);

        $site = $estimator->estimate_for_scope(self::AREAKEY, sitesearch_scope_repository::SCOPETYPE_SITE, 0, false);
        $this->assertNotNull($site);
        $this->assertSame(5, $site['doccount']);
        $this->assertSame($site, $estimator->estimate(self::AREAKEY));

        // Unknown areas stay null on every scope.
        $this->assertNull($estimator->estimate_for_scope(
            'mod_unknown-nowhere',
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course1->id,
            false
        ));
    }

    /**
     * A category estimate sums the courses of the category AND its subcategories (path-based,
     * matching the rule cascade) — never the courses of unrelated categories. A vanished
     * category yields null.
     */
    public function test_category_scope_sums_over_category_path(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $cata = $generator->create_category();
        $catasub = $generator->create_category(['parent' => $cata->id]);
        $catother = $generator->create_category();

        $courseina = $generator->create_course(['category' => $cata->id]);
        $courseinsub = $generator->create_course(['category' => $catasub->id]);
        $courseother = $generator->create_course(['category' => $catother->id]);

        $generator->create_module('page', ['course' => $courseina->id, 'name' => 'A1', 'content' => 'Body.']);
        $generator->create_module('page', ['course' => $courseina->id, 'name' => 'A2', 'content' => 'Body.']);
        $generator->create_module('page', ['course' => $courseinsub->id, 'name' => 'S1', 'content' => 'Body.']);
        foreach (['O1', 'O2', 'O3', 'O4'] as $name) {
            $generator->create_module('page', ['course' => $courseother->id, 'name' => $name, 'content' => 'Body.']);
        }

        $estimator = new index_scope_estimator();

        // Category A: its own course (2 docs) + the subcategory course (1 doc), nothing else.
        $estimate = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            (int)$cata->id,
            false
        );
        $this->assertNotNull($estimate);
        $this->assertSame(3, $estimate['doccount']);
        $this->assertSame(3, $estimate['estchunks']);
        $this->assertFalse($estimate['capped']);
        $this->assertSame('green', $estimate['ampel']);

        // The unrelated category only sees its own course.
        $other = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            (int)$catother->id,
            false
        );
        $this->assertNotNull($other);
        $this->assertSame(4, $other['doccount']);

        // A vanished category cannot be estimated.
        $this->assertNull($estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            999999,
            false
        ));
    }

    /**
     * The bounded category sum aborts at the red threshold and surfaces as capped (">N", red) —
     * the abort already fires inside the per-course count.
     */
    public function test_category_sum_aborts_at_red_threshold(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sitesearchampelred', '2', 'bookingextension_agent');

        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        for ($i = 1; $i <= 5; $i++) {
            $generator->create_module('page', ['course' => $course->id, 'name' => 'P' . $i, 'content' => 'Body.']);
        }

        $estimate = (new index_scope_estimator())->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            (int)$category->id,
            false
        );
        $this->assertNotNull($estimate);
        $this->assertTrue($estimate['capped']);
        $this->assertSame('red', $estimate['ampel']);
        // Aborted at the red threshold: reported as ">2", never the true 5.
        $this->assertSame(2, $estimate['doccount']);
    }

    /**
     * Estimates are MUC-cached PER SCOPE: the course figure stays cached while the site figure
     * (a different key) sees new content, and a purge refreshes the course figure.
     */
    public function test_scope_estimates_cached_per_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_module('page', ['course' => $course->id, 'name' => 'First', 'content' => 'Body.']);

        $estimator = new index_scope_estimator();
        $first = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id,
            false
        );
        $this->assertNotNull($first);
        $this->assertSame(1, $first['doccount']);

        $generator->create_module('page', ['course' => $course->id, 'name' => 'Second', 'content' => 'Body.']);

        // Same scope key → cached raw counts.
        $cached = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id,
            false
        );
        $this->assertNotNull($cached);
        $this->assertSame(1, $cached['doccount']);

        // The site scope is a DIFFERENT key and measures fresh.
        $site = $estimator->estimate(self::AREAKEY);
        $this->assertNotNull($site);
        $this->assertSame(2, $site['doccount']);

        // After a purge (what the repository does on rule/flag changes) the course figure is fresh.
        \cache::make('bookingextension_agent', 'sitesearchestimates')->purge();
        $fresh = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id,
            false
        );
        $this->assertNotNull($fresh);
        $this->assertSame(2, $fresh['doccount']);
    }

    /**
     * The wildcard 'area' (§3.0): its scope estimate is the bounded SUM of the per-area
     * estimates over every wildcard-covered area — at least the two areas with known documents —
     * and a capped sub-figure (red-threshold abort) propagates into the wildcard sum.
     */
    public function test_wildcard_estimate_sums_covered_areas(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        foreach (['One', 'Two', 'Three'] as $name) {
            $generator->create_module('page', ['course' => $course->id, 'name' => $name, 'content' => 'Body.']);
        }
        foreach (['U1', 'U2'] as $name) {
            $generator->create_module('url', [
                'course' => $course->id, 'name' => $name, 'externalurl' => 'https://example.com',
            ]);
        }

        $estimator = new index_scope_estimator();
        $wildcard = site_content_area_registry::WILDCARD;

        $page = $estimator->estimate_for_scope(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id,
            false
        );
        $url = $estimator->estimate_for_scope(
            'mod_url-activity',
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id,
            false
        );
        $this->assertSame(3, $page['doccount']);
        $this->assertSame(2, $url['doccount']);

        // The wildcard figure sums the covered areas (other areas may add more, never less).
        $sum = $estimator->estimate_for_scope(
            $wildcard,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id,
            false
        );
        // Areas without context-restriction support are skipped fail-soft with a developer
        // debugging note — irrelevant for the sum contract under test.
        $this->resetDebugging();
        $this->assertNotNull($sum);
        $this->assertGreaterThanOrEqual($page['doccount'] + $url['doccount'], $sum['doccount']);
        $this->assertGreaterThanOrEqual($page['estchunks'] + $url['estchunks'], $sum['estchunks']);

        // Capped propagation: with a tiny red threshold the per-area counting aborts, the
        // wildcard sum aborts too and surfaces as ">N" red (different cache key — no purge needed).
        set_config('sitesearchampelred', '2', 'bookingextension_agent');
        $capped = $estimator->estimate_for_scope(
            $wildcard,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id,
            false
        );
        $this->resetDebugging();
        $this->assertNotNull($capped);
        $this->assertTrue($capped['capped']);
        $this->assertSame('red', $capped['ampel']);
        $this->assertLessThanOrEqual($sum['estchunks'], $capped['estchunks']);
    }

    /**
     * Effective coverage follows the active rule cascade: no rules = null (no coverage), an
     * allowlist of one enabled course rule covers exactly that course's figures.
     *
     * Depends on the K1 scope resolver (§3.1 contract); skipped until it lands.
     */
    public function test_effective_coverage_follows_active_rules(): void {
        if (!class_exists(sitesearch_scope_resolver::class)) {
            $this->markTestSkipped('sitesearch_scope_resolver (K1) not available yet.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course1 = $generator->create_course();
        $course2 = $generator->create_course();
        foreach (['One', 'Two', 'Three'] as $name) {
            $generator->create_module('page', ['course' => $course1->id, 'name' => $name, 'content' => 'Body.']);
        }
        $generator->create_module('page', ['course' => $course2->id, 'name' => 'Other', 'content' => 'Body.']);

        $estimator = new index_scope_estimator();

        // No rule grants anything → no effective coverage.
        $this->assertNull($estimator->estimate_effective(self::AREAKEY));

        // Site off + one enabled course rule = allowlist of exactly that course.
        (new sitesearch_scope_repository())->set_enabled(
            self::AREAKEY,
            true,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course1->id
        );
        $effective = $estimator->estimate_effective(self::AREAKEY);
        $this->assertNotNull($effective);
        $this->assertTrue($effective['measured']);
        $this->assertSame(1, $effective['courses']);
        $this->assertSame(3, $effective['estchunks']);
        $this->assertFalse($effective['capped']);
        $this->assertSame('green', $effective['ampel']);
    }
}
