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

use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the site setting booking | turnoffmodals.
 *
 * When the setting is active, the pre booking pages must be rendered inline (inside the booking
 * option row) instead of inside a modal - in EVERY list view and no matter who renders the list.
 * The decisive point is that the view being rendered is not necessarily the view configured in the
 * booking instance: shortcodes ([courselist], shortcodes from external plugins...) bring their own
 * view, so the rendered view has to be passed in explicitly.
 *
 * Only the cards view still uses modals, because inline pre booking pages are not supported there.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_bookit::use_inline_prepages
 * @covers     \mod_booking\booking_bookit::render_bookit_template_data
 * @covers     \mod_booking\table\bookingoptions_wbtable::return_current_viewparam
 */
final class turnoffmodals_test extends booking_advanced_testcase {
    /** @var string Template that opens the pre booking pages in a modal. */
    private const TEMPLATE_MODAL = 'mod_booking/bookingpage/prepagemodal';

    /** @var string Template that shows the pre booking pages inline. */
    private const TEMPLATE_INLINE = 'mod_booking/bookingpage/prepageinline';

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        singleton_service::destroy_instance();
    }

    /**
     * All view types which are able to show the pre booking pages inline.
     *
     * @return array
     */
    public static function list_viewparam_provider(): array {
        return [
            'list' => [MOD_BOOKING_VIEW_PARAM_LIST],
            'list with image on the left' => [MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT],
            'list with image on the right' => [MOD_BOOKING_VIEW_PARAM_LIST_IMG_RIGHT],
            'list with half width image on the left' => [MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT_HALF],
        ];
    }

    /**
     * Without the setting, the pre booking pages always open in a modal.
     *
     * @param int $viewparam the rendered view
     * @return void
     * @dataProvider list_viewparam_provider
     */
    public function test_modal_is_used_when_setting_is_off(int $viewparam): void {
        set_config('turnoffmodals', 0, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage();

        $this->assertFalse(booking_bookit::use_inline_prepages($settings, $viewparam));
        $this->assert_prepage_template(self::TEMPLATE_MODAL, $settings, $userid, $viewparam);
    }

    /**
     * With the setting active, every list view renders the pre booking pages inline.
     *
     * @param int $viewparam the rendered view
     * @return void
     * @dataProvider list_viewparam_provider
     */
    public function test_inline_is_used_in_every_list_view(int $viewparam): void {
        set_config('turnoffmodals', 1, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage();

        $this->assertTrue(booking_bookit::use_inline_prepages($settings, $viewparam));
        $this->assert_prepage_template(self::TEMPLATE_INLINE, $settings, $userid, $viewparam);
    }

    /**
     * The cards view does not support inline pre booking pages yet and keeps using modals.
     *
     * @return void
     */
    public function test_modal_is_kept_for_cards_view(): void {
        set_config('turnoffmodals', 1, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage();

        $this->assertFalse(booking_bookit::use_inline_prepages($settings, MOD_BOOKING_VIEW_PARAM_CARDS));
        $this->assert_prepage_template(self::TEMPLATE_MODAL, $settings, $userid, MOD_BOOKING_VIEW_PARAM_CARDS);
    }

    /**
     * Regression guard for the shortcode case: a shortcode can render a booking instance that is
     * configured to use the cards view as a list ([courselist type=list], shortcodes from external plugins, ...).
     * What counts is the view that is rendered, not the instance setting.
     *
     * @return void
     */
    public function test_inline_is_used_for_a_list_shortcode_of_a_cards_instance(): void {
        set_config('turnoffmodals', 1, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage(MOD_BOOKING_VIEW_PARAM_CARDS);

        $this->assertTrue(booking_bookit::use_inline_prepages($settings, MOD_BOOKING_VIEW_PARAM_LIST));
        $this->assert_prepage_template(self::TEMPLATE_INLINE, $settings, $userid, MOD_BOOKING_VIEW_PARAM_LIST);
    }

    /**
     * Regression guard for instances that merely OFFER the cards view via the template switcher.
     * As long as a list view is actually rendered, the pre booking pages have to be inline.
     *
     * @return void
     */
    public function test_inline_is_used_when_cards_are_only_offered_by_the_template_switcher(): void {
        set_config('turnoffmodals', 1, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage(
            MOD_BOOKING_VIEW_PARAM_LIST,
            [MOD_BOOKING_VIEW_PARAM_LIST, MOD_BOOKING_VIEW_PARAM_CARDS]
        );

        $this->assertTrue(booking_bookit::use_inline_prepages($settings, MOD_BOOKING_VIEW_PARAM_LIST));
        $this->assert_prepage_template(self::TEMPLATE_INLINE, $settings, $userid, MOD_BOOKING_VIEW_PARAM_LIST);
    }

    /**
     * When the rendered view is unknown (e.g. the bookit webservice re-rendering the button), we
     * fall back to the configuration of the booking instance: a list instance uses inline pages.
     *
     * @return void
     */
    public function test_fallback_uses_inline_for_a_list_instance(): void {
        set_config('turnoffmodals', 1, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage(MOD_BOOKING_VIEW_PARAM_LIST);

        $this->assertTrue(booking_bookit::use_inline_prepages($settings));
        $this->assert_prepage_template(self::TEMPLATE_INLINE, $settings, $userid, null);
    }

    /**
     * Fallback for an unknown rendered view: a cards instance keeps its modals.
     *
     * @return void
     */
    public function test_fallback_uses_modal_for_a_cards_instance(): void {
        set_config('turnoffmodals', 1, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage(MOD_BOOKING_VIEW_PARAM_CARDS);

        $this->assertFalse(booking_bookit::use_inline_prepages($settings));
        $this->assert_prepage_template(self::TEMPLATE_MODAL, $settings, $userid, null);
    }

    /**
     * Fallback for an unknown rendered view: as soon as the template switcher offers the cards
     * view we cannot know whether we are in it, so we stay conservative and keep the modals.
     *
     * @return void
     */
    public function test_fallback_uses_modal_when_the_switcher_offers_cards(): void {
        set_config('turnoffmodals', 1, 'booking');
        [$settings, $userid] = $this->create_option_with_prepage(
            MOD_BOOKING_VIEW_PARAM_LIST,
            [MOD_BOOKING_VIEW_PARAM_LIST, MOD_BOOKING_VIEW_PARAM_CARDS]
        );

        $this->assertFalse(booking_bookit::use_inline_prepages($settings));
        $this->assert_prepage_template(self::TEMPLATE_MODAL, $settings, $userid, null);
    }

    /**
     * The booking options table reports the view it renders. Shortcode tables that never call
     * view::apply_standard_params_for_bookingtable therefore default to the
     * list view, which is exactly what their list templates render.
     *
     * @return void
     */
    public function test_table_reports_the_rendered_view(): void {
        $table = new bookingoptions_wbtable('turnoffmodals_test_table');

        // The default is declared as a literal, because the table class is also loaded where the
        // MOD_BOOKING_VIEW_PARAM_* constants are not defined (e.g. the wunderbyte table load_data
        // webservice used for search and filtering). This guards the literal against drifting.
        $this->assertSame(MOD_BOOKING_VIEW_PARAM_LIST, $table->viewparam);
        $this->assertSame(MOD_BOOKING_VIEW_PARAM_LIST, $table->return_current_viewparam());

        $table->viewparam = MOD_BOOKING_VIEW_PARAM_CARDS;
        $this->assertSame(MOD_BOOKING_VIEW_PARAM_CARDS, $table->return_current_viewparam());
    }

    /**
     * With an active template switcher the user's runtime choice wins over the view the table was
     * built with - otherwise switching from cards to list would keep the modals (and vice versa).
     *
     * @return void
     */
    public function test_table_prefers_the_switched_template(): void {
        $table = new bookingoptions_wbtable('turnoffmodals_switch_table');
        $table->viewparam = MOD_BOOKING_VIEW_PARAM_CARDS;
        $table->switchtemplates = ['templates' => [['template' => 'mod_booking/table_list']]];

        set_user_preference(
            'wbtable_chosen_template_viewparam_' . $table->uniqueid,
            MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT
        );

        $this->assertSame(MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT, $table->return_current_viewparam());
    }

    /**
     * Renders the bookit button and asserts which pre booking page container is used.
     *
     * @param string $expected the expected template name
     * @param booking_option_settings $settings booking option settings
     * @param int $userid user the button is rendered for
     * @param int|null $viewparam the rendered view, null if unknown
     * @return void
     */
    private function assert_prepage_template(
        string $expected,
        booking_option_settings $settings,
        int $userid,
        ?int $viewparam
    ): void {

        [$templates] = booking_bookit::render_bookit_template_data($settings, $userid, true, '', $viewparam);

        $notexpected = $expected === self::TEMPLATE_INLINE ? self::TEMPLATE_MODAL : self::TEMPLATE_INLINE;

        $this->assertContains($expected, $templates);
        $this->assertNotContains($notexpected, $templates);
    }

    /**
     * Creates a booking option that has at least one pre booking page.
     *
     * A booking policy is the cheapest way to get one: it is rendered as a prebook page, so the
     * bookit button is wrapped into a modal (or an inline area).
     *
     * @param int|null $viewparam view to store in the booking instance, null to leave it unset
     * @param array $switchtemplatesselection views offered by the template switcher
     * @return array [booking_option_settings, int userid]
     */
    private function create_option_with_prepage(
        ?int $viewparam = null,
        array $switchtemplatesselection = []
    ): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        $user = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance([
            'course' => $course->id,
            'bookingpolicy' => 'Please accept our booking policy.',
        ]);

        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option ' . uniqid('', true),
            'course' => $course->id,
            'maxanswers' => 5,
        ]);

        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);

        if ($viewparam !== null) {
            booking::add_data_to_json($record, 'viewparam', $viewparam);
        }
        if (!empty($switchtemplatesselection)) {
            booking::add_data_to_json($record, 'switchtemplates', 1);
            booking::add_data_to_json($record, 'switchtemplatesselection', $switchtemplatesselection);
        }
        $DB->update_record('booking', $record);

        $this->purge_booking_caches($booking->id);
        $this->setUser($user);

        return [singleton_service::get_instance_of_booking_option_settings($option->id), (int)$user->id];
    }

    /**
     * The booking instance settings are cached aggressively, so changes to the instance record
     * (viewparam, template switcher) only take effect after the caches were purged.
     *
     * @param int $bookingid booking instance id
     * @return void
     */
    private function purge_booking_caches(int $bookingid): void {
        $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        \cache::make('mod_booking', 'cachedbookinginstances')->delete((int)$settings->cmid);
        singleton_service::destroy_instance();
    }
}
