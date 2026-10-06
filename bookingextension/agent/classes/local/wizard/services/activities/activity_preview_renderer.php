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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\activities;

use stdClass;

/**
 * Renders a compact, self-contained HTML card for a freshly created activity (agent preview pane).
 *
 * Output is built with html_writer and hardened with an output buffer so no stray rendering can break
 * the JSON webservice response (same discipline as the question preview renderer).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_preview_renderer {
    /**
     * Render the preview card.
     *
     * @param stdClass $course
     * @param int $cmid
     * @param string $modname
     * @param string $name
     * @param string $url
     * @return string HTML (empty string on any failure).
     */
    public function render(stdClass $course, int $cmid, string $modname, string $name, string $url): string {
        ob_start();
        try {
            $html = $this->build_html($course, $cmid, $modname, $name, $url);
        } catch (\Throwable $e) {
            $html = '';
        } finally {
            // Discard anything a renderer might have echoed directly.
            ob_end_clean();
        }
        return $html;
    }

    /**
     * Build the card HTML.
     *
     * @param stdClass $course
     * @param int $cmid
     * @param string $modname
     * @param string $name
     * @param string $url
     * @return string
     */
    private function build_html(stdClass $course, int $cmid, string $modname, string $name, string $url): string {
        $iconhtml = '';
        if ($cmid > 0) {
            try {
                $cm = get_fast_modinfo($course)->get_cm($cmid);
                $iconurl = $cm->get_icon_url();
                if ($iconurl) {
                    $iconhtml = \html_writer::empty_tag('img', [
                        'src' => $iconurl->out(false),
                        'alt' => '',
                        'class' => 'activityicon me-2',
                        'width' => 24,
                        'height' => 24,
                    ]);
                }
            } catch (\Throwable $e) {
                $iconhtml = '';
            }
        }

        $modlabel = get_string('pluginname', 'mod_' . $modname);
        $title = $name !== '' ? $name : $modlabel;

        $titlehtml = $url !== ''
            ? \html_writer::link($url, s($title), ['target' => '_blank', 'rel' => 'noopener'])
            : s($title);

        $body = \html_writer::tag('div', $iconhtml . \html_writer::tag('strong', $titlehtml), [
            'class' => 'd-flex align-items-center',
        ]);
        $meta = \html_writer::tag('div', s($modlabel), ['class' => 'text-muted small mt-1']);

        return \html_writer::tag('div', $body . $meta, [
            'class' => 'wizard-activity-preview card card-body',
        ]);
    }
}
