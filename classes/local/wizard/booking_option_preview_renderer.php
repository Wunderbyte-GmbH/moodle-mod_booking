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
 * Server-side preview renderer for booking options.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wizard;

use mod_booking\output\view;
use mod_booking\singleton_service;

/**
 * Booking option preview renderer.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option_preview_renderer {
    /**
     * Render preview HTML for booking option ids.
     *
     * @param array $payload      The preview payload containing optionids.
     * @param int   $contextid    Moodle context id.
     * @param int   $userid       Current user id.
     * @return string             Rendered HTML.
     */
    public function render(array $payload, int $contextid, int $userid): string {
        $optionids = $payload['optionids'] ?? [];
        if (!is_array($optionids)) {
            $optionids = [];
        }

        if (isset($payload['optionid'])) {
            $optionids[] = $payload['optionid'];
        }

        $optionids = array_values(array_unique(array_filter(
            array_map('intval', $optionids),
            static fn(int $id): bool => $id > 0
        )));

        if (empty($optionids)) {
            return '';
        }

        $htmlparts = [];
        foreach ($optionids as $id) {
            // Render each option in its OWN booking instance. The cmid must come from the option
            // itself (via its settings), not from the agent's WS context — an option may belong to
            // a different booking instance than the one the agent was invoked in, in which case the
            // wrong cmid yields an empty "No records found" table.
            $settings = singleton_service::get_instance_of_booking_option_settings($id);
            $optioncmid = (int)($settings->cmid ?? 0);
            if ($optioncmid <= 0) {
                continue;
            }

            try {
                // Always render the agent's option previews as cards (card view is the most useful
                // compact representation), regardless of the booking instance's default view.
                $view = new view($optioncmid, 'showonlyone', $id);
                $html = (string)$view->get_rendered_showonlyone_table($id, MOD_BOOKING_VIEW_PARAM_CARDS);
                if (trim($html) !== '') {
                    $htmlparts[] = '<div class="booking-ai-preview-item mb-3">' . $html . '</div>';
                }
            } catch (\Throwable $e) {
                $htmlparts[] = '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
            }
        }

        return implode('', $htmlparts);
    }
}
