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

namespace bookingextension_agent\local\wizard\diagnostics;

use moodle_url;

/**
 * Renders the shared "diagnosis checklist" preview block for the diagnose-skill family.
 *
 * Skills supply rows as plain data ({status, check, finding, url}); this builder turns them into the
 * self-contained preview data block the preview API forwards (get_result_preview() -> {type, html, payload}).
 * One renderer for all diagnose skills = consistent look, near-zero per-skill preview cost. Output is
 * built with html_writer and hardened with an output buffer, exactly like the activity/question previews.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostic_checklist_preview {
    /** Preview type string the client dispatches on. */
    public const PREVIEW_TYPE = 'diagnostic_checklist';

    /** @var array Status -> glyph. */
    private const GLYPHS = ['ok' => '✓', 'fail' => '✗', 'warn' => '⚠'];

    /** @var array Status -> bootstrap text class. */
    private const TEXTCLASS = ['ok' => 'text-success', 'fail' => 'text-danger', 'warn' => 'text-warning'];

    /**
     * Build the preview data block from checklist rows.
     *
     * @param array[] $rows
     * @param string $title Optional heading for the checklist.
     * @param array $payload Extra payload passed through to the client.
     * @return array{type:string,html:string,payload:array}|null Null when there are no renderable rows.
     */
    public function render(array $rows, string $title = '', array $payload = []): ?array {
        $rows = array_values(array_filter($rows, static fn($r): bool => is_array($r) && trim((string)($r['check'] ?? '')) !== ''));
        if (empty($rows)) {
            return null;
        }

        ob_start();
        try {
            $html = $this->build_html($rows, $title);
        } catch (\Throwable $e) {
            $html = '';
        } finally {
            ob_end_clean();
        }

        if (trim($html) === '') {
            return null;
        }

        return [
            'type' => self::PREVIEW_TYPE,
            'html' => $html,
            'payload' => $payload + ['rowcount' => count($rows)],
        ];
    }

    /**
     * Build the checklist HTML.
     *
     * @param array[] $rows
     * @param string $title
     * @return string
     */
    private function build_html(array $rows, string $title): string {
        $items = '';
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? 'warn');
            if (!isset(self::GLYPHS[$status])) {
                $status = 'warn';
            }
            $glyph = \html_writer::tag(
                'span',
                self::GLYPHS[$status],
                ['class' => self::TEXTCLASS[$status] . ' me-2 fw-bold', 'aria-hidden' => 'true']
            );

            $check = \html_writer::tag('span', s((string)($row['check'] ?? '')), ['class' => 'fw-medium']);

            $finding = '';
            $findingtext = trim((string)($row['finding'] ?? ''));
            if ($findingtext !== '') {
                $finding = \html_writer::tag('div', s($findingtext), ['class' => 'text-muted small ms-4']);
            }

            $link = '';
            $url = $row['url'] ?? null;
            if ($url instanceof moodle_url) {
                $url = $url->out(false);
            }
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '') {
                $link = ' ' . \html_writer::link(
                    $url,
                    get_string('diagnostic_open_link', 'bookingextension_agent'),
                    ['target' => '_blank', 'rel' => 'noopener', 'class' => 'small']
                );
            }

            $items .= \html_writer::tag(
                'li',
                $glyph . $check . $link . $finding,
                ['class' => 'wizard-diagnostic-row mb-1']
            );
        }

        $heading = trim($title) !== ''
            ? \html_writer::tag('div', s(trim($title)), ['class' => 'fw-bold mb-2'])
            : '';

        $list = \html_writer::tag('ul', $items, ['class' => 'list-unstyled mb-0']);

        return \html_writer::tag('div', $heading . $list, ['class' => 'wizard-diagnostic-checklist card card-body']);
    }
}
