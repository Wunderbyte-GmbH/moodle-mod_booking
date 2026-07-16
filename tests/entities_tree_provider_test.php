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
use local_entities\entities;
use local_entities\entitiesrelation_handler;
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\filters\types\treefilter;
use mod_booking\local\entities_tree_provider;
use mod_booking\table\bookingoptions_wbtable;

/**
 * End-to-end tests for the booking-options multilevel location (entity) filter against the real DB.
 *
 * Validates the option-only EXISTS on the outer s1.id (BC-4/BC-4a), the present-count query and the
 * live subtree expansion.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\entities_tree_provider
 */
final class entities_tree_provider_test extends advanced_testcase {
    /** @var array<string,int> entity ids by name */
    private array $entityids = [];

    /** @var array<string,int> option ids by label */
    private array $optionids = [];

    /** @var bookingoptions_wbtable */
    private $table;

    /**
     * Builds a 3-level entity tree, a booking with options attached to entities, and the wbtable SQL.
     *
     * @return void
     */
    private function build_scenario(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        entities::reset_caches();

        $egen = $this->getDataGenerator()->get_plugin_generator('local_entities');
        // Location → Building → Floor → {Room, Room2}; Location → BuildingB; separate Other root.
        $this->entityids['location'] = $egen->create_entities(['name' => 'Location', 'shortname' => 'loc']);
        $this->entityids['building'] = $egen->create_entities(
            ['name' => 'Building', 'shortname' => 'bld', 'parentid' => $this->entityids['location']]
        );
        $this->entityids['floor'] = $egen->create_entities(
            ['name' => 'Floor', 'shortname' => 'flr', 'parentid' => $this->entityids['building']]
        );
        $this->entityids['room'] = $egen->create_entities(
            ['name' => 'Room', 'shortname' => 'rm1', 'parentid' => $this->entityids['floor']]
        );
        $this->entityids['room2'] = $egen->create_entities(
            ['name' => 'Room2', 'shortname' => 'rm2', 'parentid' => $this->entityids['floor']]
        );
        $this->entityids['buildingb'] = $egen->create_entities(
            ['name' => 'BuildingB', 'shortname' => 'bldb', 'parentid' => $this->entityids['location']]
        );
        $this->entityids['other'] = $egen->create_entities(['name' => 'Other', 'shortname' => 'oth']);

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'B']);
        $cmid = $booking->cmid;
        $bookingobj = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Insert options directly: the full two-phase create_option() path is unrelated to what we test
        // here (it only feeds booking_options rows to get_options_filter_sql) and is brittle in this env.
        foreach (['A', 'B', 'C', 'D', 'E'] as $label) {
            $this->optionids[$label] = (int)$DB->insert_record('booking_options', (object)[
                'bookingid' => (int)$booking->id,
                'courseid' => (int)$course->id,
                'text' => 'Option ' . $label,
                'description' => 'Option ' . $label . ' description',
                'descriptionformat' => FORMAT_HTML,
                'identifier' => 'opt' . $label,
                'invisible' => 0,
            ]);
        }

        // Option-level relations: A→Room, B→Room2, C→BuildingB, D none, E→Other (outside Building).
        $erh = new entitiesrelation_handler('mod_booking', 'option');
        $erh->save_entity_relation($this->optionids['A'], $this->entityids['room']);
        $erh->save_entity_relation($this->optionids['B'], $this->entityids['room2']);
        $erh->save_entity_relation($this->optionids['C'], $this->entityids['buildingb']);
        $erh->save_entity_relation($this->optionids['E'], $this->entityids['other']);

        // Option E ALSO has an optiondate-level relation into Building's subtree (Room). The option-only
        // filter must ignore this (BC-4a): E must not be matched by a Building filter.
        $erhod = new entitiesrelation_handler('mod_booking', 'optiondate');
        $erhod->save_entity_relation($this->optionids['E'], $this->entityids['room']);

        set_config('entitytreefilter', 1, 'mod_booking');
        entities::reset_caches();

        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $bookingobj->context,
            [],
            ['bookingid' => (int)$booking->id]
        );
        $this->table = new bookingoptions_wbtable("cmid_{$cmid}_treefiltertest");
        $this->table->set_filter_sql($fields, $from, $where, $filter, $params);
    }

    /**
     * Runs the provider's EXISTS fragment against the real base query and returns the matched option ids.
     *
     * @param array $baseparams the untouched base params to restore before each run
     * @param int[] $selectednodes selected node ids
     * @return int[] matched option ids
     */
    private function matched_option_ids(array $baseparams, array $selectednodes): array {
        global $DB;
        $this->table->sql->params = $baseparams;
        $sql = entities_tree_provider::filter_sql($this->table, 'entityid', $selectednodes);
        $query = "SELECT s1.id FROM {$this->table->sql->from} WHERE {$this->table->sql->where} AND ( $sql )";
        return array_map('intval', $DB->get_fieldset_sql($query, $this->table->sql->params));
    }

    /**
     * The opt-in helper returns a treefilter when active and the plain standardfilter otherwise.
     *
     * @return void
     */
    public function test_get_location_filter_respects_optin(): void {
        $this->resetAfterTest();

        // The admin setting is defined as booking/entitytreefilter, so it lives under the
        // plugin name 'booking' (get_config does not normalize component names).
        set_config('entitytreefilter', 0, 'booking');
        $this->assertInstanceOf(standardfilter::class, entities_tree_provider::get_location_filter('Location'));

        set_config('entitytreefilter', 1, 'booking');
        // Only a treefilter when local_entities is present (which it is in this test env).
        $this->assertInstanceOf(treefilter::class, entities_tree_provider::get_location_filter('Location'));
    }

    /**
     * get_present_counts reports the option-level entities present in the result set (option D with no
     * relation is absent; option E is counted on its option-level entity 'Other', not its optiondate).
     *
     * @return void
     */
    public function test_get_present_counts(): void {
        $this->build_scenario();

        $counts = entities_tree_provider::get_present_counts($this->table, 'entityid');

        $this->assertSame(1, $counts[$this->entityids['room']] ?? 0);
        $this->assertSame(1, $counts[$this->entityids['room2']] ?? 0);
        $this->assertSame(1, $counts[$this->entityids['buildingb']] ?? 0);
        $this->assertSame(1, $counts[$this->entityids['other']] ?? 0);
        // Room is not directly occupied by the optiondate override (option-level only).
        $this->assertArrayNotHasKey($this->entityids['floor'], $counts);
    }

    /**
     * Selecting a node filters to the option-level entities in its whole live subtree; optiondate-only
     * locations are never matched (BC-4a).
     *
     * @return void
     */
    public function test_filter_sql_matches_subtree_option_level_only(): void {
        $this->build_scenario();
        $baseparams = $this->table->sql->params;

        // Building subtree = {Building, Floor, Room, Room2} → options A (Room) and B (Room2).
        // Option E has an *optiondate* relation into Room but its *option* entity is Other → excluded.
        $this->assertEqualsCanonicalizing(
            [$this->optionids['A'], $this->optionids['B']],
            $this->matched_option_ids($baseparams, [$this->entityids['building']])
        );

        // Location (root) subtree includes BuildingB too → A, B, C (not D, not E).
        $this->assertEqualsCanonicalizing(
            [$this->optionids['A'], $this->optionids['B'], $this->optionids['C']],
            $this->matched_option_ids($baseparams, [$this->entityids['location']])
        );

        // A leaf node matches only its own option.
        $this->assertEqualsCanonicalizing(
            [$this->optionids['A']],
            $this->matched_option_ids($baseparams, [$this->entityids['room']])
        );

        // Selecting the 'Other' root matches only option E (proves E is filed under its option entity).
        $this->assertEqualsCanonicalizing(
            [$this->optionids['E']],
            $this->matched_option_ids($baseparams, [$this->entityids['other']])
        );
    }

    /**
     * render_location_name is byte-identical to the historical output for 1–2 levels and only shows a
     * full breadcrumb for 3+ level hierarchies (BC-6).
     *
     * @return void
     */
    public function test_render_location_name_bc6(): void {
        $this->resetAfterTest();
        entities::reset_caches();

        $egen = $this->getDataGenerator()->get_plugin_generator('local_entities');
        $loc = $egen->create_entities(['name' => 'Location', 'shortname' => 'loc']);
        $bld = $egen->create_entities(['name' => 'Building', 'shortname' => 'bld', 'parentid' => $loc]);
        $flr = $egen->create_entities(['name' => 'Floor', 'shortname' => 'flr', 'parentid' => $bld]);
        entities::reset_caches();

        // 1 level (root, no parent): just the name — unchanged.
        $this->assertSame(
            'Location',
            entities_tree_provider::render_location_name(['id' => $loc, 'name' => 'Location', 'parentname' => ''])
        );

        // 2 levels: "parent (name)" — byte-identical to the historical rendering.
        $this->assertSame(
            'Location (Building)',
            entities_tree_provider::render_location_name(['id' => $bld, 'name' => 'Building', 'parentname' => 'Location'])
        );

        // 3 levels: full breadcrumb.
        $this->assertSame(
            'Location / Building / Floor',
            entities_tree_provider::render_location_name(['id' => $flr, 'name' => 'Floor', 'parentname' => 'Building'])
        );
    }

    /**
     * render_location_cell keeps 1–2 levels byte-identical (linked historical name, plain text on
     * download) and renders deep hierarchies as the entity name plus an accessible hover card with
     * the superordinate levels — full path only for screenreaders and exports (BC-6).
     *
     * @return void
     */
    public function test_render_location_cell(): void {
        $this->resetAfterTest();
        entities::reset_caches();

        $egen = $this->getDataGenerator()->get_plugin_generator('local_entities');
        $loc = $egen->create_entities(['name' => 'Location', 'shortname' => 'loc']);
        $bld = $egen->create_entities(['name' => 'Building', 'shortname' => 'bld', 'parentid' => $loc]);
        $flr = $egen->create_entities(['name' => 'Floor', 'shortname' => 'flr', 'parentid' => $bld]);
        entities::reset_caches();

        // 1–2 levels: byte-identical to the historical cell markup.
        $twolevel = ['id' => $bld, 'name' => 'Building', 'parentname' => 'Location'];
        $expectedurl = (new \moodle_url('/local/entities/view.php', ['id' => $bld]))->out(false);
        $this->assertSame(
            \html_writer::tag('a', 'Location (Building)', ['href' => $expectedurl]),
            entities_tree_provider::render_location_cell($twolevel, false)
        );
        $this->assertSame('Location (Building)', entities_tree_provider::render_location_cell($twolevel, true));

        // 3 levels, export: the full path as plain text.
        $deep = ['id' => $flr, 'name' => 'Floor', 'parentname' => 'Building'];
        $this->assertSame('Location / Building / Floor', entities_tree_provider::render_location_cell($deep, true));

        // 3 levels, display: only the entity name is visible; ancestors live in the hover card and
        // in visually hidden text — there is no inline breadcrumb anymore.
        set_config('showlocationimages', 0, 'booking');
        $html = entities_tree_provider::render_location_cell($deep, false);
        $this->assertStringContainsString('mod-booking-location-cell', $html);
        $this->assertStringContainsString('mod-booking-location-path', $html);
        $this->assertStringContainsString('>Floor<', $html);
        $this->assertStringContainsString('Location / Building', $html);
        $this->assertStringNotContainsString('Location / Building / Floor', $html);
        $this->assertStringNotContainsString('<img', $html);
        // Every ancestor in the card links to its own entity view page; the card holds focusable
        // links, so it must not be aria-hidden.
        $this->assertStringContainsString((new \moodle_url('/local/entities/view.php', ['id' => $loc]))->out(false), $html);
        $this->assertStringContainsString((new \moodle_url('/local/entities/view.php', ['id' => $bld]))->out(false), $html);
        $this->assertStringNotContainsString('aria-hidden', $html);

        // With showlocationimages on, an ancestor's image is rendered small into the card.
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_entities',
            'filearea' => 'image',
            'itemid' => $loc,
            'filepath' => '/',
            'filename' => 'loc.png',
        ], 'fake-image-bytes');
        set_config('showlocationimages', 1, 'booking');
        entities::reset_caches();
        $html = entities_tree_provider::render_location_cell($deep, false);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('loc.png', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    /**
     * Reparenting an entity is reflected live: moving Room2 out to BuildingB immediately changes which
     * options a Building filter matches, without re-saving any option (BC/R2).
     *
     * @return void
     */
    public function test_reparent_is_live(): void {
        global $DB;
        $this->build_scenario();
        $baseparams = $this->table->sql->params;

        // Before: Building matches A (Room) and B (Room2).
        $this->assertEqualsCanonicalizing(
            [$this->optionids['A'], $this->optionids['B']],
            $this->matched_option_ids($baseparams, [$this->entityids['building']])
        );

        // Move Room2 under BuildingB (a sibling of Building), then purge the request map.
        $DB->set_field('local_entities', 'parentid', $this->entityids['buildingb'], ['id' => $this->entityids['room2']]);
        entities::reset_caches();

        // After: Building no longer matches B; only A remains.
        $this->assertEqualsCanonicalizing(
            [$this->optionids['A']],
            $this->matched_option_ids($baseparams, [$this->entityids['building']])
        );
    }
}
