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

use cache_helper;
use context_module;
use local_wunderbyte_table\external\execute_action;
use local_wunderbyte_table\external\load_data;
use mod_booking\output\view;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

/**
 * Tests of the template switcher of the booking options list.
 *
 * The definition of the options table is cached once and shared by everybody who loads the table.
 * The view a user chooses (list, cards, list with image...) and the arrangement of the columns
 * that belongs to it apply to this user only.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\output\view::apply_standard_params_for_bookingtable
 * @covers     \mod_booking\table\bookingoptions_wbtable::apply_template_layout
 * @covers     \mod_booking_observer::template_switched
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class template_choice_test extends booking_advanced_testcase {
    /** @var string The list template. */
    private const LISTTEMPLATE = 'mod_booking/table_list';

    /** @var string The cards template. */
    private const CARDS = 'mod_booking/table_cards';

    /** @var stdClass First user. */
    private stdClass $usera;

    /** @var stdClass Second user. */
    private stdClass $userb;

    /** @var int Course module id of the booking instance. */
    private int $cmid;

    /**
     * Mandatory clean-up after each test.
     */
    protected function tearDown(): void {
        cache_helper::purge_by_event('changesinwunderbytetable');
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Create a booking instance with options, a template switcher and two users who can see the options.
     *
     * @param int $viewparam default view of the instance
     * @param array $switchtemplatesselection views offered by the template switcher
     * @return void
     */
    private function create_instance_with_switcher(int $viewparam, array $switchtemplatesselection): void {
        global $DB, $PAGE;

        $course = self::getDataGenerator()->create_course();
        $this->usera = self::getDataGenerator()->create_user();
        $this->userb = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($this->usera->id, $course->id, 'student');
        self::getDataGenerator()->enrol_user($this->userb->id, $course->id, 'student');

        // The options are created by an administrator.
        $this->setAdminUser();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        foreach (['Option one', 'Option two', 'Option three'] as $text) {
            $plugingenerator->create_option((object)['bookingid' => $booking->id, 'text' => $text]);
        }

        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        booking::add_data_to_json($record, 'viewparam', $viewparam);
        booking::add_data_to_json($record, 'switchtemplates', 1);
        booking::add_data_to_json($record, 'switchtemplatesselection', $switchtemplatesselection);
        $DB->update_record('booking', $record);

        // The settings of the instance are cached.
        $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking->id);
        $this->cmid = (int)$settings->cmid;
        \cache::make('mod_booking', 'cachedbookinginstances')->delete($this->cmid);
        singleton_service::destroy_instance();

        $PAGE->set_context(context_module::instance($this->cmid));
        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $this->cmid]));
    }

    /**
     * Build and render the table of all options as the current user, like the page of the instance does.
     *
     * @return string the hash of the cached table definition
     */
    private function render_page(): string {
        singleton_service::destroy_instance();
        $booking = singleton_service::get_instance_of_booking_by_cmid($this->cmid);
        $view = new view($this->cmid, 'showall', 0, true);

        $table = new bookingoptions_wbtable("cmid_{$this->cmid} allbookingoptionstable");
        $view->wbtable_initialize_layout($table, true, true, true);
        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $booking->context,
            [],
            ['bookingid' => (int)$booking->id],
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            $table
        );
        $table->set_filter_sql($fields, $from, $where, $filter, $params);
        $table->printtable($booking->get_pagination_setting(), true);

        return $table->tablecachehash;
    }

    /**
     * Load the table through the data webservice as the current user, like filtering or paging does.
     *
     * @param string $hash
     * @return array with the keys template, row (the first row) and content (everything the template gets)
     */
    private function load(string $hash): array {
        $result = load_data::execute($hash, 0, '', '', '', 0, 0, '', '');
        $content = json_decode($result['content'], true);
        return [
            'template' => $result['template'],
            'row' => $content['table']['rows'][0] ?? [],
            'content' => $content,
        ];
    }

    /**
     * Choose a view in the template switcher as the current user.
     *
     * @param string $hash
     * @param string $template
     * @param int $viewparam
     * @return void
     */
    private function switch_view(string $hash, string $template, int $viewparam): void {
        $result = execute_action::execute(
            'switchtemplates',
            $hash,
            0,
            json_encode(['selectedValue' => $template . ' ' . $viewparam])
        );
        $this->assertSame(1, $result['success']);
    }

    /**
     * Assert that a loaded table is the list view with the columns arranged for the list.
     *
     * @param array $loaded
     * @return void
     */
    private function assert_list(array $loaded): void {
        $this->assertSame(self::LISTTEMPLATE, $loaded['template']);
        $this->assertArrayHasKey('leftside', $loaded['row']);
        // The card footer only exists in the arrangement for cards.
        $this->assertArrayNotHasKey('cardfooter', $loaded['row']);
    }

    /**
     * Assert that a loaded table is the cards view with the columns arranged for cards.
     *
     * @param array $loaded
     * @return void
     */
    private function assert_cards(array $loaded): void {
        $this->assertSame(self::CARDS, $loaded['template']);
        $this->assertArrayHasKey('cardfooter', $loaded['row']);
    }

    /**
     * A user who switches to the cards gets the cards, everybody else keeps the list of the instance.
     */
    public function test_switching_the_view_only_changes_the_table_of_this_user(): void {
        $this->create_instance_with_switcher(
            MOD_BOOKING_VIEW_PARAM_LIST,
            [MOD_BOOKING_VIEW_PARAM_LIST, MOD_BOOKING_VIEW_PARAM_CARDS]
        );

        $this->setUser($this->userb);
        $hash = $this->render_page();
        $this->assert_list($this->load($hash));

        $this->setUser($this->usera);
        $this->assertSame($hash, $this->render_page(), 'Both users share one cached table definition.');
        $this->switch_view($hash, self::CARDS, MOD_BOOKING_VIEW_PARAM_CARDS);
        $this->assert_cards($this->load($hash));

        // The other user still gets the list, with the columns arranged for the list.
        $this->setUser($this->userb);
        $this->assert_list($this->load($hash));

        // And the first user keeps the cards, also when the page is opened again.
        $this->setUser($this->usera);
        $this->assertSame($hash, $this->render_page());
        $this->assert_cards($this->load($hash));
    }

    /**
     * The table definition that gets cached does not depend on who opens the page first:
     * a user who prefers the cards does not make the cards the view of everybody else.
     */
    public function test_first_visitor_with_a_preference_does_not_define_the_table_of_others(): void {
        $this->create_instance_with_switcher(
            MOD_BOOKING_VIEW_PARAM_LIST,
            [MOD_BOOKING_VIEW_PARAM_LIST, MOD_BOOKING_VIEW_PARAM_CARDS]
        );
        $uniqueid = (new bookingoptions_wbtable("cmid_{$this->cmid} allbookingoptionstable"))->uniqueid;

        $this->setUser($this->usera);
        set_user_preference('wbtable_chosen_template_' . $uniqueid, self::CARDS);
        set_user_preference('wbtable_chosen_template_viewparam_' . $uniqueid, MOD_BOOKING_VIEW_PARAM_CARDS);

        // The user with the cards preference is the first one to open the page.
        $hash = $this->render_page();
        $this->assert_cards($this->load($hash));

        $this->setUser($this->userb);
        $this->assertSame($hash, $this->render_page(), 'Both users share one cached table definition.');
        $this->assert_list($this->load($hash));
    }

    /**
     * Several views use the same template. A user without a choice gets the view of the instance,
     * a user who chose another view of the same template gets that one.
     */
    public function test_views_of_the_same_template_are_kept_apart(): void {
        $this->create_instance_with_switcher(
            MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT,
            [MOD_BOOKING_VIEW_PARAM_LIST, MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT, MOD_BOOKING_VIEW_PARAM_LIST_IMG_RIGHT]
        );

        $this->setUser($this->userb);
        $hash = $this->render_page();
        $this->assertNotEmpty($this->load($hash)['content']['showheaderimageleft'] ?? false);

        $this->setUser($this->usera);
        $this->render_page();
        $this->switch_view($hash, self::LISTTEMPLATE, MOD_BOOKING_VIEW_PARAM_LIST_IMG_RIGHT);
        $loaded = $this->load($hash);
        $this->assertNotEmpty($loaded['content']['showheaderimageright'] ?? false);
        $this->assertEmpty($loaded['content']['showheaderimageleft'] ?? false);

        // The other user keeps the view of the instance.
        $this->setUser($this->userb);
        $loaded = $this->load($hash);
        $this->assertNotEmpty($loaded['content']['showheaderimageleft'] ?? false);
        $this->assertEmpty($loaded['content']['showheaderimageright'] ?? false);
    }
}
