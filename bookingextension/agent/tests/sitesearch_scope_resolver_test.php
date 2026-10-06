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

declare(strict_types=1);

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver;

/**
 * The scope resolver's frozen §3.1 contract: specificity cascade with pair semantics, path-based
 * category inheritance, the three shape strategies, the bounded allowed-course classification,
 * site-only ('other'-contextsupport) areas, and the registry's any-coverage semantics on top.
 *
 * Pure governance logic — no embeddings provider, no store backend needed.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sitesearch_scope_resolver_test extends advanced_testcase {
    /** The reference module area used throughout. */
    private const AREAKEY = 'mod_page-activity';

    /** A site-only ('other' contextsupport) area. */
    private const OTHERAREA = 'core_user-user';

    /**
     * Reset the resolver's request-static caches before every test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        sitesearch_scope_resolver::reset_request_cache();
    }

    /**
     * Nested category tree with one course per level plus an outside course:
     * cat A > cat B; course c1 in A, c2 in B, c3 in C (sibling).
     *
     * @return array [catA, catB, catC, c1, c2, c3]
     */
    private function make_tree(): array {
        $gen = $this->getDataGenerator();
        $cata = $gen->create_category(['name' => 'A']);
        $catb = $gen->create_category(['name' => 'B', 'parent' => $cata->id]);
        $catc = $gen->create_category(['name' => 'C']);
        $c1 = $gen->create_course(['category' => $cata->id]);
        $c2 = $gen->create_course(['category' => $catb->id]);
        $c3 = $gen->create_course(['category' => $catc->id]);
        return [$cata, $catb, $catc, $c1, $c2, $c3];
    }

    /**
     * Default OFF everywhere: no rule rows → effective off, shape 'off', allowed set empty.
     */
    public function test_default_off(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $resolver = new sitesearch_scope_resolver();
        $this->assertSame(
            ['enabled' => false, 'includefiles' => false],
            $resolver->effective(self::AREAKEY, (int)$course->id)
        );
        $shape = $resolver->shape(self::AREAKEY);
        $this->assertSame('off', $shape['strategy']);
        $this->assertSame([], $shape['allowedcourseids']);
        $this->assertSame([], $shape['excludedcourseids']);
        $this->assertSame(['enabled' => false, 'includefiles' => false], $shape['sitedefault']);
        $this->assertSame([], $resolver->allowed_courseids(self::AREAKEY));
    }

    /**
     * Cascade with pair semantics: the most specific row wins with BOTH flags as a pair —
     * a category row does not inherit the site row's includefiles, a course row does not
     * inherit its category's.
     */
    public function test_cascade_pair_semantics(): void {
        $this->resetAfterTest();
        [$cata, , , $c1, $c2, $c3] = $this->make_tree();
        $repo = new sitesearch_scope_repository();
        $resolver = new sitesearch_scope_resolver();

        // Site default: enabled WITH files.
        $repo->set_enabled(self::AREAKEY, true);
        $repo->set_includefiles(self::AREAKEY, true);
        // Category A: enabled WITHOUT files (pair overrides the site pair completely).
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        // Course c2: enabled WITH files (pair overrides the category pair completely).
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c2->id);
        $repo->set_includefiles(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c2->id);

        // Course c1 (in A): category pair (on, files OFF) — files NOT inherited from the site row.
        $this->assertSame(
            ['enabled' => true, 'includefiles' => false],
            $resolver->effective(self::AREAKEY, (int)$c1->id)
        );
        // Course c2 (in B under A): the course row wins over the inherited category rule.
        $this->assertSame(
            ['enabled' => true, 'includefiles' => true],
            $resolver->effective(self::AREAKEY, (int)$c2->id)
        );
        // Course c3 (elsewhere): the site pair applies.
        $this->assertSame(
            ['enabled' => true, 'includefiles' => true],
            $resolver->effective(self::AREAKEY, (int)$c3->id)
        );
        // No course dimension → site default.
        $this->assertSame(
            ['enabled' => true, 'includefiles' => true],
            $resolver->effective(self::AREAKEY, 0)
        );
    }

    /**
     * Path-based category inheritance: a rule on a parent category covers courses of all
     * subcategories, until a DEEPER category row overrides it.
     */
    public function test_deepest_category_on_path_wins(): void {
        $this->resetAfterTest();
        [$cata, $catb, , $c1, $c2, $c3] = $this->make_tree();
        $repo = new sitesearch_scope_repository();
        $resolver = new sitesearch_scope_resolver();

        // Category A enabled, no site row: covers c1 (direct) AND c2 (inherited via path).
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        $this->assertTrue($resolver->effective(self::AREAKEY, (int)$c1->id)['enabled']);
        $this->assertTrue($resolver->effective(self::AREAKEY, (int)$c2->id)['enabled']);
        $this->assertFalse($resolver->effective(self::AREAKEY, (int)$c3->id)['enabled']);
        $this->assertEqualsCanonicalizing(
            [(int)$c1->id, (int)$c2->id],
            $resolver->allowed_courseids(self::AREAKEY)
        );

        // The deeper category B row (disabled) overrides A for c2 only.
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$catb->id);
        $this->assertTrue($resolver->effective(self::AREAKEY, (int)$c1->id)['enabled']);
        $this->assertFalse($resolver->effective(self::AREAKEY, (int)$c2->id)['enabled']);
        $this->assertSame([(int)$c1->id], $resolver->allowed_courseids(self::AREAKEY));
    }

    /**
     * Shape strategies: 'allowlist' (site off + scoped enablement), 'blocklist' (site on +
     * exclusions), 'off' when only non-granting rows exist.
     */
    public function test_shape_strategies(): void {
        $this->resetAfterTest();
        [, , , $c1, $c2, $c3] = $this->make_tree();
        $repo = new sitesearch_scope_repository();
        $resolver = new sitesearch_scope_resolver();

        // Allowlist: no site row, one enabled course row.
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $shape = $resolver->shape(self::AREAKEY);
        $this->assertSame('allowlist', $shape['strategy']);
        $this->assertSame([(int)$c1->id], $shape['allowedcourseids']);
        $this->assertSame([], $shape['excludedcourseids']);
        $this->assertSame(['enabled' => false, 'includefiles' => false], $shape['sitedefault']);

        // Blocklist: site on, one disabled course row → that course is the exclusion.
        $repo->set_enabled(self::AREAKEY, true);
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c2->id);
        $shape = $resolver->shape(self::AREAKEY);
        $this->assertSame('blocklist', $shape['strategy']);
        $this->assertSame([], $shape['allowedcourseids']);
        $this->assertSame([(int)$c2->id], $shape['excludedcourseids']);
        $this->assertSame(['enabled' => true, 'includefiles' => false], $shape['sitedefault']);
        $allowed = $resolver->allowed_courseids(self::AREAKEY);
        $this->assertContains((int)$c1->id, $allowed);
        $this->assertContains((int)$c3->id, $allowed);
        $this->assertNotContains((int)$c2->id, $allowed);

        // Only non-granting rows left: site off again → nothing grants → 'off'.
        $repo->set_enabled(self::AREAKEY, false);
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $shape = $resolver->shape(self::AREAKEY);
        $this->assertSame('off', $shape['strategy']);
        $this->assertSame([], $resolver->allowed_courseids(self::AREAKEY));
    }

    /**
     * Site-only areas ('other' contextsupport, §9): category/course rules are inert — the site
     * row alone decides, the allowed course set stays empty (no course dimension).
     */
    public function test_site_only_areas_ignore_scoped_rules(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repo = new sitesearch_scope_repository();
        $resolver = new sitesearch_scope_resolver();

        // A scoped rule alone grants nothing for a site-only area.
        $repo->set_enabled(self::OTHERAREA, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$course->id);
        $this->assertFalse($resolver->effective(self::OTHERAREA, (int)$course->id)['enabled']);
        $this->assertSame('off', $resolver->shape(self::OTHERAREA)['strategy']);
        $this->assertSame([], $resolver->allowed_courseids(self::OTHERAREA));

        // The site row governs — even a disabled course row cannot override it.
        $repo->set_enabled(self::OTHERAREA, true);
        $repo->set_enabled(self::OTHERAREA, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$course->id);
        $this->assertTrue($resolver->effective(self::OTHERAREA, (int)$course->id)['enabled']);
        $this->assertSame('blocklist', $resolver->shape(self::OTHERAREA)['strategy']);
        $this->assertSame([], $resolver->shape(self::OTHERAREA)['excludedcourseids']);
        $this->assertSame([], $resolver->allowed_courseids(self::OTHERAREA));
    }

    /**
     * The registry's any-coverage semantics: an area with ONLY scoped enablement counts as
     * enabled (site row not required); for 'other'-support areas scoped rows do NOT count.
     * Files coverage follows the pair semantics (flag on a disabled row grants nothing).
     */
    public function test_registry_any_coverage_semantics(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repo = new sitesearch_scope_repository();
        $registry = new site_content_area_registry();

        $this->assertSame([], $registry->enabled_area_keys());

        // A single enabled course rule makes the module area covered.
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$course->id);
        $this->assertSame([self::AREAKEY], $registry->enabled_area_keys());
        // ...but scoped coverage does not count for a site-only area.
        $repo->set_enabled(self::OTHERAREA, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$course->id);
        $this->assertSame([self::AREAKEY], $registry->enabled_area_keys());
        $repo->set_enabled(self::OTHERAREA, true);
        $this->assertEqualsCanonicalizing([self::AREAKEY, self::OTHERAREA], $registry->enabled_area_keys());

        // Files coverage: the flag alone (disabled row elsewhere) grants nothing; an enabled +
        // flagged course row does — for a file-capable module area.
        $filearea = 'mod_resource-activity';
        $this->assertSame([], $registry->files_enabled_area_keys());
        $repo->set_includefiles($filearea, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$course->id);
        $this->assertSame([], $registry->files_enabled_area_keys());
        $repo->set_enabled($filearea, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$course->id);
        $this->assertSame([$filearea], $registry->files_enabled_area_keys());
    }

    /**
     * Wildcard cascade tiebreaker (§3.0, normative examples 1 + 2): scope specificity first, then
     * concrete area before wildcard at the same scope level.
     */
    public function test_wildcard_normative_examples(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $c1 = $gen->create_course();
        $c2 = $gen->create_course();
        $repo = new sitesearch_scope_repository();
        $resolver = new sitesearch_scope_resolver();
        $wildcard = site_content_area_registry::WILDCARD;
        $forumarea = 'mod_forum-post';

        // NORMATIVE EXAMPLE 1: wildcard COURSE rule on + concrete SITE row "forums off" → the
        // course is indexed COMPLETELY (course scope beats site, even as a wildcard).
        $repo->set_enabled($forumarea, false);
        $repo->set_enabled($wildcard, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $this->assertTrue($resolver->effective($forumarea, (int)$c1->id)['enabled']);
        $this->assertTrue($resolver->effective(self::AREAKEY, (int)$c1->id)['enabled']);
        // The wildcard course rule covers only ITS course — elsewhere the site default holds.
        $this->assertFalse($resolver->effective($forumarea, (int)$c2->id)['enabled']);
        $this->assertFalse($resolver->effective(self::AREAKEY, (int)$c2->id)['enabled']);
        $this->assertSame([(int)$c1->id], $resolver->allowed_courseids($forumarea));
        $this->assertSame('allowlist', $resolver->shape($forumarea)['strategy']);

        // NORMATIVE EXAMPLE 2: + concrete COURSE rule "forums off" (same course) → everything
        // except forums (same scope level, the concrete area row wins over the wildcard row).
        $repo->set_enabled($forumarea, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $this->assertFalse($resolver->effective($forumarea, (int)$c1->id)['enabled']);
        $this->assertTrue($resolver->effective(self::AREAKEY, (int)$c1->id)['enabled']);
        $this->assertSame([], $resolver->allowed_courseids($forumarea));
        $this->assertSame([(int)$c1->id], $resolver->allowed_courseids(self::AREAKEY));

        // Same tiebreaker per CATEGORY path step: concrete category row beats the wildcard
        // category row of the same category.
        [$cata, , , $cc1] = $this->make_tree();
        $repo->set_enabled($wildcard, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        $repo->set_enabled($forumarea, false, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        $this->assertFalse($resolver->effective($forumarea, (int)$cc1->id)['enabled']);
        $this->assertTrue($resolver->effective(self::AREAKEY, (int)$cc1->id)['enabled']);
    }

    /**
     * Wildcard SITE row (§3.0, normative example 3): "everything everywhere" — every covered
     * area becomes active (blocklist without exclusions, full coverage, registry coverage), the
     * pair semantics carry the wildcard's includefiles flag, and 'other'-support areas stay
     * completely unaffected (§9).
     */
    public function test_wildcard_site_row_activates_all_covered_areas(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repo = new sitesearch_scope_repository();
        $resolver = new sitesearch_scope_resolver();
        $registry = new site_content_area_registry();
        $wildcard = site_content_area_registry::WILDCARD;

        $repo->set_enabled($wildcard, true);
        $repo->set_includefiles($wildcard, true);

        // An area WITHOUT any own rule row is ACTIVE through the wildcard site row alone,
        // including the pair's file flag.
        $this->assertSame(
            ['enabled' => true, 'includefiles' => true],
            $resolver->effective(self::AREAKEY, (int)$course->id)
        );
        $shape = $resolver->shape(self::AREAKEY);
        $this->assertSame('blocklist', $shape['strategy']);
        $this->assertSame([], $shape['excludedcourseids']);
        $this->assertSame(['enabled' => true, 'includefiles' => true], $shape['sitedefault']);
        $this->assertContains((int)$course->id, $resolver->allowed_courseids(self::AREAKEY));
        $this->assertTrue($resolver->coverage_map(self::AREAKEY)[(int)$course->id]);

        // The registry's coverage expands the wildcard to EVERY covered area — and only those.
        $this->assertEqualsCanonicalizing($registry->wildcard_covered_area_keys(), $registry->enabled_area_keys());
        $this->assertNotContains(self::OTHERAREA, $registry->enabled_area_keys());

        // The 'other'-support areas are untouched by wildcard rows: still off, no course dimension.
        $this->assertFalse($resolver->effective(self::OTHERAREA, (int)$course->id)['enabled']);
        $this->assertSame('off', $resolver->shape(self::OTHERAREA)['strategy']);
        $this->assertSame([], $resolver->allowed_courseids(self::OTHERAREA));

        // A concrete site row still beats the wildcard site row (tiebreaker at site level): the
        // area's effective coverage collapses to nothing (the disable mutation's delta sync has
        // already pruned the index rows).
        $repo->set_enabled(self::AREAKEY, false);
        $this->assertFalse($resolver->effective(self::AREAKEY, (int)$course->id)['enabled']);
        $this->assertSame([], $resolver->allowed_courseids(self::AREAKEY));
    }

    /**
     * Repository rule API: list_rules returns every row of the area (site + scoped), delete_rule
     * removes exactly one row, and every mutation is visible to a fresh resolution immediately
     * (the chokepoint resets the request-static caches).
     */
    public function test_list_and_delete_rules_and_cache_reset(): void {
        $this->resetAfterTest();
        [$cata, , , $c1] = $this->make_tree();
        $repo = new sitesearch_scope_repository();
        $resolver = new sitesearch_scope_resolver();

        $repo->set_enabled(self::AREAKEY, true);
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $this->assertCount(3, $repo->list_rules(self::AREAKEY));

        // Warm the memo, then mutate: the resolver must serve the fresh state (cache reset).
        $this->assertFalse($resolver->effective(self::AREAKEY, (int)$c1->id)['enabled']);
        $repo->delete_rule(self::AREAKEY, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $this->assertCount(2, $repo->list_rules(self::AREAKEY));
        // Without the blocking course row, category A covers c1 again.
        $this->assertTrue($resolver->effective(self::AREAKEY, (int)$c1->id)['enabled']);
    }
}
