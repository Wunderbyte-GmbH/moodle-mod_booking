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

namespace mod_booking\local\wizard\booking;

use bookingextension_agent\local\wizard\interfaces\skill_input_normalizer_interface;
use mod_booking\local\wizard\booking\support\slot_booking_normalizer;

/**
 * mod_booking-owned adapter for task input normalization.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_skill_input_normalizer implements skill_input_normalizer_interface {
    /** @var slot_booking_normalizer */
    private slot_booking_normalizer $normalizer;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->normalizer = new slot_booking_normalizer();
    }

    /**
     * Normalize provider-owned task input.
     *
     * @param string $taskname
     * @param array $input
     * @return array
     */
    public function normalize(string $taskname, array $input): array {
        return $this->normalizer->normalize($taskname, $input);
    }
}
