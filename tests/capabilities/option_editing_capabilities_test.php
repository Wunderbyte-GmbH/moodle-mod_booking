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
 * Tests for the option editing capabilities (capability table 1c).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_course;
use context_system;
use core_customfield_generator;
use context_module;
use mod_booking\customfield\booking_handler;
use mod_booking\external\optiontemplate;
use mod_booking\form\certificateconditionsform;
use mod_booking\form\deletecertificateconditionform;
use mod_booking\form\deleteruleform;
use mod_booking\form\rulesform;
use mod_booking\form\slotteacherassignments_form;
use mod_booking\form\teacherunavailability_form;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\external\get_option_field_config;
use mod_booking\settings\optionformconfig\optionformconfig_info;
use mod_booking\tests\capability_testcase;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * Every row of the "option editing" table: the documented default (context
 * level + archetypes) and, where the gate is reachable from a class, that the
 * capability really unlocks what the table claims.
 *
 * The three editing layers themselves (addoption / addeditownoption /
 * updatebooking and limitededitownoption) are only enforced inline in
 * editoptions.php and in the dynamic forms - they get their own test files.
 * manageoptiondates has no call site in the code at all at the moment; it is
 * only defined and documented, so only its definition is asserted.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class option_editing_capabilities_test extends capability_testcase {
    /**
     * Context level and archetype defaults of the option editing capabilities.
     *
     * @return array
     */
    public static function capability_default_provider(): array {
        return [
            'addoption' => ['mod/booking:addoption', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'addeditownoption' => ['mod/booking:addeditownoption', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'limitededitownoption' => [
                'mod/booking:limitededitownoption',
                CONTEXT_MODULE,
                ['editingteacher', 'manager'],
            ],
            'updatebooking' => ['mod/booking:updatebooking', CONTEXT_MODULE, ['coursecreator', 'manager']],
            'expertoptionform' => ['mod/booking:expertoptionform', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'reducedoptionform1' => ['mod/booking:reducedoptionform1', CONTEXT_MODULE, []],
            'reducedoptionform2' => ['mod/booking:reducedoptionform2', CONTEXT_MODULE, []],
            'reducedoptionform3' => ['mod/booking:reducedoptionform3', CONTEXT_MODULE, []],
            'reducedoptionform4' => ['mod/booking:reducedoptionform4', CONTEXT_MODULE, []],
            'reducedoptionform5' => ['mod/booking:reducedoptionform5', CONTEXT_MODULE, []],
            'manageoptiondates' => ['mod/booking:manageoptiondates', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'manageoptiontemplates' => ['mod/booking:manageoptiontemplates', CONTEXT_MODULE, ['manager']],
            'changelockedcustomfields' => ['mod/booking:changelockedcustomfields', CONTEXT_MODULE, ['manager']],
            'manageslotunavailability' => [
                'mod/booking:manageslotunavailability',
                CONTEXT_MODULE,
                ['teacher', 'editingteacher', 'manager'],
            ],
            'moveslots' => ['mod/booking:moveslots', CONTEXT_MODULE, ['coursecreator', 'manager']],
            'moveslotsself' => [
                'mod/booking:moveslotsself',
                CONTEXT_MODULE,
                ['user', 'student', 'editingteacher', 'manager'],
            ],
            'editoptionformconfig' => ['mod/booking:editoptionformconfig', CONTEXT_SYSTEM, ['manager']],
            'editbookingrules' => ['mod/booking:editbookingrules', CONTEXT_COURSE, ['manager']],
            'editcertificateconditions' => ['mod/booking:editcertificateconditions', CONTEXT_COURSE, ['manager']],
        ];
    }

    /**
     * The capabilities are defined on the documented context level with the
     * documented archetype defaults.
     *
     * @param string $capability
     * @param int $contextlevel
     * @param array $archetypes
     * @dataProvider capability_default_provider
     * @covers \mod_booking\tests\capability_testcase::assert_capability_default
     */
    public function test_capability_defaults(string $capability, int $contextlevel, array $archetypes): void {
        $this->assert_capability_default($capability, $contextlevel, $archetypes);
    }

    /**
     * The form profile is the FIRST capability of the fixed order the user
     * holds: without any profile there is none, a reduced profile alone
     * selects that profile - but expertoptionform always wins over a reduced
     * one, so granting a reduced profile on top of it changes nothing.
     *
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info::return_capability_for_user
     */
    public function test_form_profile_capabilities_are_evaluated_in_order(): void {
        $this->create_option();
        $contextid = \context_module::instance((int)$this->settings->cmid)->id;

        $this->user_with([]);
        $this->assertSame('', optionformconfig_info::return_capability_for_user($contextid));

        $this->user_with(['mod/booking:reducedoptionform1']);
        $this->assertSame(
            'mod/booking:reducedoptionform1',
            optionformconfig_info::return_capability_for_user($contextid)
        );

        $this->user_with(['mod/booking:expertoptionform', 'mod/booking:reducedoptionform1']);
        $this->assertSame(
            'mod/booking:expertoptionform',
            optionformconfig_info::return_capability_for_user($contextid),
            'expertoptionform comes first in the order and blocks the reduced profile.'
        );
    }

    /**
     * mod/booking:editoptionformconfig unlocks the option form configuration
     * (and has to be assigned globally: the service checks the system context).
     *
     * @covers \mod_booking\external\get_option_field_config::execute
     */
    public function test_editoptionformconfig_unlocks_the_form_configuration(): void {
        $this->create_option();
        $contextid = context_system::instance()->id;

        // Assigned in the module context the system context check still fails.
        $this->user_with(['mod/booking:editoptionformconfig']);
        try {
            get_option_field_config::execute($contextid);
            $this->fail('Without the capability in the system context the configuration must stay closed.');
        } catch (required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->user_with(['mod/booking:editoptionformconfig'], context_system::instance());
        $this->assertIsArray(get_option_field_config::execute($contextid));
    }

    /**
     * mod/booking:changelockedcustomfields unlocks editing locked custom
     * fields; unlocked fields stay editable for everybody.
     *
     * @covers \mod_booking\customfield\booking_handler::can_edit
     */
    public function test_changelockedcustomfields_unlocks_locked_fields(): void {
        $this->create_option();

        /** @var core_customfield_generator $cfgenerator */
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgenerator->create_category([
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $lockedfield = $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'lockedfield',
            'type' => 'text',
            'configdata' => ['locked' => 1],
        ]);
        $openfield = $cfgenerator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'openfield',
            'type' => 'text',
            'configdata' => ['locked' => 0],
        ]);

        $handler = booking_handler::create();

        $this->user_with([]);
        $this->assertTrue($handler->can_edit($openfield, 1), 'Unlocked fields are editable without the capability.');
        $this->assertFalse($handler->can_edit($lockedfield, 1));

        $this->user_with(['mod/booking:changelockedcustomfields'], context_system::instance());
        $this->assertTrue($handler->can_edit($lockedfield, 1));
    }

    /**
     * The two course level capabilities are checked in the course context, so
     * a module level assignment does not open them (edit_rules.php:63,
     * edit_certificateconditions.php:58).
     *
     * @covers \mod_booking\tests\capability_testcase::user_with
     */
    public function test_rules_and_certificate_capabilities_are_course_level(): void {
        $this->create_option();
        $coursecontext = context_course::instance((int)$this->course->id);

        $this->user_with(['mod/booking:editbookingrules', 'mod/booking:editcertificateconditions']);
        $this->assertFalse(has_capability('mod/booking:editbookingrules', $coursecontext));
        $this->assertFalse(has_capability('mod/booking:editcertificateconditions', $coursecontext));

        $this->user_with(
            ['mod/booking:editbookingrules', 'mod/booking:editcertificateconditions'],
            $coursecontext
        );
        $this->assertTrue(has_capability('mod/booking:editbookingrules', $coursecontext));
        $this->assertTrue(has_capability('mod/booking:editcertificateconditions', $coursecontext));
    }

    /**
     * mod/booking:manageoptiontemplates unlocks reading an option template
     * through the webservice - checked in the system context, so it has to be
     * assigned globally.
     *
     * @covers \mod_booking\external\optiontemplate::execute
     */
    public function test_manageoptiontemplates_unlocks_the_template_service(): void {
        $settings = $this->create_option();
        $optionid = (int)$settings->id;

        $this->user_with([]);
        $this->assert_blocked_by_capability(
            $this->capture_exception(fn() => optiontemplate::execute($optionid)),
            'mod/booking:manageoptiontemplates'
        );

        // A module level assignment is not enough: the service checks the system context.
        $this->user_with(['mod/booking:manageoptiontemplates']);
        $this->assert_blocked_by_capability(
            $this->capture_exception(fn() => optiontemplate::execute($optionid)),
            'mod/booking:manageoptiontemplates'
        );

        $this->user_with(['mod/booking:manageoptiontemplates'], context_system::instance());
        $result = optiontemplate::execute($optionid);
        $this->assertSame($optionid, $result['id']);
    }

    /**
     * mod/booking:manageslotunavailability unlocks both forms behind the
     * teacher unavailability feature. Neither needs ownership of an option,
     * but being a teacher of the option opens them as well.
     *
     * @covers \mod_booking\form\teacherunavailability_form::check_access_for_dynamic_submission
     * @covers \mod_booking\form\slotteacherassignments_form::check_access_for_dynamic_submission
     */
    public function test_manageslotunavailability_unlocks_the_unavailability_forms(): void {
        $settings = $this->create_option();
        $formdata = ['id' => (int)$settings->cmid, 'optionid' => (int)$settings->id];

        $this->user_with([]);
        $this->assert_blocked_by_capability(
            $this->run_form_access_check(teacherunavailability_form::class, $formdata),
            'mod/booking:manageslotunavailability'
        );
        $this->assert_blocked_by_capability(
            $this->run_form_access_check(slotteacherassignments_form::class, $formdata),
            'mod/booking:manageslotunavailability'
        );

        $this->user_with(['mod/booking:manageslotunavailability']);
        $this->assertNull($this->run_form_access_check(teacherunavailability_form::class, $formdata));
        $this->assertNull($this->run_form_access_check(slotteacherassignments_form::class, $formdata));
    }

    /**
     * mod/booking:moveslots unlocks moving the slots of other users.
     * slot_mover::move() checks the capability before it touches any data, so
     * a user holding it fails later on the invalid booking answer instead of
     * on the capability.
     *
     * @covers \mod_booking\local\slotbooking\slot_mover::move
     */
    public function test_moveslots_unlocks_moving_other_peoples_slots(): void {
        $settings = $this->create_option();
        $optionid = (int)$settings->id;
        $move = fn() => slot_mover::move($optionid, 0, []);

        $this->user_with([]);
        $this->assert_blocked_by_capability($this->capture_exception($move), 'mod/booking:moveslots');

        $this->user_with(['mod/booking:moveslots']);
        $this->assert_capability_gate_passed($this->capture_exception($move));

        // The capability updatebooking opens the same gate.
        $this->user_with(['mod/booking:updatebooking']);
        $this->assert_capability_gate_passed($this->capture_exception($move));
    }

    /**
     * mod/booking:moveslotsself gates self service rebooking. Unlike every
     * other capability of this table it is granted by the "user" archetype,
     * so EVERY authenticated user holds it by default - it can therefore only
     * be demonstrated by prohibiting it. It is a separate gate from
     * moveslots: neither opens the other.
     *
     * @covers \mod_booking\local\slotbooking\slot_mover::move_self
     * @covers \mod_booking\local\slotbooking\slot_mover::move
     */
    public function test_moveslotsself_gates_moving_your_own_slot(): void {
        $settings = $this->create_option();
        $optionid = (int)$settings->id;
        $moveself = fn() => slot_mover::move_self($optionid, 0, []);
        $move = fn() => slot_mover::move($optionid, 0, []);

        // A plain user already passes the gate - the archetype default.
        $this->user_with([]);
        $this->assertTrue(has_capability('mod/booking:moveslotsself', context_module::instance((int)$settings->cmid)));
        $this->assert_capability_gate_passed($this->capture_exception($moveself));

        // Prohibited: self service rebooking is refused.
        $this->user_with([], null, ['mod/booking:moveslotsself']);
        $this->assert_blocked_by_capability($this->capture_exception($moveself), 'mod/booking:moveslotsself');

        // The capability moveslots does not open self service rebooking ...
        $this->user_with(['mod/booking:moveslots'], null, ['mod/booking:moveslotsself']);
        $this->assert_blocked_by_capability($this->capture_exception($moveself), 'mod/booking:moveslotsself');

        // ... and moveslotsself does not open moving other people's slots.
        $this->user_with(['mod/booking:moveslotsself']);
        $this->assert_blocked_by_capability($this->capture_exception($move), 'mod/booking:moveslots');
    }

    /**
     * mod/booking:editbookingrules is enforced per context in both rule
     * forms: assigned in one course it opens the forms for that course only,
     * not for another course and not for the system context.
     *
     * @covers \mod_booking\form\rulesform::check_access_for_dynamic_submission
     * @covers \mod_booking\form\deleteruleform::check_access_for_dynamic_submission
     */
    public function test_editbookingrules_is_enforced_per_context(): void {
        $this->create_option();
        $granted = context_course::instance((int)$this->course->id);
        $foreign = context_course::instance((int)$this->getDataGenerator()->create_course()->id);

        $this->user_with([]);
        foreach ([rulesform::class, deleteruleform::class] as $formclass) {
            $this->assert_blocked_by_capability(
                $this->run_form_access_check($formclass, ['contextid' => $granted->id]),
                'mod/booking:editbookingrules'
            );
        }

        $this->user_with(['mod/booking:editbookingrules'], $granted);
        foreach ([rulesform::class, deleteruleform::class] as $formclass) {
            $this->assertNull($this->run_form_access_check($formclass, ['contextid' => $granted->id]));
            $this->assert_blocked_by_capability(
                $this->run_form_access_check($formclass, ['contextid' => $foreign->id]),
                'mod/booking:editbookingrules'
            );
            $this->assert_blocked_by_capability(
                $this->run_form_access_check($formclass, ['contextid' => context_system::instance()->id]),
                'mod/booking:editbookingrules'
            );
        }

        // Assigned globally the capability opens every context.
        $this->user_with(['mod/booking:editbookingrules'], context_system::instance());
        $this->assertNull($this->run_form_access_check(rulesform::class, ['contextid' => $foreign->id]));
    }

    /**
     * mod/booking:editcertificateconditions is enforced per context in both
     * certificate condition forms, exactly like the rule forms.
     *
     * @covers \mod_booking\form\certificateconditionsform::check_access_for_dynamic_submission
     * @covers \mod_booking\form\deletecertificateconditionform::check_access_for_dynamic_submission
     */
    public function test_editcertificateconditions_is_enforced_per_context(): void {
        $this->create_option();
        $granted = context_course::instance((int)$this->course->id);
        $foreign = context_course::instance((int)$this->getDataGenerator()->create_course()->id);
        $forms = [certificateconditionsform::class, deletecertificateconditionform::class];

        $this->user_with([]);
        foreach ($forms as $formclass) {
            $this->assert_blocked_by_capability(
                $this->run_form_access_check($formclass, ['contextid' => $granted->id]),
                'mod/booking:editcertificateconditions'
            );
        }

        $this->user_with(['mod/booking:editcertificateconditions'], $granted);
        foreach ($forms as $formclass) {
            $this->assertNull($this->run_form_access_check($formclass, ['contextid' => $granted->id]));
            $this->assert_blocked_by_capability(
                $this->run_form_access_check($formclass, ['contextid' => $foreign->id]),
                'mod/booking:editcertificateconditions'
            );
        }

        $this->user_with(['mod/booking:editcertificateconditions'], context_system::instance());
        $this->assertNull($this->run_form_access_check(certificateconditionsform::class, ['contextid' => $foreign->id]));
    }
}
