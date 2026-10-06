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

namespace bookingextension_agent\local\wizard\services\reportbuilder;

use core_reportbuilder\local\models\report;
use core_reportbuilder\output\custom_report;
use core_reportbuilder\permission;
use html_writer;
use moodle_url;

/**
 * Live side-panel preview of a custom report: the real Report Builder view, as the site renders it.
 *
 * Renders core's own `core_reportbuilder/report` template (view mode) inside a fragment-style
 * JavaScript collection window, so the dynamic table, its paging and sorting and the filter form
 * arrive with the render-time JS that a synchronous web service response would otherwise drop
 * (the same recipe as the booking-option and question previews). Paging, sorting and filtering in
 * the panel then run through the core web services with their own permission checks; nothing here
 * adds an access decision of its own beyond core's can_view_report() for the acting user.
 *
 * The block carries `replace => true`: it shows the CURRENT state of one report, so a later step
 * of a series supersedes it instead of appending.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_preview_renderer {
    /** Preview type of the live report view. */
    public const PREVIEW_TYPE = 'reportbuilder_report';

    /**
     * Build the preview block for a report, or null when the user may not view it.
     *
     * @param report $persistent
     * @param int $userid Acting user.
     * @param array $snapshot Optional structure (report_definition_service::snapshot) for the header card.
     * @param string $lang Output language for the header labels ('' = current).
     * @return array|null {type, html, js, replace, payload}
     */
    public function build(report $persistent, int $userid, array $snapshot = [], string $lang = ''): ?array {
        if (!permission::can_view_report($persistent, $userid)) {
            return null;
        }

        $reportid = (int)$persistent->get('id');
        $header = $this->render_header($persistent, $snapshot, $lang);
        ['html' => $reporthtml, 'js' => $js] = $this->render_report($persistent);

        $html = html_writer::div($header . $reporthtml, 'bx-agent-report-preview', ['data-report-id' => $reportid]);

        return [
            'type' => self::PREVIEW_TYPE,
            'html' => $html,
            'js' => $js,
            'replace' => true,
            'payload' => [
                'reportid' => $reportid,
                'revision' => (int)$persistent->get('timemodified'),
            ],
        ];
    }

    /**
     * Core's report view (table, filters, download) with its render-time JS collected.
     *
     * @param report $persistent
     * @return array{html: string, js: string}
     */
    private function render_report(report $persistent): array {
        // This is a plain service, not a Moodle renderer subclass (no $this->page): it uses the global
        // page on purpose, to render core's template with the page's requirements manager collecting.
        // phpcs:disable moodle.PHP.ForbiddenGlobalUse
        global $PAGE;

        $collecting = $this->start_collecting($persistent);
        $html = '';
        ob_start();
        try {
            $renderer = $PAGE->get_renderer('core_reportbuilder');
            $export = (new custom_report($persistent, false))->export_for_template($renderer);
            $html = (string)$renderer->render_from_template('core_reportbuilder/report', $export);
        } catch (\Throwable $e) {
            $html = html_writer::div(s($e->getMessage()), 'alert alert-danger');
        } finally {
            ob_end_clean();
        }
        $js = $collecting ? $this->stop_collecting($html !== '') : '';

        return ['html' => $html, 'js' => $js];
        // phpcs:enable moodle.PHP.ForbiddenGlobalUse
    }

    /**
     * Compact header above the live view: name, source, structure counts, links.
     *
     * @param report $persistent
     * @param array $snapshot
     * @param string $lang
     * @return string
     */
    private function render_header(report $persistent, array $snapshot, string $lang): string {
        $reportid = (int)$persistent->get('id');
        $name = (string)$persistent->get('name');
        $sourcename = (string)($snapshot['sourcename'] ?? '');

        $out = html_writer::tag('h5', s($name), ['class' => 'mb-1']);
        if ($sourcename !== '') {
            $out .= html_writer::div(
                s($this->str('agent_report_preview_source', $lang)) . ': ' . s($sourcename),
                'small text-muted'
            );
        }

        $counts = [];
        foreach (['columns', 'conditions', 'filters', 'audiences', 'schedules'] as $key) {
            if (array_key_exists($key, $snapshot)) {
                $counts[] = s($this->str('agent_report_preview_' . $key, $lang)) . ': ' . count((array)$snapshot[$key]);
            }
        }
        if (array_key_exists('rowcount', $snapshot) && $snapshot['rowcount'] !== null) {
            $counts[] = s($this->str('agent_report_preview_rows', $lang)) . ': ' . (int)$snapshot['rowcount'];
        }
        if (!empty($counts)) {
            $out .= html_writer::div(implode(' · ', $counts), 'small text-muted');
        }

        $links = html_writer::link(
            new moodle_url('/reportbuilder/view.php', ['id' => $reportid]),
            s($this->str('agent_report_preview_open_report', $lang)),
            ['class' => 'btn btn-sm btn-outline-secondary me-2', 'target' => '_blank']
        );
        if (!empty($snapshot['canedit'])) {
            $links .= html_writer::link(
                new moodle_url('/reportbuilder/edit.php', ['id' => $reportid]),
                s($this->str('agent_report_preview_open_editor', $lang)),
                ['class' => 'btn btn-sm btn-outline-secondary', 'target' => '_blank']
            );
        }
        $out .= html_writer::div($links, 'my-2');

        return html_writer::div($out, 'bx-agent-report-preview-header mb-3');
    }

    /**
     * Switch the page to a collecting requirements manager (fragment pattern).
     *
     * @param report $persistent
     * @return bool Whether collection is active.
     */
    private function start_collecting(report $persistent): bool {
        // phpcs:disable moodle.PHP.ForbiddenGlobalUse
        global $PAGE;

        try {
            if (!$PAGE->has_set_url()) {
                $PAGE->set_url(new moodle_url('/reportbuilder/view.php', ['id' => (int)$persistent->get('id')]));
            }
        } catch (\Throwable $e) {
            unset($e);
        }
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
     * Read the collected JS (before the real manager is restored) and stop collecting.
     *
     * @param bool $wantjs
     * @return string
     */
    private function stop_collecting(bool $wantjs): string {
        // phpcs:disable moodle.PHP.ForbiddenGlobalUse
        global $PAGE;

        $js = '';
        try {
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

    /**
     * Plugin string in the output language.
     *
     * @param string $identifier
     * @param string $lang
     * @return string
     */
    private function str(string $identifier, string $lang): string {
        if ($lang === '') {
            return get_string($identifier, 'bookingextension_agent');
        }
        return get_string_manager()->get_string($identifier, 'bookingextension_agent', null, $lang);
    }
}
