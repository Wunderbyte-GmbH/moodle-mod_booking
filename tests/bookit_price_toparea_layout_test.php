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
 * Layout of availability condition warnings in the bookit button templates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;

/**
 * Warnings of availability conditions (the "top" area, e.g. "maximum number of bookings
 * reached" or "overlaps with your booked options") have to be rendered ABOVE the booking
 * button / the add-to-cart button, never next to it.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookit_price_toparea_layout_test extends advanced_testcase {
    /**
     * Data of a warning as bo_info::render_button() / booking_bookit put it into the "top" area.
     *
     * @return array
     */
    private function toparea(): array {
        return [
            'label' => 'Overlap warning',
            'class' => 'alert alert-warning w-100 text-center',
            'role' => 'alert',
        ];
    }

    /**
     * The price button wrapper stacks its areas vertically: warning, price / cart button, price label.
     *
     * Before, the wrapper was a plain flex row, so the warning ended up left of the price and the
     * cart button in every view and on the option detail page.
     *
     * @covers \mod_booking\output\renderer::render_bookit_price
     *
     * @return void
     */
    public function test_price_wrapper_stacks_warning_above_price(): void {
        global $PAGE;

        $this->resetAfterTest();

        $output = $PAGE->get_renderer('mod_booking');
        $html = $output->render_from_template('mod_booking/bookit_price', [
            'itemid' => 1,
            'area' => 'option',
            'userid' => 2,
            'price' => '10.00',
            'currency' => 'EUR',
            'fullwidth' => true,
            'shoppingcartisavailable' => false,
            'top' => $this->toparea(),
            'sub' => ['label' => 'price label', 'class' => 'text-center', 'role' => ''],
        ]);

        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*\bd-flex\b[^"]*\bflex-column\b[^"]*\bbooking-button-area\b[^"]*"/',
            $html,
            'The price button wrapper must be a vertical flex column, otherwise the warning sits next to the price.'
        );

        $toppos = strpos($html, 'booking-button-toparea');
        $pricepos = strpos($html, 'pricecontainer');
        $subpos = strpos($html, 'booking-button-subarea');
        $this->assertNotFalse($toppos, 'The warning area is missing.');
        $this->assertNotFalse($pricepos, 'The price container is missing.');
        $this->assertLessThan($pricepos, $toppos, 'The warning has to come before the price.');
        $this->assertLessThan($subpos, $pricepos, 'The price label has to follow the price.');
        $this->assertStringContainsString('Overlap warning', $html);
    }

    /**
     * The plain bookit button keeps the warning above the main button as well.
     *
     * @covers \mod_booking\output\renderer::render_bookit_button
     *
     * @return void
     */
    public function test_bookit_button_renders_warning_above_main_button(): void {
        global $PAGE;

        $this->resetAfterTest();

        $output = $PAGE->get_renderer('mod_booking');
        $html = $output->render_from_template('mod_booking/bookit_button', [
            'itemid' => 1,
            'area' => 'option',
            'userid' => 2,
            'nojs' => true,
            'top' => $this->toparea(),
            'main' => [
                'label' => 'Book now',
                'class' => 'btn btn-primary w-100 text-center',
                'role' => 'button',
                'isbutton' => true,
            ],
        ]);

        $toppos = strpos($html, 'booking-button-toparea');
        $mainpos = strpos($html, 'booking-button-mainarea');
        $this->assertNotFalse($toppos, 'The warning area is missing.');
        $this->assertNotFalse($mainpos, 'The main button is missing.');
        $this->assertLessThan($mainpos, $toppos, 'The warning has to come before the main button.');
        // Plugin css loaded in every theme (e.g. local_urise) sets display:flex on the area. Only an
        // explicit vertical direction keeps the warning above the button in that case.
        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*\bbooking-button-area\b[^"]*\bd-flex\b[^"]*\bflex-column\b[^"]*"/',
            $html,
            'The booking-button-area must be an explicit vertical flex column.'
        );
    }
}
