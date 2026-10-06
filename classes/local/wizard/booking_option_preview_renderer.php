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
     * Render preview HTML for booking option ids, together with its render-time JS.
     *
     * The booking templates bind their behaviour (booking button, prepage modal and inline pages,
     * notify-me, table reload) in Mustache {{#js}} blocks. Moodle routes those to $PAGE->requires
     * instead of the returned markup, and inside this synchronous webservice request the page
     * footer is never emitted — so the card would arrive in the preview pane with a booking button
     * that nothing listens to. We therefore COLLECT that JS (Moodle's fragment pattern:
     * start_collecting_javascript_requirements + get_end_code) and hand it back separately; the
     * client injects the HTML and runs the JS via core/templates replaceNodeContents.
     *
     * @param array $payload      The preview payload containing optionids.
     * @param int   $contextid    Moodle context id.
     * @param int   $userid       Current user id.
     * @return array              Rendered html and the collected js, both '' when nothing rendered.
     */
    public function render(array $payload, int $contextid, int $userid): array {
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
            return ['html' => '', 'js' => ''];
        }

        // Switch the page over to a fragment requirements manager so the render-time JS of the
        // booking templates is collected instead of being dropped with the never-emitted footer.
        // This needs a minimally initialised page output, which initialise_theme_and_output()
        // provides WITHOUT emitting any HTML. If that cannot be set up in this request context we
        // degrade gracefully: the card is rendered anyway, just without its JS.
        $collecting = $this->start_collecting();

        $htmlparts = [];
        // A stray echo (debug notice) inside the table rendering must never corrupt the JSON
        // webservice envelope, so the whole loop renders into an output buffer we discard.
        ob_start();
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
                // compact representation), regardless of the booking instance's default view —
                // and without table chrome (count label, reload, download), which reads as noise
                // when every option gets its own one-row preview card.
                $view = new view($optioncmid, 'showonlyone', $id);
                $html = (string)$view->get_rendered_showonlyone_table($id, MOD_BOOKING_VIEW_PARAM_CARDS, false);
                if (trim($html) !== '') {
                    $htmlparts[] = '<div class="booking-ai-preview-item mb-3">' . $html . '</div>';
                }
            } catch (\Throwable $e) {
                $htmlparts[] = '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
            }
        }
        ob_end_clean();

        $js = $collecting ? $this->stop_collecting(!empty($htmlparts)) : '';

        return ['html' => implode('', $htmlparts), 'js' => $js];
    }

    /**
     * Start collecting the render-time JS of this request, best effort.
     *
     * @return bool True when collecting is active and must be stopped again.
     */
    private function start_collecting(): bool {
        // This is a plain service, not a Moodle renderer subclass (it has no $this->page). It uses
        // the global $PAGE requirements manager on purpose, to collect render-time JS via the
        // fragment pattern documented on render().
        // phpcs:disable moodle.PHP.ForbiddenGlobalUse
        global $PAGE;

        try {
            $PAGE->initialise_theme_and_output();
            if ($PAGE->requires) {
                $PAGE->start_collecting_javascript_requirements();
                return true;
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        return false;
        // phpcs:enable moodle.PHP.ForbiddenGlobalUse
    }

    /**
     * Read the collected JS and restore the page's real requirements manager.
     *
     * @param bool $wantjs Whether anything was rendered that could need its JS.
     * @return string The collected JS, '' when nothing was rendered or reading it failed.
     */
    private function stop_collecting(bool $wantjs): string {
        // phpcs:disable moodle.PHP.ForbiddenGlobalUse
        global $PAGE;

        $js = '';
        try {
            // Read the collected code BEFORE end_collecting restores the real manager.
            if ($wantjs) {
                $js = (string)$PAGE->requires->get_end_code();
            }
        } catch (\Throwable $e) {
            $js = '';
        } finally {
            try {
                $PAGE->end_collecting_javascript_requirements();
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        return $js;
        // phpcs:enable moodle.PHP.ForbiddenGlobalUse
    }
}
