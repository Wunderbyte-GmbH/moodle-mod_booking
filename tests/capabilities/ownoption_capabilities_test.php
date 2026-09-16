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
 * Tests that each capability for own booking options opens only its own part.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\dynamicoptiondateform;
use mod_booking\form\editteachersforoptiondate_form;
use mod_booking\form\option_form;
use mod_booking\local\option_edit_access;
use mod_booking\local\ownoption_capabilities;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\capability_testcase;
use navigation_node;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * mod/booking:addeditownoption was split into smaller capabilities. Each of
 * them alone opens only its own part: a teacher of the option holding one of
 * them gets exactly these actions.
 *
 * What a role holding ALL of them can do is tested in own_option_role_test.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ownoption_capabilities_test extends capability_testcase {
    /**
     * The entries of the options menu, by the text that identifies them.
     */
    private const MENU_ENTRIES = [
        'edit' => 'editoptions.php?id={cmid}&amp;optionid={optionid}',
        'createfromdates' => 'createfromoptiondates=1',
        'managebookings' => '/mod/booking/report.php',
        'tracker' => '/mod/booking/report2.php',
        'mail' => 'mailto:',
        'substitutions' => '/mod/booking/optiondates_teachers_report.php',
        'cancel' => 'mod_booking/confirm_cancel',
        'duplicate' => 'copyoptionid={optionid}',
    ];

    /**
     * Each capability and the menu entries it opens alone.
     *
     * @return array
     */
    public static function menu_provider(): array {
        return [
            'editownoption' => ['mod/booking:editownoption', ['edit', 'createfromdates']],
            'managebookingsownoption' => ['mod/booking:managebookingsownoption', ['managebookings', 'tracker']],
            'sendmailownoption' => ['mod/booking:sendmailownoption', ['mail']],
            'editteachersownoption' => ['mod/booking:editteachersownoption', ['substitutions']],
            'cancelownoption' => ['mod/booking:cancelownoption', ['cancel']],
            'duplicateownoption' => ['mod/booking:duplicateownoption', ['duplicate']],
            'viewteacherreports' => ['mod/booking:viewteacherreports', []],
        ];
    }

    /**
     * The options menu: one capability, only its entries - and only for the
     * option the user teaches.
     *
     * @param string $capability
     * @param string[] $expected keys of MENU_ENTRIES
     * @dataProvider menu_provider
     * @covers \mod_booking\table\bookingoptions_wbtable::col_action
     */
    public function test_each_capability_opens_only_its_menu_entries(string $capability, array $expected): void {
        global $PAGE;

        $PAGE->set_url('/mod/booking/view.php');
        set_config('teachersallowmailtobookedusers', 1, 'booking');

        $own = $this->create_option();
        $this->book_students(1);
        $other = $this->add_option('Option of somebody else');

        $user = $this->user_with([$capability]);
        $this->make_teacher_of((int)$own->id, (int)$user->id);
        $this->setUser($user);

        $table = new bookingoptions_wbtable('ownoptioncapabilities');
        $ownmenu = $table->col_action((object)['id' => (int)$own->id, 'status' => 0]);
        $othermenu = $table->col_action((object)['id' => (int)$other->id, 'status' => 0]);

        foreach (self::MENU_ENTRIES as $entry => $needle) {
            $needle = str_replace(['{cmid}', '{optionid}'], [$own->cmid, $own->id], $needle);
            if (in_array($entry, $expected)) {
                $this->assertStringContainsString($needle, $ownmenu, "$capability must open '$entry'.");
            } else {
                $this->assertStringNotContainsString($needle, $ownmenu, "$capability must not open '$entry'.");
            }
            $this->assertStringNotContainsString(
                str_replace((string)$own->id, (string)$other->id, $needle),
                $othermenu,
                "$capability must not open '$entry' for an option the user does not teach."
            );
        }
    }

    /**
     * The forms and gates behind the actions: each capability opens only its own.
     *
     * @covers \mod_booking\local\option_edit_access::can_edit_option
     * @covers \mod_booking\form\option_form::check_access_for_dynamic_submission
     * @covers \mod_booking\form\dynamicoptiondateform::check_access_for_dynamic_submission
     * @covers \mod_booking\form\editteachersforoptiondate_form::check_access_for_dynamic_submission
     */
    public function test_each_capability_opens_only_its_forms(): void {
        $own = $this->create_option();
        $cmid = (int)$own->cmid;

        foreach (ownoption_capabilities::NEW_CAPABILITIES as $capability) {
            $user = $this->user_with([$capability]);
            $this->make_teacher_of((int)$own->id, (int)$user->id);
            $this->setUser($user);

            $isedit = $capability === 'mod/booking:editownoption';
            $isteachers = $capability === 'mod/booking:editteachersownoption';

            $this->assertSame($isedit, option_edit_access::can_edit_option($cmid, (int)$own->id), "$capability: edit form.");
            $this->assertSame(
                $isedit,
                $this->run_form_access_check(
                    option_form::class,
                    ['cmid' => $cmid, 'id' => (int)$own->id, 'optionid' => (int)$own->id]
                ) === null,
                "$capability: saving the edit form."
            );
            $this->assertSame(
                $isedit,
                $this->run_form_access_check(dynamicoptiondateform::class, ['cmid' => $cmid, 'optionid' => (int)$own->id])
                    === null,
                "$capability: session dates form."
            );
            $this->assertSame(
                $isteachers,
                $this->run_form_access_check(editteachersforoptiondate_form::class, ['cmid' => $cmid]) === null,
                "$capability: session teachers form."
            );
        }
    }

    /**
     * The search webservices of the forms are open for the capabilities whose
     * forms use them: edit, duplicate and change teachers.
     *
     * @covers \mod_booking\permissions::has_any_booking_editing_capability
     */
    public function test_search_webservices_are_open_for_the_capabilities_with_forms(): void {
        $this->create_option();
        $withforms = [
            'mod/booking:editownoption',
            'mod/booking:duplicateownoption',
            'mod/booking:editteachersownoption',
        ];

        foreach (ownoption_capabilities::NEW_CAPABILITIES as $capability) {
            $this->user_with([$capability]);
            $this->assertSame(
                in_array($capability, $withforms),
                permissions::has_any_booking_editing_capability(),
                "$capability and the search webservices."
            );
        }
    }

    /**
     * The settings navigation of an option page: "edit" needs editownoption,
     * "manage bookings" needs managebookingsownoption.
     *
     * @covers ::booking_extend_settings_navigation
     */
    public function test_navigation_entries_need_their_own_capability(): void {
        global $PAGE;

        $own = $this->create_option();
        [$course, $cm] = get_course_and_cm_from_cmid((int)$own->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url('/mod/booking/view.php', ['id' => $cm->id, 'optionid' => (int)$own->id]);

        $cases = [
            'mod/booking:editownoption' => ['nav_edit'],
            'mod/booking:managebookingsownoption' => ['nav_manageresponses'],
            'mod/booking:sendmailownoption' => [],
        ];
        foreach ($cases as $capability => $expected) {
            $this->user_with([$capability]);
            $root = navigation_node::create('root');
            booking_extend_settings_navigation($PAGE->settingsnav, $root);
            $keys = array_map('strval', $root->get_children_key_list());

            foreach (['nav_edit', 'nav_manageresponses'] as $key) {
                $this->assertSame(in_array($key, $expected), in_array($key, $keys), "$capability and $key.");
            }
        }
    }
}
