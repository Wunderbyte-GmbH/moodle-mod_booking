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
use cache;
use context_module;
use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\skill_catalog_discovery;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * With local_entities installed, location is entity-managed: wizard updates must never blank it.
 *
 * Write-path baseline W1 (2026-09-15, thread 1641 BU-4, finding W12, Wunderbyte-GmbH#2414): a bulk
 * location update reported "0 of 174 saved" and cleared location AND address of the four
 * entity-linked options, because the wizard payload never carries the entity key that
 * option/fields/entities::prepare_save_field() refills location/address from.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_mutation_execute_service
 * @covers     \mod_booking\local\wizard\options\skills\update_option_skill
 * @covers     \mod_booking\local\wizard\options\skills\bulk_update_options_skill
 */
final class wizard_entities_location_update_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var string Relation handler class of local_entities. */
    private const HANDLER = '\\local_entities\\entitiesrelation_handler';

    /** @var int Course-module id of the booking instance. */
    private int $cmid = 0;

    /** @var int Module context id. */
    private int $contextid = 0;

    /** @var int Option linked to the entity. */
    private int $linkedid = 0;

    /** @var int Option without entity. */
    private int $plainid = 0;

    /** @var int The entity "Gebäude A". */
    private int $entityid = 0;

    /**
     * Setup: booking instance, one entity-linked and one plain option.
     */
    protected function setUp(): void {
        $this->skip_without_agent_extension();
        if (!class_exists(self::HANDLER)) {
            $this->markTestSkipped('local_entities is not installed');
        }
        parent::setUp();
        $this->resetAfterTest(true);
        engine_component::ensure_engine_aliases();
        $this->setAdminUser();
        singleton_service::destroy_instance();

        global $DB, $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Entities booking', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $this->linkedid = (int)$gen->create_option([
            'bookingid' => (int)$booking->id, 'text' => 'Linked option', 'maxanswers' => 10, 'type' => 0, 'importing' => 1,
        ])->id;
        $this->plainid = (int)$gen->create_option([
            'bookingid' => (int)$booking->id, 'text' => 'Plain option', 'maxanswers' => 10, 'type' => 0, 'importing' => 1,
        ])->id;

        $this->entityid = (int)$this->getDataGenerator()->get_plugin_generator('local_entities')->create_entities([
            'name' => 'Gebäude A',
            'shortname' => 'gebaeudea',
            'country_0' => 'AT',
            'city_0' => 'Wien',
            'postcode_0' => '1010',
            'streetname_0' => 'Ring',
            'streetnumber_0' => '1',
        ]);
        // Link the first option the way a form save does and persist the derived location/address columns.
        $handler = self::HANDLER;
        (new $handler('mod_booking', 'option'))->save_entity_relation($this->linkedid, $this->entityid);
        $DB->set_field('booking_options', 'location', 'Gebäude A', ['id' => $this->linkedid]);
        $DB->set_field('booking_options', 'address', 'Ring 1, 1010 Wien', ['id' => $this->linkedid]);
        $this->reset_caches();
    }

    /**
     * Drop every cache that hides a DB change from the settings and entity lookups.
     */
    private function reset_caches(): void {
        cache::make('local_entities', 'cachedentities')->purge();
        cache::make('mod_booking', 'bookingoptionsettings')->purge();
        singleton_service::destroy_instance();
    }

    /**
     * Attachment token service of the active engine.
     *
     * @return object
     */
    private function attachment_token_service(): object {
        $class = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\attachment\\attachment_token_service');
        return new $class();
    }

    /**
     * Conversation thread memory of the active engine.
     *
     * @return object
     */
    private function thread_memory(): object {
        $class = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\conversation_thread_memory');
        return new $class();
    }

    /**
     * Skill support wired with the active engine's services.
     *
     * @return booking_skill_support
     */
    private function skill_support(): booking_skill_support {
        return new booking_skill_support($this->attachment_token_service(), $this->thread_memory(), new skill_catalog_discovery());
    }

    /**
     * Current location/address/entity of an option, read fresh from the DB.
     *
     * @param int $optionid Option id.
     * @return array{location:string,address:string,entityid:int}
     */
    private function state(int $optionid): array {
        global $DB;
        $this->reset_caches();
        $record = $DB->get_record('booking_options', ['id' => $optionid], 'location, address', MUST_EXIST);
        $handler = self::HANDLER;
        return [
            'location' => (string)$record->location,
            'address' => (string)$record->address,
            'entityid' => (int)(new $handler('mod_booking', 'option'))->get_entityid_by_instanceid($optionid),
        ];
    }

    /**
     * A bulk update that does not touch the location keeps location, address and entity link.
     */
    public function test_unrelated_bulk_update_keeps_entity_location_and_address(): void {
        global $USER;
        $service = new booking_skill_mutation_execute_service($this->attachment_token_service());
        $result = $service->execute(
            bulk_update_options_skill::TASK_NAME,
            ['optionids' => [$this->linkedid, $this->plainid], 'maxanswers' => 7],
            $this->cmid,
            (int)$USER->id,
            $this->skill_support()
        );
        $this->assertNotSame('error', (string)($result['status'] ?? ''), json_encode($result));
        $state = $this->state($this->linkedid);
        $this->assertSame('Gebäude A', $state['location'], 'location must survive an unrelated update');
        $this->assertNotSame('', $state['address'], 'address must survive an unrelated update');
        $this->assertSame($this->entityid, $state['entityid'], 'entity relation must survive');
    }

    /**
     * A location that matches no entity ends as a clarification with the entities as remedies, nothing written.
     */
    public function test_unknown_location_clarifies_with_entity_remedies_and_writes_nothing(): void {
        global $USER;
        $skill = new update_option_skill();
        $dto = $skill->preflight(
            ['optionid' => $this->linkedid, 'location' => 'Gebäude C', 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertNotContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $issues = json_decode(json_encode($dto->issues), true);
        $issue = null;
        foreach ($issues as $candidate) {
            if (($candidate['severity'] ?? '') === 'needs_clarification' && !empty($candidate['remedy_options'])) {
                $issue = $candidate;
            }
        }
        $this->assertNotNull($issue, 'an unknown location is a clarification with remedies: ' . json_encode($issues));
        $remedyids = array_map(static fn($remedy) => (int)($remedy['id'] ?? 0), (array)$issue['remedy_options']);
        $this->assertContains($this->entityid, $remedyids, json_encode($issue));
        $this->assertStringContainsString('Gebäude C', (string)($issue['user_question'] ?? ''));

        // The raw execute path (no preflight) must not blank the entity-managed columns either.
        $service = new booking_skill_mutation_execute_service($this->attachment_token_service());
        $result = $service->execute(
            update_option_skill::TASK_NAME,
            ['optionid' => $this->linkedid, 'location' => 'Gebäude C'],
            $this->cmid,
            (int)$USER->id,
            $this->skill_support()
        );
        $this->assertSame('error', (string)($result['status'] ?? ''), json_encode($result));
        $state = $this->state($this->linkedid);
        $this->assertSame('Gebäude A', $state['location'], 'nothing may be written for an unresolved location');
        $this->assertNotSame('', $state['address']);
        $this->assertSame($this->entityid, $state['entityid']);
    }

    /**
     * A location that names exactly one entity links the option to it and is reported as saved.
     */
    public function test_matching_location_links_the_entity_and_is_saved(): void {
        global $USER;
        $skill = new update_option_skill();
        $dto = $skill->preflight(
            ['optionid' => $this->plainid, 'location' => 'Gebäude A', 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertSame($this->entityid, (int)($prepared['entityid'] ?? 0), 'the resolved entity travels in the prepared input');

        $service = new booking_skill_mutation_execute_service($this->attachment_token_service());
        $result = $service->execute(update_option_skill::TASK_NAME, $prepared, $this->cmid, (int)$USER->id, $this->skill_support());
        $this->assertNotSame('error', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertContains('location', (array)($result['persisted_fields'] ?? []), json_encode($result));
        $state = $this->state($this->plainid);
        $this->assertSame('Gebäude A', $state['location']);
        $this->assertNotSame('', $state['address'], 'the entity address is derived on save');
        $this->assertSame($this->entityid, $state['entityid']);
    }
}
