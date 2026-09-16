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
 * Everything a role that held mod/booking:addeditownoption could do.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_course;
use context_system;
use core_customfield\field_controller;
use mod_booking\customfield\booking_handler;
use mod_booking\external\search_teachers;
use mod_booking\form\dynamicoptiondateform;
use mod_booking\form\editteachersforoptiondate_form;
use mod_booking\form\option_form;
use mod_booking\local\option_edit_access;
use mod_booking\local\ownoption_capabilities;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking\local\wizard\options\skills\update_option_trainer_skill;
use mod_booking\option\fields\moveoption;
use mod_booking\output\bookingoption_description;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\capability_testcase;
use MoodleQuickForm;
use navigation_node;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");
require_once("$CFG->libdir/formslib.php");

/**
 * Everything a role that held mod/booking:addeditownoption could do.
 *
 * mod/booking:addeditownoption was split into smaller capabilities for own
 * booking options. Customers must not notice: a role that held
 * addeditownoption gets all new capabilities from the migration and has to
 * be able to do exactly the same things. The assertions were written against
 * addeditownoption before the split and did not change; only
 * ROLE_CAPABILITIES did.
 *
 * Every test also checks a user WITHOUT the role, so a test cannot pass
 * because a gate became open for everybody.
 *
 * Page scripts without a class to call (report.php mailto button,
 * optiondates_teachers_report.php, teachers_instance_report.php,
 * teacher_performed_units_report.php,
 * bookinginstancetemplatessettings.php) are covered
 * by the Behat feature tests/behat/booking_own_option_role.feature.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class own_option_role_test extends capability_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * The capabilities of the role under test: the capabilities a role holding
     * addeditownoption gets from the migration.
     */
    private const ROLE_CAPABILITIES = ownoption_capabilities::NEW_CAPABILITIES;

    /**
     * Opening the option form (editoptions.php): allowed for the options the
     * user teaches, refused for other options.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     */
    public function test_role_opens_the_option_form_of_own_options(): void {
        [$own, $other, $user] = $this->create_own_and_other_option();
        $cmid = (int)$own->cmid;

        $this->assertTrue(option_edit_access::can_edit_option($cmid, (int)$own->id));
        $this->assertFalse(option_edit_access::can_edit_option($cmid, (int)$other->id));

        $this->make_teacher_of((int)$own->id, (int)$this->user_with([])->id);
        $this->assertFalse(
            option_edit_access::can_edit_option($cmid, (int)$own->id),
            'Being teacher of the option alone must not open the form.'
        );
        $this->assertNotEmpty($user->id);
    }

    /**
     * Saving the option form (AJAX submission of option_form): allowed for the
     * options the user teaches. Options of other people are refused since the
     * ownership check was added to the form on MOODLE_405_DEV.
     *
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     */
    public function test_role_passes_the_option_form_submission(): void {
        [$own, $other] = $this->create_own_and_other_option();
        $cmid = (int)$own->cmid;

        $this->assertNull($this->run_form_access_check(option_form::class, ['cmid' => $cmid, 'id' => (int)$own->id]));
        $this->assertNotNull($this->run_form_access_check(option_form::class, ['cmid' => $cmid, 'id' => (int)$other->id]));

        $this->user_with([]);
        $this->assertNotNull($this->run_form_access_check(option_form::class, ['cmid' => $cmid, 'id' => (int)$own->id]));
    }

    /**
     * The action menu in the options list: the role gets these entries for
     * the options the user teaches, and none of them for other options.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_role_sees_the_menu_entries_of_own_options(): void {
        global $PAGE;

        $PAGE->set_url('/mod/booking/view.php');
        set_config('teachersallowmailtobookedusers', 1, 'booking');

        $own = $this->create_option();
        $this->book_students(1);
        $other = $this->add_option('Option of somebody else');

        $user = $this->role_user();
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $entries = [
            'edit option' => 'editoptions.php?id=' . $own->cmid . '&amp;optionid=' . $own->id,
            'manage bookings' => '/mod/booking/report.php',
            'bookings tracker' => '/mod/booking/report2.php',
            'teacher substitutions' => '/mod/booking/optiondates_teachers_report.php',
            'create options from dates' => 'createfromoptiondates=1',
            'show only this option' => 'whichview=showonlyone',
            'mail to booked users' => 'mailto:',
        ];

        $table = new bookingoptions_wbtable('ownoptionrole_own');
        $ownmenu = $table->col_action((object)['id' => (int)$own->id, 'status' => 0]);
        foreach ($entries as $name => $needle) {
            $this->assertStringContainsString($needle, $ownmenu, "Own option: the role must see '$name'.");
        }

        $othermenu = $table->col_action((object)['id' => (int)$other->id, 'status' => 0]);
        foreach ($entries as $name => $needle) {
            $this->assertStringNotContainsString($needle, $othermenu, "Other option: the role must not see '$name'.");
        }

        // A user without the role, but teacher of the same option, sees none of them.
        $teacher = $this->user_with([]);
        $this->make_teacher_of((int)$own->id, (int)$teacher->id);
        $this->setUser($teacher);
        $table = new bookingoptions_wbtable('ownoptionrole_teacher');
        $teachermenu = $table->col_action((object)['id' => (int)$own->id, 'status' => 0]);
        foreach ($entries as $name => $needle) {
            $this->assertStringNotContainsString($needle, $teachermenu, "Without the role there is no '$name'.");
        }
    }

    /**
     * The detail view of an option: edit link and "manage bookings" link for
     * the options the user teaches only.
     *
     * @covers \mod_booking\output\bookingoption_description::__construct
     * @covers \mod_booking\output\bookingoption_description::export_for_template
     */
    public function test_role_sees_edit_and_manage_bookings_in_own_option_details(): void {
        global $PAGE;

        [$own, $other] = $this->create_own_and_other_option();
        $output = $PAGE->get_renderer('mod_booking');

        $owndescription = new bookingoption_description((int)$own->id);
        $this->assertNotEmpty($owndescription->export_for_template($output)['editurl']);
        $this->assertTrue((bool)$this->manage_bookings_shown($owndescription));

        $otherdescription = new bookingoption_description((int)$other->id);
        $this->assertEmpty($otherdescription->export_for_template($output)['editurl']);
        $this->assertFalse((bool)$this->manage_bookings_shown($otherdescription));

        // Teacher of the option, but without the role.
        $teacher = $this->user_with([]);
        $this->make_teacher_of((int)$own->id, (int)$teacher->id);
        $this->setUser($teacher);
        $teacherdescription = new bookingoption_description((int)$own->id);
        $this->assertEmpty($teacherdescription->export_for_template($output)['editurl']);
        $this->assertFalse((bool)$this->manage_bookings_shown($teacherdescription));
    }

    /**
     * The form to add a series of session dates: allowed for any option.
     *
     * @covers \mod_booking\form\dynamicoptiondateform::check_access_for_dynamic_submission
     */
    public function test_role_opens_the_session_dates_form(): void {
        [$own, $other] = $this->create_own_and_other_option();
        $cmid = (int)$own->cmid;

        $this->assertNull($this->run_form_access_check(dynamicoptiondateform::class, [
            'cmid' => $cmid,
            'optionid' => (int)$other->id,
        ]));

        $this->user_with([]);
        $this->assertNotNull($this->run_form_access_check(dynamicoptiondateform::class, [
            'cmid' => $cmid,
            'optionid' => (int)$other->id,
        ]));
    }

    /**
     * The form to change the teacher of a single session date (substitution).
     *
     * @covers \mod_booking\form\editteachersforoptiondate_form::check_access_for_dynamic_submission
     */
    public function test_role_opens_the_session_teachers_form(): void {
        [$own] = $this->create_own_and_other_option();
        $cmid = (int)$own->cmid;

        $this->assertNull($this->run_form_access_check(editteachersforoptiondate_form::class, ['cmid' => $cmid]));

        $this->user_with([]);
        $this->assertNotNull($this->run_form_access_check(editteachersforoptiondate_form::class, ['cmid' => $cmid]));
    }

    /**
     * Custom fields of booking options: configuring the categories and seeing
     * fields marked "visible to teachers". Both are checked in the system
     * context, so the role has to be assigned site wide.
     *
     * @covers \mod_booking\customfield\booking_handler::can_configure
     * @covers \mod_booking\customfield\booking_handler::can_view
     */
    public function test_role_configures_and_sees_teacher_custom_fields(): void {
        $this->create_option();
        $field = $this->create_custom_field_visible_to_teachers();

        $this->role_user(context_system::instance());
        $handler = booking_handler::create();
        $this->assertTrue($handler->can_configure());
        $this->assertTrue($handler->can_view($field, 0));

        $this->user_with([], context_system::instance());
        $handler = booking_handler::create();
        $this->assertFalse($handler->can_configure());
        $this->assertFalse($handler->can_view($field, 0));
    }

    /**
     * Moving an option: the role opens a booking activity as move target.
     *
     * @covers \mod_booking\option\fields\moveoption::instance_form_definition
     */
    public function test_role_offers_other_activities_as_move_target(): void {
        $own = $this->create_option();
        $target = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Move target booking',
            'course' => $this->course->id,
        ]);
        $coursecontext = context_course::instance((int)$this->course->id);

        $this->role_user($coursecontext);
        $this->assertContains((int)$target->cmid, $this->move_targets((int)$own->id, (int)$own->cmid));

        $this->user_with([], $coursecontext);
        $this->assertNotContains((int)$target->cmid, $this->move_targets((int)$own->id, (int)$own->cmid));
    }

    /**
     * The settings navigation of an option page: "edit booking option" and
     * "manage bookings". There is no ownership check here.
     *
     * @covers ::booking_extend_settings_navigation
     */
    public function test_role_sees_edit_and_manage_bookings_in_the_navigation(): void {
        global $PAGE;

        [$own, $other] = $this->create_own_and_other_option();
        [$course, $cm] = get_course_and_cm_from_cmid((int)$own->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url('/mod/booking/view.php', ['id' => $cm->id, 'optionid' => (int)$other->id]);

        $nodes = $this->navigation_keys();
        $this->assertContains('nav_edit', $nodes);
        $this->assertContains('nav_manageresponses', $nodes);

        $this->user_with([]);
        $nodes = $this->navigation_keys();
        $this->assertNotContains('nav_edit', $nodes);
        $this->assertNotContains('nav_manageresponses', $nodes);
    }

    /**
     * The webservices behind the option form (autocomplete search fields).
     *
     * @covers \mod_booking\permissions::has_any_booking_editing_capability
     * @covers \mod_booking\external\search_teachers::execute
     */
    public function test_role_uses_the_search_webservices_of_the_option_form(): void {
        $this->create_option();

        $this->role_user();
        $this->assertTrue(permissions::has_any_booking_editing_capability());
        $this->assertArrayHasKey('list', search_teachers::execute('someone'));

        $this->user_with([]);
        $this->assertFalse(permissions::has_any_booking_editing_capability());
    }

    /**
     * The option wizard skills that change options: the role passes their
     * native capability check. The skill capabilities themselves are separate
     * capabilities and given to both users.
     *
     * @covers \mod_booking\local\wizard\options\skills\update_option_skill::run_preflight
     * @covers \mod_booking\local\wizard\options\skills\update_option_trainer_skill::run_preflight
     * @covers \mod_booking\local\wizard\options\skills\bulk_update_options_skill::run_preflight
     */
    public function test_role_passes_the_capability_check_of_the_wizard_skills(): void {
        // The skills need the agent engine plugin.
        $this->skip_without_agent_extension();

        $own = $this->create_option();
        $contextid = (int)\context_module::instance((int)$own->cmid)->id;
        $skillcapabilities = [
            'mod/booking:skill_mod_booking_update_option',
            'mod/booking:skill_mod_booking_update_option_trainer',
            'mod/booking:skill_mod_booking_bulk_update_options',
        ];

        $user = $this->user_with(array_merge(self::ROLE_CAPABILITIES, $skillcapabilities));
        foreach ($this->wizard_skill_calls((int)$own->id, (int)$user->id) as $name => $call) {
            $this->assertNotContains('NO_NATIVE_CAPABILITY', $call($contextid, (int)$user->id), "$name with the role.");
        }

        $user = $this->user_with($skillcapabilities);
        foreach ($this->wizard_skill_calls((int)$own->id, (int)$user->id) as $name => $call) {
            $this->assertContains('NO_NATIVE_CAPABILITY', $call($contextid, (int)$user->id), "$name without the role.");
        }
    }

    /**
     * Creates a user holding the role under test and logs them in.
     *
     * @param \context|null $context assignment context, defaults to the module context of the option
     * @return \stdClass
     */
    private function role_user(?\context $context = null): \stdClass {
        return $this->user_with(self::ROLE_CAPABILITIES, $context);
    }

    /**
     * An option the logged in role user teaches and an option they do not teach.
     *
     * @return array{0: booking_option_settings, 1: booking_option_settings, 2: \stdClass}
     */
    private function create_own_and_other_option(): array {
        $own = $this->create_option();
        $other = $this->add_option('Option of somebody else');

        $user = $this->role_user();
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        return [$own, $other, $user];
    }

    /**
     * Whether the detail view offers the "manage bookings" link.
     *
     * @param bookingoption_description $description
     * @return bool|null
     */
    private function manage_bookings_shown(bookingoption_description $description): ?bool {
        $property = new \ReflectionProperty($description, 'showmanageresponses');
        $property->setAccessible(true);
        return $property->getValue($description);
    }

    /**
     * Creates a booking option custom field that is visible to teachers only.
     *
     * @return field_controller
     */
    private function create_custom_field_visible_to_teachers(): field_controller {
        /** @var \core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category([
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
        ]);
        return $generator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => 'teachersonly',
            'configdata' => ['visibility' => booking_handler::MOD_BOOKING_VISIBLETOTEACHERS],
        ]);
    }

    /**
     * The booking activities offered as move target for the option.
     *
     * @param int $optionid
     * @param int $cmid
     * @return int[] course module ids
     */
    private function move_targets(int $optionid, int $cmid): array {
        $mform = new MoodleQuickForm('movetargets', 'post', '');
        $formdata = ['id' => $optionid, 'cmid' => $cmid];
        moveoption::instance_form_definition($mform, $formdata, []);

        if (!$mform->elementExists('moveoption')) {
            return [];
        }
        $targets = [];
        foreach ($mform->getElement('moveoption')->_options as $option) {
            $targets[] = (int)$option['attr']['value'];
        }
        return array_values(array_filter($targets));
    }

    /**
     * The keys of the nodes booking adds to the settings navigation of the current page.
     *
     * @return string[]
     */
    private function navigation_keys(): array {
        global $PAGE;

        $root = navigation_node::create('root');
        booking_extend_settings_navigation($PAGE->settingsnav, $root);
        return array_map('strval', $root->get_children_key_list());
    }

    /**
     * The preflight calls of the option wizard skills, each returning the issue codes.
     *
     * @param int $optionid
     * @param int $userid
     * @return callable[]
     */
    private function wizard_skill_calls(int $optionid, int $userid): array {
        $codes = static fn($dto): array => (array)($dto->to_array()['issue_codes'] ?? []);
        return [
            'update_option' => fn(int $contextid, int $uid) => $codes((new update_option_skill())->preflight(
                ['optionid' => $optionid, 'text' => 'Renamed option'],
                $contextid,
                $uid
            )),
            'update_option_trainer' => fn(int $contextid, int $uid) => $codes((new update_option_trainer_skill())->preflight(
                ['optionid' => $optionid, 'teacherids' => [$userid]],
                $contextid,
                $uid
            )),
            'bulk_update_options' => fn(int $contextid, int $uid) => $codes((new bulk_update_options_skill())->preflight(
                ['optionids' => [$optionid], 'maxanswers' => 9],
                $contextid,
                $uid
            )),
        ];
    }
}
