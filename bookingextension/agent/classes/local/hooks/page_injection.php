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

namespace bookingextension_agent\local\hooks;

use core\hook\output\before_standard_head_html_generation;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Injects the navbar magic-wand entry point on every Moodle page.
 *
 * Gated by the inject_in_navbar admin setting (default off), a logged-in
 * non-guest user and the manager-only agent:seemagicwand capability checked at
 * the current page context. The wand is a site-wide entry point, so it is
 * deliberately restricted to managers; per-module agent usage stays at teacher
 * level via useaiinstructions, which is still enforced server-side on every
 * call. No CSS is injected here: the plugin's styles.css
 * is already aggregated into the theme stylesheet on all pages. The
 * authoritative permission checks happen server-side on every agent call.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_injection {
    /**
     * Add the magic-wand bootstrap JS to the page.
     *
     * @param before_standard_head_html_generation $hook
     */
    public static function extend_head(before_standard_head_html_generation $hook): void {
        global $PAGE;

        // Coexistence: when the standalone local_wizard plugin has taken over, it injects its own
        // navbar entry point — the bundled engine must not add a second wand.
        if (!authorization_service::is_agent_engine_active()) {
            return;
        }

        if (empty(get_config('bookingextension_agent', 'inject_in_navbar'))) {
            return;
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        try {
            // Layouts without a navbar (or where an overlay would interfere).
            if (in_array($PAGE->pagelayout, ['embedded', 'popup', 'frametop', 'maintenance', 'print', 'redirect'], true)) {
                return;
            }

            $context = $PAGE->context;
            if (!has_capability('bookingextension/agent:seemagicwand', $context)) {
                return;
            }

            // Keep the per-page footprint minimal: only this tiny AMD module is
            // loaded; the label travels along so the JS needs no string AJAX.
            // The current-page snapshot is built from FREE $PAGE scalars already in
            // memory (no queries, no block loading), so the always-on hook stays cost-free.
            // Modal/templates/fragment load lazily on first click.
            $PAGE->requires->js_call_amd('bookingextension_agent/navbar_magic_wand', 'init', [
                (int)$context->id,
                get_string('agent_display_name', 'bookingextension_agent'),
                self::current_page_context($PAGE),
            ]);
        } catch (\Throwable $e) {
            // Never break page rendering for a convenience entry point
            // (e.g. $PAGE->context not initialised during install/upgrade).
            debugging('bookingextension_agent navbar injection skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Build a compact descriptor of the user's current page from FREE $PAGE scalars only.
     *
     * Everything read here is already resolved in memory by the page's own setup (require_login /
     * set_url / set_cm), so it adds NO queries and no block loading — safe for an always-on hook.
     * It covers every page family (course, activity, dashboard, user profile, admin, …) via pagetype,
     * and is a best-effort hint for the agent's runtime context, never an authorization source.
     *
     * @param \moodle_page $page
     * @return array
     */
    private static function current_page_context(\moodle_page $page): array {
        // Each access is guarded: a page that has not set a url/context must never break injection.
        $try = static function (callable $get) {
            try {
                return $get();
            } catch (\Throwable $e) {
                return null;
            }
        };

        $pc = [];
        $pc['pagetype'] = (string)($try(static fn() => $page->pagetype) ?? '');
        $pc['url'] = (string)($try(static fn() => $page->url->out(false)) ?? '');
        $pc['heading'] = (string)($try(static fn() => $page->title) ?? '');

        $context = $try(static fn() => $page->context);
        $pc['contextlevel'] = $context ? (int)$context->contextlevel : 0;

        $course = $try(static fn() => $page->course);
        if ($course && (int)$course->id !== (int)SITEID) {
            $pc['courseid'] = (int)$course->id;
            $pc['coursename'] = format_string($course->fullname);
        }

        $cm = $try(static fn() => $page->cm);
        if ($cm) {
            $pc['cmid'] = (int)$cm->id;
            $pc['modname'] = (string)$cm->modname;
            $pc['activityname'] = format_string($cm->name);
        }

        return $pc;
    }
}
