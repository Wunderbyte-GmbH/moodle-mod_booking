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

namespace bookingextension_agent\local\wizard\services;

/**
 * Finds the AI provider actions the wizard uses that carry a system instruction.
 *
 * The wizard brings its complete prompt per phase. Core sends a provider action's system instruction with every call
 * of that action (Gemini: as system_instruction, above the wizard prompt), and Moodle pre-fills one when an admin
 * creates a provider, e.g. "Your task is to summarize the provided text…" for Summarise text. A model that follows it
 * summarises the wizard prompt instead of answering it (Gemini, Wunderbyte-GmbH/Wunderbyte-GmbH#2724). The routing
 * stays as it is; the wizard names the setting so an admin can empty it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_instruction_check {
    /**
     * Enabled provider actions among the given ones that carry a system instruction.
     *
     * Every enabled provider offering the action counts, not only the first: core falls back to the next provider
     * when one fails.
     *
     * @param string[] $actionclasses Action classes the wizard routes to.
     * @param object[]|null $views Provider views as provider_compat::get_provider_views() returns them; null reads
     *     the site's providers.
     * @return array[] One entry per provider and action: action, actionname, provider, settingsurl.
     */
    public static function find_conflicts(array $actionclasses, ?array $views = null): array {
        $views = $views ?? provider_compat::get_provider_views();
        $conflicts = [];
        foreach (array_values(array_unique(array_filter($actionclasses))) as $actionclass) {
            foreach ($views as $view) {
                $action = (array)(((array)($view->actionconfig ?? []))[$actionclass] ?? []);
                if (empty($view->enabled) || empty($action['enabled'])) {
                    continue;
                }
                if (trim((string)($action['settings']['systeminstruction'] ?? '')) === '') {
                    continue;
                }
                $conflicts[] = [
                    'action' => $actionclass,
                    'actionname' => self::action_name($actionclass),
                    'provider' => trim((string)($view->name ?? $view->config['name'] ?? '')),
                    'settingsurl' => self::settings_url($view, $actionclass),
                ];
            }
        }
        return $conflicts;
    }

    /**
     * Display name of an action ("Summarise text").
     *
     * @param string $actionclass
     * @return string
     */
    private static function action_name(string $actionclass): string {
        if (class_exists($actionclass) && method_exists($actionclass, 'get_name')) {
            return (string)$actionclass::get_name();
        }
        $parts = explode('\\', $actionclass);
        return (string)end($parts);
    }

    /**
     * Settings page of the action: the instance's action settings on Moodle 5.x, the AI provider settings on 4.5.
     *
     * @param object $view
     * @param string $actionclass
     * @return string
     */
    private static function settings_url(object $view, string $actionclass): string {
        if (!empty($view->id)) {
            $providerclass = is_string($view->provider ?? null) && $view->provider !== '' ? $view->provider : get_class($view);
            $component = explode('\\', ltrim($providerclass, '\\'))[0];
            return (new \moodle_url('/ai/configure_actions.php', [
                'provider' => $component,
                'action' => $actionclass,
                'providerid' => (int)$view->id,
            ]))->out(false);
        }
        return (new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(false);
    }
}
