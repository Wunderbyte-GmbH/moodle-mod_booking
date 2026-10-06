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

use mod_booking\local\wizard\engine_component;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\wizard\booking\support\booking_rules_agent_service;
use mod_booking\local\wizard\options\skills\create_rule_from_template_skill;
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/classes/booking_advanced_testcase.php');

/**
 * A template choice never carries an attribute under the name of an input field of the skill.
 *
 * CRT-2, L44 thread 13616 (regression of ebaeae3d57): "two days before their course starts" was first built with
 * days=2 and a template word. The choices listed "Notification n days before start" with days=3 - the template's
 * default under the name of the input field that takes the requested number. The re-planned construction read it as
 * the template's fixed value, answered that no template fits two days and dropped days=2. A template's own values are
 * its defaults, so they are named apart from the fields a request sets.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_rule_from_template_skill
 */
final class wizard_template_choice_attributes_test extends booking_advanced_testcase {
    /**
     * Rule templates active.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingruletemplatesactive', 1, 'booking');
    }

    /**
     * No choice attribute shares its name with an input field of a skill that offers these choices.
     */
    public function test_choice_attributes_are_named_apart_from_input_fields(): void {
        $choices = create_rule_from_template_skill::template_choices((new booking_rules_agent_service())->template_candidates());
        $this->assertNotEmpty($choices);
        $attributes = [];
        foreach ($choices as $choice) {
            $attributes += array_flip(array_diff(array_keys($choice), ['id', 'label']));
        }
        $this->assertNotEmpty($attributes, 'the choices carry what each template does');
        foreach ([new create_rule_from_template_skill(), new update_rule_from_template_skill()] as $skill) {
            $fields = array_keys((array)($skill->get_schema()['properties'] ?? []));
            $this->assertSame(
                [],
                array_values(array_intersect(array_keys($attributes), $fields)),
                get_class($skill) . ': a template value under an input field name reads as the value of the request'
            );
        }
    }
}
