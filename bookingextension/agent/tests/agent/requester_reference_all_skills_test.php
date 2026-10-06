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
 * The requester in a person field: one mechanism for every registered skill (#2569).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\reportbuilder\audience_service;
use bookingextension_agent\local\wizard\services\requester_reference;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\skill_input_schema_projection;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * Earlier fixes covered one skill (book_users, #2246 in August), one layer (the strip, 208d858) or one resolver
 * (F81). This pins the whole chain for every skill the registry knows: every person field - by naming convention or
 * declared by the skill - turns the requester's token into the requester marker, and every person resolver reads the
 * marker as the acting user. A new skill with a person field is covered without being listed here.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\requester_reference
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers \bookingextension_agent\local\wizard\services\skill_input_schema_projection
 */
final class requester_reference_all_skills_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
    }

    /**
     * Every person field of every registered skill: the requester's token becomes the marker, another person's
     * token and the requester's id stay as they are, and a list maps per entry.
     */
    public function test_every_person_field_of_every_skill_marks_the_requester(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $userid = (int)$this->teacher->id;
        $anonymizer = new privacy_anonymizer($store);
        $tokens = runtime_context_block_builder::current_user_identity_tokens($anonymizer, $threadid, $store);
        $this->assertNotEmpty($tokens);

        $registry = skill_registry::make_default();
        $service = new agent_decision_service($registry, $store, new authorization_service());
        $mark = new \ReflectionMethod($service, 'mark_self_references');

        $checked = 0;
        foreach ($registry->get_skills() as $skillname => $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            foreach (array_keys($properties) as $field) {
                if (!requester_reference::is_person_field($skill, (string)$field, $anonymizer)) {
                    continue;
                }
                foreach ($tokens as $token) {
                    $commands = [['skill' => (string)$skillname, 'input' => [$field => $token]]];
                    $marked = $mark->invoke($service, $commands, $threadid, $userid);
                    $this->assertSame(
                        requester_reference::MARKER,
                        $marked[0]['input'][$field] ?? null,
                        "{$skillname}.{$field}: the requester's token must become the marker"
                    );
                }
                $commands = [['skill' => (string)$skillname, 'input' => [$field => 'ANON_USER_97_both']]];
                $marked = $mark->invoke($service, $commands, $threadid, $userid);
                $this->assertSame('ANON_USER_97_both', $marked[0]['input'][$field] ?? null, "{$skillname}.{$field}");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked, 'the registry must expose person fields, otherwise this test checks nothing');

        // A person list: the requester among others, as a comma list (booking) and as an array (report audiences).
        $list = $mark->invoke($service, [
            ['skill' => 'mod_booking.book_users', 'input' => ['bookusersquery' => 'ANON_USER_97_both, ' . $tokens[0]]],
            ['skill' => 'report.set_report_audience', 'input' => ['userqueries' => ['ANON_USER_97_both', $tokens[0]]]],
        ], $threadid, $userid);
        $this->assertSame('ANON_USER_97_both, ' . requester_reference::MARKER, $list[0]['input']['bookusersquery']);
        $this->assertSame(['ANON_USER_97_both', requester_reference::MARKER], $list[1]['input']['userqueries']);

        // An id is left alone: it resolves to the requester without help.
        $ids = $mark->invoke($service, [['skill' => '', 'input' => ['userquery' => (string)$userid]]], $threadid, $userid);
        $this->assertSame((string)$userid, $ids[0]['input']['userquery']);
    }

    /**
     * Every engine-side person resolver reads the marker as the acting user.
     */
    public function test_engine_person_resolvers_read_the_marker_as_the_acting_user(): void {
        $this->setUser($this->teacher);
        $userid = (int)$this->teacher->id;

        // Report audiences.
        $audience = (new audience_service())->resolve_users([requester_reference::MARKER], $userid);
        $this->assertSame([$userid], $audience['ids']);
        $this->assertNull($audience['problem']);

        // Core skills: the userquery resolver (with and without a default user) and the shared lookup.
        $core = new \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill();
        $resolve = new \ReflectionMethod($core, 'resolve_userid');
        $lookup = new \ReflectionMethod($core, 'search_user_candidates_for_preview');
        $this->assertSame($userid, $resolve->invoke($core, ['userquery' => requester_reference::MARKER], $userid));
        $this->assertSame($userid, $resolve->invoke($core, ['userquery' => requester_reference::MARKER], 0));
        $hits = $lookup->invoke($core, requester_reference::MARKER, 5);
        $this->assertCount(1, $hits);
        $this->assertSame($userid, (int)$hits[0]['userid']);
    }

    /**
     * Every person field a skill names through a yes/no companion (its empty value does not mean the requester) is a
     * person field, its companion line follows the field line in the constructor's view, no other field gets one, and
     * a set companion becomes the requester marker - next to the people already named - while an unset one only
     * disappears.
     */
    public function test_requester_flag_fields_get_a_companion_that_becomes_the_marker(): void {
        $store = new \bookingextension_agent\local\wizard\conversation_store();
        $anonymizer = new privacy_anonymizer($store);
        $declared = 0;
        foreach (skill_registry::make_default()->get_skills() as $skillname => $skill) {
            $flags = requester_reference::flag_fields($skill);
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $lines = skill_input_schema_projection::for_skill($skill);
            foreach ($lines as $i => $line) {
                if (!str_contains($line, '_is_requester (boolean')) {
                    continue;
                }
                $field = substr($line, 0, (int)strpos($line, '_is_requester ('));
                $this->assertArrayHasKey($field, $flags, "{$skillname}: a companion only for a declared field");
                $this->assertStringStartsWith($field . ' (', (string)($lines[$i - 1] ?? ''), "{$skillname}.{$field}");
            }
            foreach ($flags as $field => $description) {
                if (!array_key_exists($field, $properties)) {
                    continue;
                }
                $declared++;
                $this->assertNotSame('', $description, "{$skillname}.{$field}");
                $this->assertTrue(
                    requester_reference::is_person_field($skill, (string)$field, $anonymizer),
                    "{$skillname}.{$field} must be a person field"
                );
                $flag = requester_reference::flag_name($field);
                $marked = requester_reference::apply_flags($skill, [$flag => true]);
                $this->assertSame([$field => requester_reference::MARKER], $marked);
                $this->assertSame([], requester_reference::apply_flags($skill, [$flag => false]));
                $this->assertSame(['x' => 1], requester_reference::apply_flags($skill, ['x' => 1]));
            }
        }
        $this->assertGreaterThan(0, $declared, 'at least one skill must declare a requester flag field');

        // Next to the people already named: a comma list and an array keep them.
        $booking = skill_registry::make_default()->get_skill('mod_booking.update_option');
        $this->assertSame(
            ['selectusersquery' => 'Anna Muster, ' . requester_reference::MARKER],
            requester_reference::apply_flags($booking, [
                'selectusersquery' => 'Anna Muster',
                'selectusersquery_is_requester' => true,
            ])
        );
        $audience = skill_registry::make_default()->get_skill('report.set_report_audience');
        $this->assertSame(
            ['userqueries' => ['ANON_USER_9_both', requester_reference::MARKER]],
            requester_reference::apply_flags($audience, [
                'userqueries' => ['ANON_USER_9_both'],
                'userqueries_is_requester' => 'true',
            ])
        );
    }
}
