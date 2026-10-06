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
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\manager;

/**
 * Tests for the run-time catalog of Report Builder datasources.
 *
 * Plugin-agnostic by construction: the expectations are phrased against core's own discovery
 * (manager::get_report_datasources()) and against the users datasource that every Moodle ships,
 * never against a plugin list.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_source_catalog_service_test extends advanced_testcase {
    /** FQCN of the users datasource core ships. */
    private const USERS_SOURCE = 'core_user\\reportbuilder\\datasource\\users';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        report_source_catalog_service::reset_caches();
    }

    /**
     * Every source core discovers is listed exactly once, with its plugin and a usable name.
     */
    public function test_lists_every_installed_datasource(): void {
        $expected = [];
        foreach (manager::get_report_datasources() as $sources) {
            foreach ($sources as $fqcn => $unused) {
                $expected[] = ltrim((string)$fqcn, '\\');
            }
        }
        sort($expected);

        $listed = (new report_source_catalog_service())->list_sources();
        $actual = array_column($listed, 'source');
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertContains(self::USERS_SOURCE, $actual);
        foreach ($listed as $entry) {
            $this->assertNotSame('', $entry['name'], $entry['source'] . ' has no name');
            $this->assertNotSame('', $entry['component'], $entry['source'] . ' has no component');
            $this->assertStringStartsWith($entry['component'] . '\\', $entry['source']);
        }
    }

    /**
     * The plain list never instantiates a source (a broken third-party entity class would be a
     * compile-time fatal); with entities requested for one component, the narrowed sources report
     * their structure.
     */
    public function test_entities_and_counts_are_reported_on_demand(): void {
        $plain = (new report_source_catalog_service())->list_sources();
        foreach ($plain as $entry) {
            $this->assertArrayNotHasKey('entities', $entry, $entry['source']);
            $this->assertArrayNotHasKey('counts', $entry, $entry['source']);
        }

        $listed = (new report_source_catalog_service())->list_sources(true, 'core_user');
        $this->assertNotEmpty($listed);
        $users = null;
        foreach ($listed as $entry) {
            $this->assertSame('core_user', $entry['component']);
            $this->assertArrayHasKey('available', $entry, $entry['source']);
            $this->assertArrayHasKey('counts', $entry, $entry['source']);
            if ($entry['source'] === self::USERS_SOURCE) {
                $users = $entry;
            }
        }
        $this->assertNotNull($users);
        $this->assertTrue($users['available']);
        $this->assertContains('user', array_column($users['entities'], 'name'));
        $this->assertGreaterThan(0, $users['counts']['columns']);
        $this->assertGreaterThan(0, $users['counts']['filters']);
    }

    /**
     * A source is resolved by exact FQCN, by exact short class name and by exact localised name;
     * anything else is unresolved and carries the full candidate list. No partial matching.
     */
    public function test_resolves_exact_references_only(): void {
        $service = new report_source_catalog_service();

        $this->assertSame(self::USERS_SOURCE, $service->resolve_source(self::USERS_SOURCE)['source']);
        $this->assertSame(self::USERS_SOURCE, $service->resolve_source('\\' . self::USERS_SOURCE)['source']);
        $this->assertSame(self::USERS_SOURCE, $service->resolve_source(get_string('users'))['source']);
        $this->assertSame(self::USERS_SOURCE, $service->resolve_source(strtoupper(get_string('users')))['source']);

        $miss = $service->resolve_source('use');
        $this->assertNull($miss['source'], 'a prefix must not resolve');
        $this->assertNotEmpty($miss['candidates']);
        $this->assertContains(self::USERS_SOURCE, array_column($miss['candidates'], 'source'));

        $this->assertNull($service->resolve_source('')['source']);
    }

    /**
     * The description carries exact identifiers, entities, types, aggregations and operator enums
     * whose keys are the filter classes' own constant names with core's own labels.
     */
    public function test_describes_columns_filters_and_operators(): void {
        $described = (new report_source_catalog_service())->describe_source(self::USERS_SOURCE);

        $this->assertSame(self::USERS_SOURCE, $described['source']);
        $this->assertSame('core_user', $described['component']);
        $this->assertContains('user:fullname', $described['defaults']['columns']);

        $columns = array_column($described['columns'], null, 'identifier');
        $this->assertArrayHasKey('user:fullname', $columns);
        $this->assertSame('user', $columns['user:fullname']['entity']);
        $this->assertSame('text', $columns['user:fullname']['type']);
        $this->assertTrue($columns['user:fullname']['default']);
        $this->assertContains('count', $columns['user:fullname']['aggregations']);

        $filters = array_column($described['filters'], null, 'identifier');
        $this->assertArrayHasKey('user:fullname', $filters);
        $this->assertSame('text', $filters['user:fullname']['filterclass']);
        $operators = array_column($filters['user:fullname']['operators'], null, 'key');
        $this->assertArrayHasKey('IS_EQUAL_TO', $operators);
        $this->assertSame(text::IS_EQUAL_TO, $operators['IS_EQUAL_TO']['value']);
        $this->assertSame(get_string('filterisequalto', 'core_reportbuilder'), $operators['IS_EQUAL_TO']['label']);
        $this->assertArrayNotHasKey('DATE_UNIT_DAY', $operators);

        // A date filter reports its units separately and collapses aliased constants onto one key.
        $datefilter = null;
        foreach ($described['filters'] as $filter) {
            if ($filter['filterclass'] === 'date') {
                $datefilter = $filter;
                break;
            }
        }
        $this->assertNotNull($datefilter, 'the users source has a date filter');
        $dateoperators = array_column($datefilter['operators'], 'value', 'key');
        $this->assertSame(date::DATE_RANGE, $dateoperators['DATE_RANGE']);
        $this->assertArrayHasKey('DATE_LAST', $dateoperators);
        $this->assertArrayNotHasKey('DATE_PREVIOUS', $dateoperators, 'aliased constant collapses');
        $this->assertContains('DATE_UNIT_DAY', array_column($datefilter['units'], 'key'));

        $this->assertNotEmpty($described['conditions']);
    }

    /**
     * Section and entity narrow the description deterministically.
     */
    public function test_section_and_entity_narrow_the_description(): void {
        $service = new report_source_catalog_service();

        $onlyfilters = $service->describe_source(self::USERS_SOURCE, report_source_catalog_service::SECTION_FILTERS);
        $this->assertSame([], $onlyfilters['columns']);
        $this->assertSame([], $onlyfilters['conditions']);
        $this->assertNotEmpty($onlyfilters['filters']);

        $onlyuser = $service->describe_source(self::USERS_SOURCE, report_source_catalog_service::SECTION_ALL, 'user');
        $this->assertNotEmpty($onlyuser['columns']);
        foreach (array_merge($onlyuser['columns'], $onlyuser['filters'], $onlyuser['conditions']) as $item) {
            $this->assertSame('user', $item['entity'], $item['identifier']);
        }
    }

    /**
     * Describing a source stays inside the per-command preflight budget (2000 ms), cold.
     */
    public function test_describe_stays_inside_the_preflight_budget(): void {
        report_source_catalog_service::reset_caches();
        $start = microtime(true);
        (new report_source_catalog_service())->describe_source(self::USERS_SOURCE);
        $elapsed = (microtime(true) - $start) * 1000;
        $this->assertLessThan(2000, $elapsed, 'describe_source took ' . round($elapsed) . ' ms');
    }

    /**
     * An unknown source is a coding error at the service boundary (skills resolve first).
     */
    public function test_unknown_source_throws(): void {
        $this->expectException(\coding_exception::class);
        (new report_source_catalog_service())->instantiate('no_such\\reportbuilder\\datasource\\thing');
    }
}
