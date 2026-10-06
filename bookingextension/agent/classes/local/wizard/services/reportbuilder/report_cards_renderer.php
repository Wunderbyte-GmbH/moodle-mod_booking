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

use html_writer;

/**
 * Static HTML cards for the side preview of the report discovery skills.
 *
 * Pure markup, no JavaScript: the same block is used as an executed-result preview (source A) and
 * as the candidate list attached to a clarification (source C). All labels come from the plugin's
 * language pack in the requested output language; the data itself is what the catalog service
 * returned (localised by core).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_cards_renderer {
    /** @var string Output language ('' = current). */
    private string $lang;

    /**
     * Constructor.
     *
     * @param string $lang Output language code, '' for the current language.
     */
    public function __construct(string $lang = '') {
        $this->lang = trim($lang);
    }

    /**
     * One card per report source, grouped by plugin.
     *
     * @param array[] $sources Entries of report_source_catalog_service::list_sources().
     * @return string
     */
    public function render_sources(array $sources): string {
        if (empty($sources)) {
            return '';
        }
        $groups = [];
        foreach ($sources as $source) {
            $groups[(string)($source['componentname'] ?? '')][] = $source;
        }
        ksort($groups);

        $out = html_writer::start_div('bx-agent-report-sources');
        foreach ($groups as $componentname => $entries) {
            $out .= html_writer::tag('h6', s($componentname), ['class' => 'mt-3 mb-2 text-muted']);
            foreach ($entries as $entry) {
                $out .= $this->render_source_card($entry);
            }
        }
        $out .= html_writer::end_div();
        return $out;
    }

    /**
     * The columns, filters and conditions of one source as three lists grouped by entity.
     *
     * @param array $described Result of report_source_catalog_service::describe_source().
     * @return string
     */
    public function render_source_detail(array $described): string {
        $out = html_writer::start_div('bx-agent-report-source-detail');
        $out .= html_writer::tag('h5', s((string)($described['name'] ?? '')), ['class' => 'mb-1']);
        $out .= html_writer::tag(
            'div',
            html_writer::tag('code', s((string)($described['source'] ?? ''))),
            ['class' => 'small text-muted mb-3']
        );

        foreach (['columns', 'filters', 'conditions'] as $section) {
            $items = (array)($described[$section] ?? []);
            if (empty($items)) {
                continue;
            }
            $out .= html_writer::tag(
                'h6',
                s($this->str('agent_report_preview_' . $section)) . ' (' . count($items) . ')',
                ['class' => 'mt-3 mb-2']
            );
            $byentity = [];
            foreach ($items as $item) {
                $byentity[(string)($item['entitytitle'] ?? $item['entity'] ?? '')][] = $item;
            }
            foreach ($byentity as $entitytitle => $entityitems) {
                $out .= html_writer::tag('div', s($entitytitle), ['class' => 'small fw-bold mt-2']);
                $rows = '';
                foreach ($entityitems as $item) {
                    $rows .= $this->render_item_row($section, $item);
                }
                $out .= html_writer::tag(
                    'table',
                    html_writer::tag('tbody', $rows),
                    ['class' => 'table table-sm table-borderless mb-0 small']
                );
            }
        }

        $out .= html_writer::end_div();
        return $out;
    }

    /**
     * One card per existing report.
     *
     * @param array[] $reports Entries of report_resolver::find()['reports'] / summarize().
     * @return string
     */
    public function render_reports(array $reports): string {
        if (empty($reports)) {
            return '';
        }
        $out = html_writer::start_div('bx-agent-report-list');
        foreach ($reports as $report) {
            $idbadge = html_writer::tag('span', '#' . (int)($report['id'] ?? 0), ['class' => 'text-muted small']);
            $body = html_writer::tag('div', s((string)($report['name'] ?? '')) . ' ' . $idbadge, ['class' => 'fw-bold']);
            $body .= html_writer::tag(
                'div',
                s($this->str('agent_report_preview_source')) . ': ' . s((string)($report['sourcename'] ?? '')),
                ['class' => 'small text-muted']
            );
            $body .= html_writer::tag(
                'div',
                s($this->str('agent_report_preview_audiences')) . ': ' . (int)($report['audiences'] ?? 0) . ' · '
                . s($this->str('agent_report_preview_schedules')) . ': ' . (int)($report['schedules'] ?? 0),
                ['class' => 'small text-muted']
            );
            if (!empty($report['url'])) {
                $body .= html_writer::link(
                    $report['url'],
                    s($this->str('agent_report_preview_open_report')),
                    ['class' => 'small', 'target' => '_blank']
                );
            }
            $out .= html_writer::div(html_writer::div($body, 'card-body py-2 px-3'), 'card mb-2');
        }
        $out .= html_writer::end_div();
        return $out;
    }

    /**
     * One source card.
     *
     * @param array $entry
     * @return string
     */
    private function render_source_card(array $entry): string {
        $body = html_writer::tag('div', s((string)($entry['name'] ?? '')), ['class' => 'fw-bold']);
        $body .= html_writer::tag(
            'div',
            html_writer::tag('code', s((string)($entry['source'] ?? ''))),
            ['class' => 'small text-muted text-break']
        );

        $entities = array_map(
            static fn(array $e): string => (string)($e['title'] ?? $e['name'] ?? ''),
            (array)($entry['entities'] ?? [])
        );
        if (!empty($entities)) {
            $body .= html_writer::tag(
                'div',
                s($this->str('agent_report_preview_entities')) . ': ' . s(implode(', ', $entities)),
                ['class' => 'small mt-1']
            );
        }
        $counts = (array)($entry['counts'] ?? []);
        if (!empty($counts)) {
            $body .= html_writer::tag(
                'div',
                s($this->str('agent_report_preview_columns')) . ': ' . (int)($counts['columns'] ?? 0) . ' · '
                . s($this->str('agent_report_preview_filters')) . ': ' . (int)($counts['filters'] ?? 0) . ' · '
                . s($this->str('agent_report_preview_conditions')) . ': ' . (int)($counts['conditions'] ?? 0),
                ['class' => 'small text-muted']
            );
        }
        if (array_key_exists('available', $entry) && !$entry['available']) {
            $body .= html_writer::tag('div', s($this->str('agent_report_preview_unavailable')), ['class' => 'small text-danger']);
        }

        return html_writer::div(html_writer::div($body, 'card-body py-2 px-3'), 'card mb-2');
    }

    /**
     * One table row for a column, filter or condition.
     *
     * @param string $section
     * @param array $item
     * @return string
     */
    private function render_item_row(string $section, array $item): string {
        $title = s((string)($item['title'] ?? ''));
        if (!empty($item['default'])) {
            $badge = html_writer::tag('span', s($this->str('agent_report_preview_default')), ['class' => 'badge bg-secondary']);
            $title .= ' ' . $badge;
        }
        $cells = html_writer::tag('td', $title);
        $identifier = html_writer::tag('code', s((string)($item['identifier'] ?? '')));
        $cells .= html_writer::tag('td', $identifier, ['class' => 'text-break']);
        if ($section === 'columns') {
            $meta = (string)($item['type'] ?? '');
            $aggregations = (array)($item['aggregations'] ?? []);
            if (!empty($aggregations)) {
                $meta .= ' · ' . s(implode(', ', $aggregations));
            }
        } else {
            $meta = (string)($item['filterclass'] ?? '');
            $operators = array_map(static fn(array $o): string => (string)($o['key'] ?? ''), (array)($item['operators'] ?? []));
            if (!empty($operators)) {
                $meta .= ' · ' . s(implode(', ', $operators));
            }
        }
        $cells .= html_writer::tag('td', $meta, ['class' => 'text-muted']);
        return html_writer::tag('tr', $cells);
    }

    /**
     * Plugin string in the output language.
     *
     * @param string $identifier
     * @return string
     */
    private function str(string $identifier): string {
        if ($this->lang === '') {
            return get_string($identifier, 'bookingextension_agent');
        }
        return get_string_manager()->get_string($identifier, 'bookingextension_agent', null, $this->lang);
    }
}
