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
 * Shortcode handlers for bookingextension_agent.
 *
 * Renders the AI agent panel inline, anywhere Moodle runs the shortcodes filter
 * (a label, a custom page, a text field), as an alternative to the navbar magic
 * wand. The tag is guarded by an auto-generated security token so only content an
 * admin explicitly authored can surface the agent:
 *
 *   [wbbagent securitytoken=ABCDEFGH]
 *
 * The token is created and shown on the agent settings page. The authoritative
 * permission checks still happen server-side on every agent call (the panel itself
 * renders a "not ready" state when the viewer lacks the use-capability).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

/**
 * Shortcode handler class for bookingextension_agent.
 */
class shortcodes {
    /**
     * Render a warning box for shortcode validation failures.
     *
     * @param string $stringidentifier
     * @param mixed $a Optional string placeholder data.
     * @return string
     */
    private static function render_shortcode_warning(string $stringidentifier, $a = null): string {
        return '<div class="alert alert-warning bookingextension-agent-shortcode-warning" role="alert">'
            . \s(\get_string($stringidentifier, 'bookingextension_agent', $a))
            . '</div>';
    }

    /**
     * Render the AI agent panel inline for the current page context and viewer.
     *
     * Usage:
     *   [wbbagent securitytoken=ABCDEFGH]
     *
     * @param string        $shortcode The shortcode tag name ("wbbagent").
     * @param array         $args      Shortcode attributes. securitytoken is required.
     * @param string|null   $content   Inner content between tags (unused).
     * @param object        $env       Rendering environment from the filter.
     * @param \Closure|null $next      Next handler in the filter chain.
     * @return string Rendered HTML, a warning box, or empty string.
     */
    public static function wbbagent(
        string $shortcode,
        array $args,
        ?string $content,
        object $env,
        ?\Closure $next
    ): string {
        global $PAGE, $OUTPUT, $USER;

        // Coexistence: when the standalone local_wizard plugin owns the engine, it provides its own
        // entry point — render nothing here so an authored [wbbagent] tag does not surface a second
        // (inert) panel. Reverts automatically when local_wizard is removed.
        if (!\bookingextension_agent\local\wizard\services\security\authorization_service::is_agent_engine_active()) {
            return '';
        }

        $configuredtoken = (string) \get_config('bookingextension_agent', 'shortcodetoken');
        $providedtoken = isset($args['securitytoken']) ? trim((string) $args['securitytoken']) : '';

        if ($configuredtoken === '' || $providedtoken === '') {
            return self::render_shortcode_warning('shortcode_warning_missing_securitytoken');
        }

        if (!hash_equals($configuredtoken, $providedtoken)) {
            return self::render_shortcode_warning('shortcode_warning_invalid_securitytoken');
        }

        // The agent is rendered for the context of the formatted content and the current viewer.
        // aiready + the aiinstructions template are the same entry point the inline booking view and
        // the navbar fragment use; the template self-bootstraps its AMD chat module, so rendering it
        // is enough. Take the context from the filter environment ($env->context), NOT
        // empty($PAGE->context): moodle_page has __get but no __isset, so empty() consults __isset and
        // always reports the magic property as empty even when a valid context exists. $env->context is
        // a plain property (no magic gotcha) and is the correct context for the content being filtered.
        $context = $env->context ?? ($PAGE->context ?? null);
        if (empty($context) || !\class_exists('\bookingextension_agent\local\wizard\aiready')) {
            return '';
        }

        try {
            $aiready = new \bookingextension_agent\local\wizard\aiready((int)$context->id, (int)$USER->id);
            return $OUTPUT->render_from_template('bookingextension_agent/aiinstructions', $aiready->export_for_template());
        } catch (\Throwable $e) {
            // Never let a misplaced shortcode (e.g. an unsupported context) break the page.
            debugging('bookingextension_agent [wbbagent] shortcode skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }
}
