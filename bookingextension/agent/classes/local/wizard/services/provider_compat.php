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

namespace bookingextension_agent\local\wizard\services;

use core\di;
use core_ai\manager as ai_manager;

/**
 * Moodle-version-agnostic view over core_ai provider configuration.
 *
 * Moodle 5.0 introduced multi-instance AI providers (the `ai_providers` table plus
 * manager methods `get_provider_instances()` / `create_/update_/enable_provider_instance()`).
 * Moodle 4.5 — supported until October 2027 — has the older single-instance model: one
 * config block per aiprovider plugin in `config_plugins`, with no instance table and no
 * instance methods on the manager (it exposes only `process_action()` + static helpers).
 *
 * This service hides that difference behind one shape. On 5.x it returns the real provider
 * instances; on 4.5 it synthesises one instance-shaped object per configured aiprovider
 * plugin from the plugin's flat config keys, so every read-site can keep iterating
 * `->provider`, `->enabled`, `->config[...]`, `->actionconfig[...]` unchanged.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_compat {
    /**
     * Whether the running Moodle exposes the 5.0+ multi-instance provider API.
     *
     * @return bool True on Moodle 5.0+, false on the 4.5 single-instance model.
     */
    public static function supports_provider_instances(): bool {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return false;
        }
        return method_exists($manager, 'get_provider_instances');
    }

    /**
     * Version-agnostic list of provider "views".
     *
     * Each returned object exposes the same fields a 5.x core_ai provider instance does and
     * that the agent's read-sites rely on: `->provider` (provider class name), `->enabled`,
     * `->name`, `->id`, `->config` (incl. `apikey`, `name`) and `->actionconfig` keyed by
     * action class name with `['enabled' => bool, 'settings' => ['endpoint','model',...]]`.
     *
     * @return object[] Provider instances (5.x) or synthesised views (4.5).
     */
    public static function get_provider_views(): array {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return [];
        }

        if (method_exists($manager, 'get_provider_instances')) {
            return array_values((array)$manager->get_provider_instances());
        }

        return self::synthesise_legacy_views();
    }

    /**
     * Create or update a provider's configuration, version-agnostically.
     *
     * On Moodle 5.x this updates the supplied existing instance (or creates a new one) via the
     * multi-instance manager API. On Moodle 4.5 there are no instances: the configuration is
     * written as flat plugin config (apikey + per-action endpoint/model/systeminstruction keys),
     * the actions are enabled via manager::set_action_state, and the provider plugin is enabled.
     *
     * NOTE on 4.5: a provider plugin has a single config slot, so configuring e.g. aiprovider_openai
     * for the agent overwrites that plugin's existing site configuration. This is the documented
     * trade-off of the reduced 4.5 mode (no separate per-instance config exists there).
     *
     * @param string $providerclass The provider class, e.g. 'aiprovider_openai\\provider'.
     * @param array $config Top-level provider config (e.g. ['apikey' => '...']). 'name' is display-only.
     * @param array $actionconfig 5.x-shaped action map: [ACTIONCLASS => ['enabled'=>bool,'settings'=>[...]]].
     * @param string $displayname Instance display name (5.x only; 4.5 has no per-instance name).
     * @param object|null $existing The existing instance to update (5.x); ignored on 4.5.
     */
    public static function configure_provider(
        string $providerclass,
        array $config,
        array $actionconfig,
        string $displayname,
        ?object $existing = null
    ): void {
        $manager = di::get(ai_manager::class);

        if (method_exists($manager, 'get_provider_instances')) {
            if ($existing !== null) {
                $instance = $manager->update_provider_instance(
                    provider: $existing,
                    config: $config,
                    actionconfig: $actionconfig,
                );
                if (empty($instance->enabled)) {
                    $manager->enable_provider_instance($instance);
                }
                return;
            }
            $manager->create_provider_instance(
                classname: $providerclass,
                name: $displayname,
                enabled: true,
                config: $config,
                actionconfig: $actionconfig,
            );
            return;
        }

        self::configure_legacy_provider($providerclass, $config, $actionconfig);
    }

    /**
     * Enable a provider that a provider view describes, version-agnostically.
     *
     * 5.x: enable the instance. 4.5: enable the provider plugin.
     *
     * @param object $view A provider instance (5.x) or synthesised view (4.5).
     */
    public static function enable_provider_view(object $view): void {
        $manager = di::get(ai_manager::class);
        if (method_exists($manager, 'enable_provider_instance')) {
            $manager->enable_provider_instance($view);
            return;
        }
        $component = self::component_from_providerclass((string)($view->provider ?? ''));
        $shortname = self::short_name_from_component($component);
        if ($shortname !== '' && \core_component::get_plugin_directory('aiprovider', $shortname)) {
            \core\plugininfo\aiprovider::enable_plugin($shortname, 1);
        }
    }

    /**
     * Write a 5.x-shaped provider config to 4.5 flat plugin config.
     *
     * @param string $providerclass
     * @param array $config
     * @param array $actionconfig
     */
    private static function configure_legacy_provider(string $providerclass, array $config, array $actionconfig): void {
        $component = self::component_from_providerclass($providerclass);
        $shortname = self::short_name_from_component($component);
        if ($shortname === '' || !\core_component::get_plugin_directory('aiprovider', $shortname)) {
            throw new \coding_exception('provider_compat: aiprovider plugin not installed: ' . $component);
        }

        // Top-level provider config (apikey, etc.). 'name' is a 5.x display label with no 4.5 equivalent.
        foreach ($config as $key => $value) {
            if ($key === 'name') {
                continue;
            }
            set_config($key, is_scalar($value) ? (string)$value : json_encode($value), $component);
        }

        // Per-action settings -> flat config keys, plus per-action enabled state.
        foreach ($actionconfig as $actionclass => $cfg) {
            if (!is_string($actionclass) || !class_exists($actionclass)) {
                // A 4.5-absent action (e.g. a Wunderbyte custom action) has no home here -> skip.
                continue;
            }
            $basename = $actionclass::get_basename();
            $settings = (array)(($cfg['settings'] ?? []));
            foreach (['endpoint', 'model'] as $settingkey) {
                if (isset($settings[$settingkey]) && $settings[$settingkey] !== '') {
                    set_config("action_{$basename}_{$settingkey}", (string)$settings[$settingkey], $component);
                }
            }
            // An empty system instruction is a value, not a gap: the agent brings its complete prompt, and leaving
            // the key alone kept core's default "summarise / generate" instruction in front of it (demo4, #2569).
            if (array_key_exists('systeminstruction', $settings)) {
                set_config("action_{$basename}_systeminstruction", (string)$settings['systeminstruction'], $component);
            }
            // The flat per-action state is a 4.5 API (static there); from 5.0 on actions live on provider instances
            // and this legacy path never runs.
            if (
                method_exists(ai_manager::class, 'set_action_state')
                && (new \ReflectionMethod(ai_manager::class, 'set_action_state'))->isStatic()
            ) {
                ai_manager::set_action_state($component, $basename, !empty($cfg['enabled']) ? 1 : 0);
            }
        }

        // Finally enable the provider plugin itself.
        \core\plugininfo\aiprovider::enable_plugin($shortname, 1);
    }

    /**
     * Whether a provider instance (or 4.5 view) is of the given provider class.
     *
     * Moodle 5.x instances carry the component in ->provider ('aiprovider_openai'), the synthesised 4.5 views the class
     * name ('aiprovider_openai\\provider'); comparing with the class name alone never matched a real instance, so an
     * existing instance was never found again and a second one was created (#2569).
     *
     * @param object $instance
     * @param string $providerclass e.g. 'aiprovider_wunderbyte\\provider'
     * @return bool
     */
    public static function is_instance_of_provider(object $instance, string $providerclass): bool {
        $provider = ltrim((string)($instance->provider ?? ''), '\\');
        $providerclass = ltrim($providerclass, '\\');
        return $provider === $providerclass
            || $provider === self::component_from_providerclass($providerclass)
            || ltrim(get_class($instance), '\\') === $providerclass;
    }

    /**
     * Derive the component name from a provider class name.
     *
     * @param string $providerclass e.g. 'aiprovider_openai\\provider'
     * @return string e.g. 'aiprovider_openai'
     */
    private static function component_from_providerclass(string $providerclass): string {
        $providerclass = ltrim($providerclass, '\\');
        $pos = strpos($providerclass, '\\');
        return $pos === false ? $providerclass : substr($providerclass, 0, $pos);
    }

    /**
     * Derive the aiprovider short name (without the 'aiprovider_' prefix) from a component.
     *
     * @param string $component e.g. 'aiprovider_openai'
     * @return string e.g. 'openai' ('' if not an aiprovider component)
     */
    private static function short_name_from_component(string $component): string {
        $prefix = 'aiprovider_';
        if (strpos($component, $prefix) !== 0) {
            return '';
        }
        return substr($component, strlen($prefix));
    }

    /**
     * Build instance-shaped views from 4.5 flat plugin config.
     *
     * Mirrors 5.x semantics where only *created* instances are returned: a plugin is only
     * surfaced when it is actually configured (`is_provider_configured()`), regardless of
     * whether it is currently enabled — disabled-but-configured must stay visible so the
     * trial "activate" path can still find it.
     *
     * @return object[]
     */
    private static function synthesise_legacy_views(): array {
        $views = [];
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('aiprovider');
        foreach ($plugins as $plugin) {
            $component = (string)$plugin->component;            // E.g. 'aiprovider_openai'.
            $providerclass = $component . '\\provider';
            if (!class_exists($providerclass)) {
                continue;
            }

            try {
                // 4.5 providers have a no-arg constructor (see core_ai\manager::get_providers_for_actions).
                $provider = new $providerclass();
                if (!$provider->is_provider_configured()) {
                    // Not configured -> there is no equivalent "instance" to report.
                    continue;
                }
                $actionlist = (array)$provider->get_action_list();
            } catch (\Throwable $e) {
                continue;
            }

            $views[] = (object)[
                'id' => null,
                'provider' => $providerclass,
                'name' => (string)($plugin->displayname ?? $component),
                'enabled' => (bool)$plugin->is_enabled(),
                'config' => [
                    'apikey' => (string)get_config($component, 'apikey'),
                    'name' => (string)($plugin->displayname ?? $component),
                ],
                'actionconfig' => self::legacy_actionconfig($component, $actionlist),
            ];
        }

        return $views;
    }

    /**
     * Assemble a 5.x-shaped actionconfig map from 4.5 per-action flat config keys.
     *
     * @param string $component The aiprovider component (e.g. 'aiprovider_openai').
     * @param string[] $actionlist Action class names the provider supports.
     * @return array
     */
    private static function legacy_actionconfig(string $component, array $actionlist): array {
        $actionconfig = [];
        foreach ($actionlist as $actionclass) {
            if (!is_string($actionclass) || !class_exists($actionclass)) {
                continue;
            }
            $basename = $actionclass::get_basename();

            $settings = [];
            $endpoint = (string)get_config($component, "action_{$basename}_endpoint");
            $model = (string)get_config($component, "action_{$basename}_model");
            $systeminstruction = (string)get_config($component, "action_{$basename}_systeminstruction");
            if ($endpoint !== '') {
                $settings['endpoint'] = $endpoint;
            }
            if ($model !== '') {
                $settings['model'] = $model;
            }
            if ($systeminstruction !== '') {
                $settings['systeminstruction'] = $systeminstruction;
            }

            $enabled = true;
            if (method_exists(ai_manager::class, 'is_action_enabled')) {
                $enabled = (bool)ai_manager::is_action_enabled($component, $actionclass);
            }

            $actionconfig[$actionclass] = [
                'enabled' => $enabled,
                'settings' => $settings,
            ];
        }

        return $actionconfig;
    }
}
