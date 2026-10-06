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

/**
 * Renders the dedicated "course structure" preview block (sections → activities tree).
 *
 * The skill supplies the already-visibility-filtered structure as plain data; this builder turns it into the
 * self-contained preview block the preview API forwards (get_result_preview() -> {type, html, payload}).
 * Output is built with html_writer and hardened with an output buffer, like the diagnostic/activity previews.
 * Badges mark hidden-from-students, availability-restricted, locked (visible but not enterable) and group items.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_structure_preview {
    /** Preview type string the client dispatches on. */
    public const PREVIEW_TYPE = 'course_structure';

    /**
     * Build the preview data block from a course structure.
     *
     * @param array $structure The structure produced by {@see course_structure_service::analyze()}.
     * @return array{type:string,html:string,payload:array}|null Null when there is nothing renderable.
     */
    public function render(array $structure): ?array {
        $sections = (array)($structure['sections'] ?? []);
        if (empty($sections)) {
            return null;
        }

        ob_start();
        try {
            $html = $this->build_html($structure, $sections);
        } catch (\Throwable $e) {
            $html = '';
        } finally {
            ob_end_clean();
        }

        if (trim($html) === '') {
            return null;
        }

        $activitycount = 0;
        foreach ($sections as $section) {
            $activitycount += count((array)($section['activities'] ?? []));
        }

        return [
            'type' => self::PREVIEW_TYPE,
            'html' => $html,
            'payload' => [
                'courseid' => (int)($structure['courseid'] ?? 0),
                'sectioncount' => count($sections),
                'activitycount' => $activitycount,
            ],
        ];
    }

    /**
     * Build the structure HTML.
     *
     * @param array $structure
     * @param array[] $sections
     * @return string
     */
    private function build_html(array $structure, array $sections): string {
        $heading = \html_writer::tag(
            'div',
            s((string)($structure['coursename'] ?? '')),
            ['class' => 'fw-bold mb-2']
        );

        $sectionhtml = '';
        foreach ($sections as $section) {
            $sectionhtml .= $this->section_html($section);
        }

        $list = \html_writer::tag('ul', $sectionhtml, ['class' => 'list-unstyled mb-0']);

        return \html_writer::tag(
            'div',
            $heading . $list,
            ['class' => 'wizard-course-structure card card-body']
        );
    }

    /**
     * Render one section and its activities.
     *
     * @param array $section
     * @return string
     */
    private function section_html(array $section): string {
        $name = (string)($section['name'] ?? '');
        $title = \html_writer::tag('span', s($name), ['class' => 'fw-medium']);
        $title .= $this->section_badges($section);

        $summary = trim((string)($section['summary_text'] ?? ''));
        $summaryhtml = $summary !== ''
            ? \html_writer::tag('div', s($summary), ['class' => 'text-muted small ms-3'])
            : '';

        $activities = (array)($section['activities'] ?? []);
        $activityhtml = '';
        foreach ($activities as $activity) {
            $activityhtml .= $this->activity_html($activity);
        }
        $activitylist = $activityhtml !== ''
            ? \html_writer::tag('ul', $activityhtml, ['class' => 'list-unstyled ms-3 mb-0'])
            : '';

        return \html_writer::tag(
            'li',
            $title . $summaryhtml . $activitylist,
            ['class' => 'wizard-cs-section mb-2']
        );
    }

    /**
     * Render one activity row.
     *
     * @param array $activity
     * @return string
     */
    private function activity_html(array $activity): string {
        $modname = (string)($activity['modname'] ?? '');
        $name = (string)($activity['name'] ?? '');

        $label = \html_writer::tag('span', s($modname), ['class' => 'badge bg-light text-dark me-1']);
        $url = is_string($activity['url'] ?? null) ? trim((string)$activity['url']) : '';
        $namehtml = $url !== '' && !empty($activity['accessible'])
            ? \html_writer::link($url, s($name), ['target' => '_blank', 'rel' => 'noopener'])
            : \html_writer::tag('span', s($name));

        $badges = $this->activity_badges($activity);

        $intro = trim((string)($activity['intro_text'] ?? ''));
        $introhtml = $intro !== ''
            ? \html_writer::tag('div', s($intro), ['class' => 'text-muted small ms-4'])
            : '';

        return \html_writer::tag(
            'li',
            $label . $namehtml . $badges . $introhtml,
            ['class' => 'wizard-cs-activity mb-1']
        );
    }

    /**
     * Badges for a section.
     *
     * @param array $section
     * @return string
     */
    private function section_badges(array $section): string {
        $badges = '';
        if (!empty($section['hidden'])) {
            $badges .= $this->badge(get_string('cs_badge_hidden', 'bookingextension_agent'), 'bg-secondary');
        }
        if (empty($section['accessible'])) {
            $badges .= $this->badge(get_string('cs_badge_locked', 'bookingextension_agent'), 'bg-danger');
        } else if (!empty($section['restricted'])) {
            $badges .= $this->badge(get_string('cs_badge_restricted', 'bookingextension_agent'), 'bg-warning text-dark');
        }
        $badges .= $this->restriction_note($section);
        return $badges;
    }

    /**
     * Badges for an activity.
     *
     * @param array $activity
     * @return string
     */
    private function activity_badges(array $activity): string {
        $badges = '';
        if (!empty($activity['hidden'])) {
            $badges .= $this->badge(get_string('cs_badge_hidden', 'bookingextension_agent'), 'bg-secondary');
        }
        if (empty($activity['accessible'])) {
            $badges .= $this->badge(get_string('cs_badge_locked', 'bookingextension_agent'), 'bg-danger');
        } else if (!empty($activity['restricted'])) {
            $badges .= $this->badge(get_string('cs_badge_restricted', 'bookingextension_agent'), 'bg-warning text-dark');
        }
        $groupmode = (string)($activity['groupmode'] ?? 'none');
        if ($groupmode !== 'none') {
            $badges .= $this->badge(
                get_string('cs_badge_groups', 'bookingextension_agent') . ': ' . $groupmode,
                'bg-info text-dark'
            );
        }
        $badges .= $this->restriction_note($activity);
        return $badges;
    }

    /**
     * Render the restriction reason text, if any.
     *
     * @param array $node
     * @return string
     */
    private function restriction_note(array $node): string {
        $info = trim((string)($node['restrictinfo'] ?? ''));
        if ($info === '') {
            return '';
        }
        return \html_writer::tag('span', ' — ' . s($info), ['class' => 'text-muted small fst-italic']);
    }

    /**
     * Build a single badge.
     *
     * @param string $text
     * @param string $bgclass
     * @return string
     */
    private function badge(string $text, string $bgclass): string {
        return ' ' . \html_writer::tag('span', s($text), ['class' => 'badge ' . $bgclass]);
    }
}
