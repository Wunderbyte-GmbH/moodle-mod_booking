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

use mod_booking\bo_availability\bo_info;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

/**
 * A priced option, the price category fallback turned off and a user whose price category matches
 * no configured category: price::get_price() resolves no price at all. The user must then see a
 * plain, non clickable message. Before, the bare "no price" text of bookit_price was still wrapped
 * into the prepage modal trigger whenever other conditions (booking policy, confirmation) added
 * pre booking pages, so clicking the message opened the booking modal.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\bo_availability\conditions\priceisset
 * @covers     \mod_booking\booking_bookit::render_bookit_template_data
 */
final class priceisset_missing_price_no_modal_test extends booking_advanced_testcase {
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
     * Creates the priced option with a booking policy (which, together with the confirmation page,
     * forces a prepage modal for bookable users) and the price category "somecat".
     *
     * @return array [course, optionid]
     */
    private function create_priced_option_with_policy(): array {
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        // Fallback turned off: a non-matching profile value resolves to NO price at all.
        set_config('pricecategoryfallback', 2, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'somecat',
            'identifier' => 'somecat',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ]);

        $booking = $plugingenerator->create_instance([
            'course' => $course->id,
            'name' => 'Missing price category',
            'eventtype' => 'Test',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'bookingpolicy' => 'Please accept our booking policy.',
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'priced-option-without-fallback';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return [$course, (int) $option->id];
    }

    /**
     * Creates and enrols a student with the given price category profile value.
     *
     * @param stdClass $course
     * @param string $pricecat
     * @return stdClass
     */
    private function create_student(stdClass $course, string $pricecat): stdClass {
        $user = $this->getDataGenerator()->create_user(['profile_field_pricecat' => $pricecat]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        return $user;
    }

    /**
     * A user without a resolvable price gets a plain alert: no prepage modal, nothing clickable.
     *
     * @return void
     */
    public function test_missing_price_renders_plain_alert_without_modal(): void {
        [$course, $optionid] = $this->create_priced_option_with_policy();
        $nomatch = $this->create_student($course, 'nomatch');

        $this->setUser($nomatch);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Precondition: the price lookup really resolves nothing for this user.
        $this->assertEmpty(price::get_price('option', $optionid, $nomatch));

        // The option is still blocked (the booking policy is reported first, the price right after it)...
        $boinfo = new bo_info($settings);
        [, $isavailable] = $boinfo->is_available($optionid, $nomatch->id);
        $this->assertFalse($isavailable);

        // ... but announces itself as "just my alert", which suppresses the prepage modal.
        $results = bo_info::get_condition_results($optionid, $nomatch->id);
        $this->assertArrayHasKey(MOD_BOOKING_BO_COND_PRICEISSET, $results);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_JUSTMYALERT, $results[MOD_BOOKING_BO_COND_PRICEISSET]['button']);

        [$templates, $datas] = booking_bookit::render_bookit_template_data($settings, $nomatch->id);
        $this->assertNotContains(self::TEMPLATE_MODAL, $templates);
        $this->assertNotContains(self::TEMPLATE_INLINE, $templates);
        $this->assertSame(['mod_booking/bookit_button'], $templates);

        $data = $datas[0]->data;
        $this->assertSame('alert', $data['main']['role']);
        $this->assertFalse($data['main']['isbutton']);
        $this->assertTrue($data['nojs']);
        $this->assertSame(get_string('nopriceisset', 'mod_booking', 'nomatch'), $data['main']['label']);

        $html = booking_bookit::render_bookit_button($settings, $nomatch->id);
        $this->assertStringNotContainsString('data-bs-toggle="modal"', $html);
        $this->assertStringNotContainsString('data-bs-toggle="collapse"', $html);
        $this->assertStringNotContainsString('sbPrePageModal_', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringContainsString('booking-button-mainarea', $html);
        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString(get_string('nopriceisset', 'mod_booking', 'nomatch'), $html);
    }

    /**
     * A user whose price category matches keeps the price button and the booking policy modal.
     *
     * @return void
     */
    public function test_matching_price_category_still_gets_price_button_and_modal(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is required for the price button.');
        }

        [$course, $optionid] = $this->create_priced_option_with_policy();
        $match = $this->create_student($course, 'somecat');

        $this->setUser($match);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $price = price::get_price('option', $optionid, $match);
        $this->assertEquals(50, (float) $price['price']);

        $results = bo_info::get_condition_results($optionid, $match->id);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_MYBUTTON, $results[MOD_BOOKING_BO_COND_PRICEISSET]['button']);

        [$templates] = booking_bookit::render_bookit_template_data($settings, $match->id);
        $this->assertContains(self::TEMPLATE_MODAL, $templates);

        $html = booking_bookit::render_bookit_button($settings, $match->id);
        $this->assertStringContainsString('data-bs-toggle="modal"', $html);
        $this->assertStringContainsString('50.00', $html);
        $this->assertStringNotContainsString(get_string('nopriceisset', 'mod_booking', 'somecat'), $html);
    }
}
