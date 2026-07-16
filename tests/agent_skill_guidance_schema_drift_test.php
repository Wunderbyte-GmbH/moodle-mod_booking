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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Guard against schema/guidance drift in the wizard skills' contextual prompt packs.
 *
 * A guidance line that recommends a parameter key the skill's schema does not accept makes the
 * LLM produce exactly that key and run into a schema reject (thread 550: create_option guidance
 * still said "Use coursequery …" long after the key had been removed from the create schema).
 * Guidance packs are never validated against the schema at runtime, so this test does it:
 *
 * Every token in a guidance line that IS a known parameter key of any registered skill must be
 * accepted by the skill the line belongs to — or the line must explicitly name the skill the key
 * belongs to (two-step advice like "… then set coursequery via mod_booking.update_option" is the
 * documented pattern). Purely negative advice ("do NOT use …", "never invent …") and override-token
 * enumerations are exempt.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 */
final class agent_skill_guidance_schema_drift_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Generic English words that double as parameter keys of some skill. As prose they appear in
     * guidance constantly without recommending the key ("answer their question", "type and
     * description"), so they carry no drift signal — the compound keys (…query, customform…,
     * bookusers…, enrolledin…) are the family where drift actually bites.
     *
     * @var array<int,string>
     */
    private const PROSE_WORDS = [
        'question', 'description', 'properties', 'location', 'address', 'identifier',
        'confirmed', 'visibility', 'invisible', 'duration', 'courseid', 'capability',
        'category', 'override',
    ];

    /**
     * Every parameter key recommended in a guidance line is accepted by the skill it is
     * recommended for (the pack's own skill, or a skill the line explicitly names).
     */
    public function test_guidance_packs_recommend_only_schema_keys(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $registry = \bookingextension_agent\local\wizard\skill_registry::make_default();

        // Map skill name => set of accepted schema property keys, plus the union of all keys.
        $schemakeys = [];
        $allknownkeys = [];
        foreach ($registry->get_skill_names() as $skillname) {
            $skill = $registry->get_skill($skillname);
            if ($skill === null) {
                continue;
            }
            $properties = (array)(($skill->get_schema())['properties'] ?? []);
            $keys = array_map('strval', array_keys($properties));
            $schemakeys[$skillname] = array_fill_keys($keys, true);
            foreach ($keys as $key) {
                $allknownkeys[$key] = true;
            }
        }
        $this->assertNotEmpty($schemakeys, 'The skill registry must expose skills with schemas.');

        $violations = [];
        foreach ($registry->get_skill_names() as $skillname) {
            $skill = $registry->get_skill($skillname);
            if ($skill === null || !method_exists($skill, 'get_contextual_prompt_packs')) {
                continue;
            }
            foreach ((array)$skill->get_contextual_prompt_packs() as $pack) {
                $packid = (string)($pack['id'] ?? '?');
                foreach ((array)($pack['guidance'] ?? []) as $line) {
                    $line = (string)$line;
                    // Negative advice and override-token enumerations legitimately name
                    // keys that must not (or cannot) be sent.
                    if (preg_match('/do not|never|override token/i', $line)) {
                        continue;
                    }

                    // Keys accepted in this line: the pack's own skill plus every skill the
                    // line names explicitly (matched by full or dot-suffixed name).
                    $allowed = $schemakeys[$skillname] ?? [];
                    if (preg_match_all('/[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]+)+/', strtolower($line), $named)) {
                        foreach ($named[0] as $namedskill) {
                            foreach ($schemakeys as $registeredname => $keys) {
                                if (
                                    $registeredname === $namedskill
                                    || str_ends_with($registeredname, '.' . substr($namedskill, strpos($namedskill, '.') + 1))
                                ) {
                                    $allowed += $keys;
                                }
                            }
                        }
                    }

                    if (!preg_match_all('/\b[a-z][a-z0-9_]{7,}\b/', strtolower($line), $tokens)) {
                        continue;
                    }
                    foreach (array_unique($tokens[0]) as $token) {
                        if (
                            !isset($allknownkeys[$token])
                            || isset($allowed[$token])
                            || in_array($token, self::PROSE_WORDS, true)
                        ) {
                            continue;
                        }
                        $violations[] = $skillname . ' [' . $packid . ']: recommends "' . $token
                            . '" which its schema does not accept — line: ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Guidance/schema drift found (fix the guidance line or name the owning skill in it):\n"
            . implode("\n", $violations)
        );
    }
}
