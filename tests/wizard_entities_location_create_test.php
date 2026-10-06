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
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;

/**
 * Creating an option with a location under local_entities links the entity instead of losing the location.
 *
 * Follow-up of W12/#2414 (#2417): the create payload carried no entity key, so
 * entities::prepare_save_field() cleared location/address right after the wizard wrote them,
 * while the confirm card had shown the location.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers     \mod_booking\local\wizard\booking\support\entity_location
 */
final class wizard_entities_location_create_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var string Entities relation handler class. */
    private const HANDLER = '\\local_entities\\entitiesrelation_handler';

    /** @var int Course-module id of the booking instance. */
    private int $cmid = 0;

    /** @var int Module context id. */
    private int $contextid = 0;

    /** @var int Id of the entity "Gebäude A". */
    private int $entityid = 0;

    /**
     * Setup: booking instance and one entity.
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
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Entities create', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
        $this->entityid = (int)$this->getDataGenerator()->get_plugin_generator('local_entities')->create_entities([
            'name' => 'Gebäude A',
            'shortname' => 'gebaeudea',
            'country_0' => 'AT',
            'city_0' => 'Wien',
            'postcode_0' => '1010',
            'streetname_0' => 'Ring',
            'streetnumber_0' => '1',
        ]);
        cache::make('local_entities', 'cachedentities')->purge();
    }

    /**
     * Location, address and entity relation of an option.
     *
     * @param int $optionid Option id.
     * @return array{location:string,address:string,entityid:int}
     */
    private function state(int $optionid): array {
        global $DB;
        cache::make('mod_booking', 'bookingoptionsettings')->purge();
        singleton_service::destroy_instance();
        $record = $DB->get_record('booking_options', ['id' => $optionid], 'location, address', MUST_EXIST);
        $handler = self::HANDLER;
        return [
            'location' => (string)$record->location,
            'address' => (string)$record->address,
            'entityid' => (int)(new $handler('mod_booking', 'option'))->get_entityid_by_instanceid($optionid),
        ];
    }

    /**
     * Skill support for a raw service call.
     *
     * @return booking_skill_support
     */
    private function skill_support(): booking_skill_support {
        $tokens = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\attachment\\attachment_token_service');
        $memory = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\conversation_thread_memory');
        return new booking_skill_support(new $tokens(), new $memory(), new skill_catalog_discovery());
    }

    /**
     * Input of a dated option with a location.
     *
     * @param string $text Title.
     * @param string $location Location.
     * @return array
     */
    private function input(string $text, string $location): array {
        return [
            'text' => $text,
            'location' => $location,
            'coursestarttime' => '2046-10-01T10:00:00',
            'courseendtime' => '2046-10-01T12:00:00',
            'cmid' => $this->cmid,
        ];
    }

    /**
     * A location that names exactly one entity travels as entityid and is linked on create.
     */
    public function test_matching_location_links_the_entity_on_create(): void {
        global $USER;
        $skill = new create_option_skill();
        $dto = $skill->preflight($this->input('Schweißseminar', 'Gebäude A'), $this->contextid, (int)$USER->id);
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertSame($this->entityid, (int)($prepared['entityid'] ?? 0), 'the resolved entity travels in the prepared input');
        $result = $skill->execute($prepared, $this->contextid, (int)$USER->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''), json_encode($result));
        $optionid = (int)($result['resultid'] ?? 0);
        $this->assertGreaterThan(0, $optionid);
        $state = $this->state($optionid);
        $this->assertSame('Gebäude A', $state['location'], 'the location must survive the create');
        $this->assertNotSame('', $state['address'], 'the entity address is derived on save');
        $this->assertSame($this->entityid, $state['entityid']);
    }

    /**
     * An unknown location is a clarification with the entities as remedies; nothing is created.
     */
    public function test_unknown_location_clarifies_and_creates_nothing(): void {
        global $DB, $USER;
        $before = $DB->count_records('booking_options');
        $skill = new create_option_skill();
        $dto = $skill->preflight($this->input('Schweißseminar', 'Gebäude C'), $this->contextid, (int)$USER->id);
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
        // The raw execute path must not create an option whose location would be cleared.
        $service = new booking_skill_mutation_execute_service(null);
        $result = $service->execute(
            create_option_skill::TASK_NAME,
            ['text' => 'Schweißseminar', 'location' => 'Gebäude C', 'coursestarttime' => '2046-10-01T10:00:00',
                'courseendtime' => '2046-10-01T12:00:00'],
            $this->cmid,
            (int)$USER->id,
            $this->skill_support()
        );
        $this->assertSame('error', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertSame($before, $DB->count_records('booking_options'), 'nothing may be created for an unresolved location');
    }

    /**
     * The self-learning create shares the path: the entity is linked too.
     */
    public function test_selflearning_create_links_the_entity(): void {
        global $USER;
        $skill = new create_selflearning_option_skill();
        $dto = $skill->preflight(
            ['text' => 'RGPD Selbstlernkurs', 'location' => 'Gebäude A', 'duration' => 7200, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertSame($this->entityid, (int)($prepared['entityid'] ?? 0));
        $result = $skill->execute($prepared, $this->contextid, (int)$USER->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''), json_encode($result));
        $state = $this->state((int)$result['resultid']);
        $this->assertSame('Gebäude A', $state['location']);
        $this->assertSame($this->entityid, $state['entityid']);
    }
}
