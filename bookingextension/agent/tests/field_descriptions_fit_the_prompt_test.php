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

use bookingextension_agent\local\wizard\core\skills\diagnose_notifications_skill;
use bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill;
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\course\skills\update_quiz_skill;
use bookingextension_agent\local\wizard\question\skills\generate_questions_skill;
use bookingextension_agent\local\wizard\wizard\skills\scaffold_skill;

/**
 * The field descriptions of the question skills reach the constructor whole.
 *
 * Wave 30 (2026-09-25): skill_input_schema_projection cuts a field description at 160 characters. The decisive
 * sentence of generate_questions.usecoursepdfs ("do NOT ask the user to upload them again", 333 characters) and of
 * coursequery / resourcecmid sat behind the cut, and the constructor kept asking "which PDF?" (GQ-1). 117 of 864 field
 * descriptions in 49 skills were longer than the cap; the question skills are fixed here, the rest is a later wave.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\question\skills\generate_questions_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_quiz_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_quiz_skill
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\scaffold_skill
 * @covers     \bookingextension_agent\local\wizard\core\skills\diagnose_notifications_skill
 * @covers     \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill
 */
final class field_descriptions_fit_the_prompt_test extends \advanced_testcase {
    /** The cap of skill_input_schema_projection::MAX_DESCRIPTION_CHARS. */
    private const CAP = 160;

    /**
     * No field description of the question skills is cut in the constructor prompt.
     */
    public function test_no_field_description_is_cut(): void {
        $reflection = new \ReflectionClassConstant(
            \bookingextension_agent\local\wizard\services\skill_input_schema_projection::class,
            'MAX_DESCRIPTION_CHARS'
        );
        $this->assertSame(self::CAP, $reflection->getValue(), 'the cap this test pins');
        foreach ([new generate_questions_skill(), new add_quiz_skill(), new update_quiz_skill()] as $skill) {
            foreach ((array)($skill->get_schema()['properties'] ?? []) as $field => $definition) {
                $text = trim((string)preg_replace('/\s+/u', ' ', (string)($definition['description'] ?? '')));
                $this->assertLessThanOrEqual(
                    self::CAP,
                    \core_text::strlen($text),
                    $skill->get_name() . '.' . $field . ': ' . $text
                );
            }
        }
    }

    /**
     * Wave 32 (A3): the field texts rewritten in this wave reach the constructor whole - every scaffold_skill field
     * and the userquery field of both diagnosis skills (the other diagnose_permissions fields are older and longer).
     */
    public function test_wave32_rewritten_field_descriptions_are_not_cut(): void {
        $fields = [];
        foreach ((array)((new scaffold_skill())->get_schema()['properties'] ?? []) as $field => $definition) {
            $fields['wizard.scaffold_skill.' . $field] = (string)($definition['description'] ?? '');
        }
        foreach ([new diagnose_notifications_skill(), new diagnose_permissions_skill()] as $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('userquery', $properties, $skill->get_name());
            $fields[$skill->get_name() . '.userquery'] = (string)($properties['userquery']['description'] ?? '');
        }
        foreach ($fields as $key => $description) {
            $text = trim((string)preg_replace('/\s+/u', ' ', $description));
            $this->assertNotSame('', $text, $key);
            $this->assertLessThanOrEqual(self::CAP, \core_text::strlen($text), $key . ': ' . $text);
        }
    }
}
