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

use mod_booking\tests\booking_advanced_testcase;
use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use mod_booking\output\view;
use mod_booking\table\bookingoptions_wbtable;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for customfields configured via the instance setting customfieldsforview
 * shown for each booking option in the options overview (view.php).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_customfields_on_view_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Ensure customfields selected in the instance setting customfieldsforview
     * are rendered with their configured icon in the booking options overview.
     *
     * @covers \mod_booking\output\view::get_customfieldsforview_info_array
     * @covers \mod_booking\output\view::prepare_customfields
     */
    public function test_customfieldsforview_are_rendered_in_view_table(): void {
        global $PAGE;

        $this->setAdminUser();

        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);

        $this->create_booking_customfields();
        // Configure a Font Awesome icon for one of the custom fields (but not for the other).
        set_config('customfieldicon_spt1', 'fa-futbol-o', 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking0',
            'bookingmanager' => $teacher->username,
            'eventtype' => 'Webinar',
            // Show the customfields spt1 and lng1 for each option in the overview.
            'customfieldsforview' => ['spt1', 'lng1'],
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Option01-t',
            'course' => $course->id,
            'description' => 'Option 1 description',
            'importing' => 1,
            'maxanswers' => 3,
            'spt1' => 'tenis',
            'lng1' => 'french',
        ]);

        singleton_service::destroy_instance();
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($booking->cmid);

        // The setting was stored in the JSON column as a plain list of shortnames
        // and loaded into booking_settings.
        $storedfields = (array)$bookingsettings->customfieldsforview;
        $this->assertEqualsCanonicalizing(['spt1', 'lng1'], $storedfields);
        $this->assertTrue(array_is_list($storedfields));

        // The info array contains both fields, with the configured icon or the default icon.
        $cfinfoarray = view::get_customfieldsforview_info_array($bookingsettings);
        $this->assertEqualsCanonicalizing(['spt1', 'lng1'], array_keys($cfinfoarray));
        $this->assertSame('fa fa-fw fa-futbol-o', $cfinfoarray['spt1']['iconclass']);
        // No icon configured for lng1, so the default icon is used.
        $this->assertSame('fa fa-fw fa-puzzle-piece', $cfinfoarray['lng1']['iconclass']);
        // The region stays empty, it is resolved depending on the rendered template.
        $this->assertNull($cfinfoarray['spt1']['region']);

        // Render the options overview table (list view is the default).
        $PAGE->set_url('/mod/booking/tests/booking_options_customfields/booking_customfields_on_view_test.php');
        $viewoutput = new view($booking->cmid, 'showall');
        $html = $viewoutput->get_rendered_all_options_table();

        $this->assertStringContainsString('wunderbyte_table_container', $html);
        // Both customfield values are rendered for the booking option.
        $this->assertStringContainsString('tenis', $html);
        $this->assertStringContainsString('french', $html);
        // The configured icon is rendered, for lng1 the default icon is used.
        $this->assertStringContainsString('fa fa-fw fa-futbol-o', $html);
        $this->assertStringContainsString('fa fa-fw fa-puzzle-piece', $html);

        // In the list view, the customfields are placed in the footer region.
        $this->assertSame(1, preg_match('/data-encodedtable=["\']?([^"\'>\s]+)/i', $html, $matches));
        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $this->assertArrayHasKey('spt1', $table->subcolumns['footer']);
        $this->assertArrayHasKey('lng1', $table->subcolumns['footer']);
        // The customfields are styled like institution (gray) and placed right next to institution,
        // before the dates.
        $this->assertStringContainsString('text-gray', $table->subcolumns['footer']['spt1']['columnclass']);
        $footerkeys = array_keys($table->subcolumns['footer']);
        $posshowdates = array_search('showdates', $footerkeys);
        if ($posshowdates !== false) {
            $this->assertLessThan($posshowdates, array_search('spt1', $footerkeys));
            $this->assertLessThan($posshowdates, array_search('lng1', $footerkeys));
        }
        $posinstitution = array_search('institution', $footerkeys);
        if ($posinstitution !== false) {
            $this->assertSame($posinstitution + 1, array_search('spt1', $footerkeys));
            $this->assertSame($posinstitution + 2, array_search('lng1', $footerkeys));
        }
    }

    /**
     * Ensure that in the cards view each customfield is rendered on its own line in the card list.
     *
     * @covers \mod_booking\output\view::prepare_customfields
     */
    public function test_customfieldsforview_are_rendered_in_cardlist_of_cards_view(): void {
        global $DB, $PAGE;

        $this->setAdminUser();

        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);

        $this->create_booking_customfields();
        set_config('customfieldicon_spt1', 'fa-futbol-o', 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking0',
            'bookingmanager' => $teacher->username,
            'eventtype' => 'Webinar',
            'customfieldsforview' => ['spt1', 'lng1'],
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Option01-t',
            'course' => $course->id,
            'description' => 'Option 1 description',
            'importing' => 1,
            'maxanswers' => 3,
            'spt1' => 'tenis',
            'lng1' => 'french',
        ]);

        // Switch the instance to the cards view.
        $jsonobject = json_decode($DB->get_field('booking', 'json', ['id' => $booking->id]) ?: '{}');
        $jsonobject->viewparam = MOD_BOOKING_VIEW_PARAM_CARDS;
        $DB->set_field('booking', 'json', json_encode($jsonobject), ['id' => $booking->id]);
        // The booking settings are cached, so we need to invalidate cache and singletons.
        \cache::make('mod_booking', 'cachedbookinginstances')->delete($booking->cmid);
        singleton_service::destroy_instance();

        $PAGE->set_url('/mod/booking/tests/booking_options_customfields/booking_customfields_on_view_test.php');
        $viewoutput = new view($booking->cmid, 'showall');
        $html = $viewoutput->get_rendered_all_options_table();

        // The cards template renders the card list as ul.infolist with one li per entry.
        $this->assertStringContainsString('infolist', $html);
        $this->assertStringContainsString('tenis', $html);
        $this->assertStringContainsString('french', $html);
        $this->assertStringContainsString('fa fa-fw fa-futbol-o', $html);
        $this->assertStringContainsString('fa fa-fw fa-puzzle-piece', $html);
        // Each customfield is rendered as its own list item (icon and value within one li).
        $this->assertMatchesRegularExpression(
            '/<li[^>]*>(?:(?!<\/li>).)*fa-futbol-o(?:(?!<\/li>).)*tenis(?:(?!<\/li>).)*<\/li>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<li[^>]*>(?:(?!<\/li>).)*fa-puzzle-piece(?:(?!<\/li>).)*french(?:(?!<\/li>).)*<\/li>/s',
            $html
        );

        // The customfields are placed in the cardlist region, so each one is rendered on its own line.
        $table = new bookingoptions_wbtable("cmid_{$booking->cmid} testcardstable");
        $viewoutput->wbtable_initialize_layout($table, true, true, true);
        $this->assertArrayHasKey('spt1', $table->subcolumns['cardlist']);
        $this->assertArrayHasKey('lng1', $table->subcolumns['cardlist']);
        // Icons carry the same gray as the other card list entries (e.g. institution).
        $this->assertSame('fa fa-fw fa-futbol-o text-gray', $table->subcolumns['cardlist']['spt1']['columniclassbefore']);
        $this->assertSame('fa fa-fw fa-puzzle-piece text-gray', $table->subcolumns['cardlist']['lng1']['columniclassbefore']);
        $this->assertStringContainsString('text-gray', $table->subcolumns['cardlist']['spt1']['columnclass']);
        // The customfields must not end up in the cardbody region.
        $this->assertArrayNotHasKey('spt1', $table->subcolumns['cardbody']);
        // The customfields are placed right above the dates.
        $cardlistkeys = array_keys($table->subcolumns['cardlist']);
        $posshowdates = array_search('showdates', $cardlistkeys);
        if ($posshowdates !== false) {
            $this->assertLessThan($posshowdates, array_search('spt1', $cardlistkeys));
            $this->assertLessThan($posshowdates, array_search('lng1', $cardlistkeys));
        }
    }

    /**
     * Ensure explicit regions (e.g. from the includecustomfields shortcode argument) are kept
     * as given - they may also come from templates of other plugins - while customfields
     * without a region go to the default region with the standard styling.
     *
     * @covers \mod_booking\output\view::prepare_customfields
     */
    public function test_prepare_customfields_keeps_explicit_regions(): void {
        global $PAGE;

        $this->setAdminUser();
        $PAGE->set_context(context_system::instance());
        $PAGE->set_url('/mod/booking/tests/booking_options_customfields/booking_customfields_on_view_test.php');

        $cfinfoarray = [
            'explicitcf' => [
                'colname' => 'explicitcf',
                'region' => 'myownregion',
                'iconclass' => 'fa fa-fw fa-wrench',
                'class' => null,
            ],
            'noregioncf' => [
                'colname' => 'noregioncf',
                'region' => null,
                'iconclass' => 'fa fa-fw fa-puzzle-piece',
                'class' => null,
            ],
        ];

        $table = new bookingoptions_wbtable('testexplicitregions');
        $table->set_customfields_info_array($cfinfoarray);
        view::prepare_customfields($table, 'footer', 'defaultclass', 'defaulticon');

        // Explicit regions are kept as given (even regions of other templates), without default styling.
        $this->assertArrayHasKey('explicitcf', $table->subcolumns['myownregion']);
        $this->assertArrayNotHasKey('columnclass', $table->subcolumns['myownregion']['explicitcf']);
        $this->assertSame('fa fa-fw fa-wrench', $table->subcolumns['myownregion']['explicitcf']['columniclassbefore']);

        // Customfields without a region go to the default region with the standard styling.
        $this->assertArrayHasKey('noregioncf', $table->subcolumns['footer']);
        $this->assertSame('defaultclass', $table->subcolumns['footer']['noregioncf']['columnclass']);
        $this->assertSame('fa fa-fw fa-puzzle-piece defaulticon', $table->subcolumns['footer']['noregioncf']['columniclassbefore']);
    }

    /**
     * Ensure the includecustomfields shortcode parser keeps the region empty if none is given,
     * so those customfields end up in the standard region of the rendered template.
     *
     * @covers \mod_booking\shortcodes_handler::get_includecustomfields_info_array
     */
    public function test_includecustomfields_parser_region_default(): void {

        $this->setAdminUser();
        $this->create_booking_customfields();

        $cfinfoarray = shortcodes_handler::get_includecustomfields_info_array(
            ['includecustomfields' => 'spt1,lng1|cardbody|far|fa-wrench']
        );

        // Without a region part, the region stays empty (standard region of the rendered template).
        $this->assertNull($cfinfoarray['spt1']['region']);
        $this->assertNull($cfinfoarray['spt1']['iconclass']);
        // Explicitly given regions and icons are kept unchanged.
        $this->assertSame('cardbody', $cfinfoarray['lng1']['region']);
        $this->assertSame('far fa-wrench', $cfinfoarray['lng1']['iconclass']);
    }

    /**
     * Ensure that deleted customfields and instances without the setting do not break the view.
     *
     * @covers \mod_booking\output\view::get_customfieldsforview_info_array
     */
    public function test_customfieldsforview_ignores_invalid_fields(): void {

        $this->setAdminUser();

        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);

        $this->create_booking_customfields();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking0',
            'bookingmanager' => $teacher->username,
            'eventtype' => 'Webinar',
        ]);

        singleton_service::destroy_instance();
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($booking->cmid);

        // No customfieldsforview setting stored: empty info array.
        $this->assertSame([], view::get_customfieldsforview_info_array($bookingsettings));

        // A stored shortname of a meanwhile deleted customfield is ignored.
        $bookingsettings->customfieldsforview = ['deletedfield', 'spt1'];
        $cfinfoarray = view::get_customfieldsforview_info_array($bookingsettings);
        $this->assertSame(['spt1'], array_keys($cfinfoarray));

        // The legacy format (shortname => fullname pairs) is still supported.
        $bookingsettings->customfieldsforview = ['deletedfield' => 'Deleted field', 'spt1' => 'Sport1'];
        $cfinfoarray = view::get_customfieldsforview_info_array($bookingsettings);
        $this->assertSame(['spt1'], array_keys($cfinfoarray));
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
            'name' => 'Language1',
            'shortname' => 'lng1',
            'type' => 'text',
            'configdata' => '{"required":"0","uniquevalues":"0","defaultvalue":"",'
                . '"displaysize":50,"maxlength":1333,"ispassword":"0","link":"",'
                . '"locked":"0","visibility":"2"}',
        ])->save();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        singleton_service::destroy_instance();
    }
}
