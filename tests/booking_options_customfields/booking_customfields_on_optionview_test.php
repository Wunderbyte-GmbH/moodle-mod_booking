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
use context_system;
use mod_booking_generator;
use mod_booking\output\bookingoption_description;

/**
 * Tests for configured customfields shown on option view.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_customfields_on_optionview_test extends advanced_testcase {
    /**
     * Ensure configured customfields are rendered in the optionview output.
     *
     * @covers \mod_booking\output\bookingoption_description::export_for_template
     */
    public function test_optionviewcustomfields_are_rendered_for_supported_field_types(): void {
        global $PAGE;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);
        $student1 = $this->getDataGenerator()->create_user(['username' => 'student1']);
        $student3 = $this->getDataGenerator()->create_user(['username' => 'student3']);

        $this->create_booking_customfields();
        set_config('optionviewcustomfields', '0,spt1,dtime,dtext,ddownmenu,dnumber,dynamicuser', 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking0',
            'bookingmanager' => $teacher->username,
            'eventtype' => 'Webinar',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Option01-t',
            'course' => $course->id,
            'description' => 'tenis-{dnumber}-{spt1}',
            'importing' => 1,
            'maxanswers' => 3,
            'spt1' => 'tenis',
            'dtime' => 2346937200,
            'ddownmenu' => 1,
            'dynamicuser' => $student1->username . ',' . $student3->username,
        ]);

        $PAGE->set_url('/mod/booking/tests/output/booking_customfields_on_optionview_test.php');
        $data = (new bookingoption_description($option->id))->export_for_template($PAGE->get_renderer('mod_booking'));

        $customfieldshtml = $data['optionviewcustomfields'];
        $descriptiohtml = $data['description'];

        $this->assertStringContainsString('optionview-customfield-spt1', $customfieldshtml);
        $this->assertStringContainsString('tenis', $customfieldshtml);

        $this->assertStringContainsString('optionview-customfield-dtime', $customfieldshtml);
        $this->assertMatchesRegularExpression('/optionview-customfield-dtime.*2044/s', $customfieldshtml);

        $this->assertStringContainsString('optionview-customfield-dtext', $customfieldshtml);
        $this->assertMatchesRegularExpression('/optionview-customfield-dtext.*text.*area.*test/s', $customfieldshtml);

        $this->assertStringContainsString('optionview-customfield-ddownmenu', $customfieldshtml);
        $this->assertStringContainsString('Option1', $customfieldshtml);

        $this->assertStringContainsString('optionview-customfield-dnumber', $customfieldshtml);
        $this->assertStringContainsString('5', $customfieldshtml);

        $this->assertStringContainsString('optionview-customfield-dynamicuser', $customfieldshtml);
        $this->assertStringContainsString('student1, student3', $customfieldshtml);

        // Verify that the customfield placeholders in description.
        $this->assertStringContainsString('text_to_html', $descriptiohtml);
        $this->assertStringContainsString('tenis-5-tenis', $descriptiohtml);
    }

    /**
     * Create the custom fields needed by this test.
     *
     * @return void
     */
    private function create_booking_customfields(): void {
        $categorydata = [
            'name' => 'OtherFields',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ];
        $category = $this->getDataGenerator()->create_custom_field_category($categorydata);
        $category->save();

        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Sport1',
            'shortname' => 'spt1',
            'type' => 'text',
            'configdata' => '{"required":"0","uniquevalues":"0","defaultvalue":"defsport",'
                . '"displaysize":50,"maxlength":1333,"ispassword":"0","link":"",'
                . '"locked":"0","visibility":"2"}',
        ])->save();

        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'dtime',
            'shortname' => 'dtime',
            'type' => 'date',
            'configdata' => '{"required":"0","uniquevalues":"0","includetime":"1",'
                . '"mindate":0,"maxdate":0,"locked":"0","visibility":"2"}',
        ])->save();

        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'dtext',
            'shortname' => 'dtext',
            'type' => 'textarea',
            'configdata' => '{"required":"0","uniquevalues":"0","locked":"0",'
                . '"visibility":"2","defaultvalue":"<p><strong>text<\\/strong> '
                . '<em>area <a href=\\"http:\\/\\/google.com\\">test<\\/a><\\/em><\\/p>",'
                . '"defaultvalueformat":"1"}',
        ])->save();

        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'ddownmenu',
            'shortname' => 'ddownmenu',
            'type' => 'select',
            'configdata' => '{"required":"0","uniquevalues":"0","locked":"0",'
                . '"visibility":"2","defaultvalue":"1","options":"Option1\\nOption2\\nOption3"}',
        ])->save();

        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'dnumber',
            'shortname' => 'dnumber',
            'type' => 'number',
            'configdata' => '{"required":"0","uniquevalues":"0","locked":"0",'
                . '"visibility":"2","defaultvalue":"5","rangefrom":"0","rangeto":"10"}',
        ])->save();

        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'DynamicU',
            'shortname' => 'dynamicuser',
            'type' => 'dynamicformat',
            'configdata' => '{"required":"0","uniquevalues":"0",'
                . '"dynamicsql":"SELECT username as id, username as data FROM {user}",'
                . '"autocomplete":"0","defaultvalue":"1","multiselect":"1"}',
        ])->save();

        // Validate that the same category and same field will not affect booking custom fields.
        $categorydata = [
            'name' => 'OtherFields',
            'component' => 'core_course',
            'area' => 'course',
            'itemid' => 2,
            'contextid' => context_system::instance()->id,
        ];
        $category = $this->getDataGenerator()->create_custom_field_category($categorydata);
        $category->save();
        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Sport1',
            'shortname' => 'spt1',
            'type' => 'text',
            'configdata' => '{"required":"0","uniquevalues":"0","defaultvalue":"defsport",'
                . '"displaysize":50,"maxlength":1333,"ispassword":"0","link":"",'
                . '"locked":"0","visibility":"2"}',
        ])->save();
    }
}
