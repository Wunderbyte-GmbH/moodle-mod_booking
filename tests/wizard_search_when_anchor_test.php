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

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\search_options_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A temporal phrase must be routable into "when", never into the substring query (#2317, F57a).
 *
 * Example anchors beat descriptions (architecture/07, proven three times). Both fields carried
 * the identical example "next monday", so the model could route a temporal phrase either way —
 * and did, non-deterministically. The anchors must be distinct: query anchors on plain text,
 * "when" anchors on a concrete resolved date, and vague phrases stage neither (#2318 default).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\search_options_skill
 */
final class wizard_search_when_anchor_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * The query description must not anchor on a temporal example.
     */
    public function test_query_anchor_is_not_temporal(): void {
        $props = (array)((new search_options_skill())->get_schema()['properties'] ?? []);
        $querydesc = strtolower((string)($props['query']['description'] ?? ''));

        $this->assertStringNotContainsString(
            'next monday',
            $querydesc,
            'a temporal example on query lets the model route time phrases into the substring search'
        );
        $this->assertStringContainsString(
            'never',
            $querydesc,
            'the contrast steering (#2275 pattern) must be present'
        );
    }

    /**
     * The "when" anchor must be a concrete date plus the resolve/leave-empty policy.
     */
    public function test_when_anchor_is_a_concrete_date(): void {
        $props = (array)((new search_options_skill())->get_schema()['properties'] ?? []);
        $whendesc = (string)($props['when']['description'] ?? '');

        // Wave 30/32: a concrete example date is a copyable value (33abfb2, e51c839: dates as placeholders); the anchor
        // is the concrete format the strict parser (#2318) reads, with the instruction to resolve the day.
        $this->assertStringContainsString(
            'YYYY-MM-DD',
            $whendesc,
            'the when anchor must name the concrete date format the strict parser (#2318) can read'
        );
        $this->assertStringContainsString(
            'resolve',
            strtolower($whendesc),
            'relative phrases must be resolved by the constructor, not shipped verbatim'
        );
        $this->assertStringContainsString(
            'empty',
            strtolower($whendesc),
            'vague phrases must leave the field empty — upcoming is the default since #2318'
        );
    }

    /**
     * The construction guidance must carry the inline JSON contrast (strongest anchor form).
     */
    public function test_pack_guidance_carries_the_contrast(): void {
        $guidance = '';
        foreach ((array)(new search_options_skill())->get_contextual_prompt_packs() as $pack) {
            $guidance .= implode("\n", (array)($pack['guidance'] ?? []));
        }

        $this->assertStringContainsString('"when"', $guidance, 'the when flavour needs an inline example');
        $this->assertStringContainsString('"query"', $guidance, 'the query flavour needs an inline example');
    }
}
