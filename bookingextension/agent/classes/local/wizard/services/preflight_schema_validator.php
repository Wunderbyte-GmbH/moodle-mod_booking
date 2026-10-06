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

/**
 * Validates command payloads against the central command schema.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_schema_validator {
    /** @var array|null Cached decoded schema. */
    private static ?array $cachedschema = null;

    /**
     * Validate one command array against the command schema.
     *
     * @param array $command
     * @return array{valid:bool,error_class:string,issue_codes:string[],errors:string[]}
     */
    public function validate(array $command): array {
        $schema = $this->get_schema();
        if (empty($schema)) {
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => ['SCHEMA_UNAVAILABLE'],
                'errors' => ['Command schema could not be loaded.'],
            ];
        }

        $errors = [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $field) {
            $field = trim((string)$field);
            if ($field === '') {
                continue;
            }
            if (!array_key_exists($field, $command)) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $field) ?? '';
                $safe = $safe !== '' ? $safe : 'unknown';
                $errors[] = 'Missing required field "' . $safe . '".';
            }
        }

        if (array_key_exists('skill', $command)) {
            $skill = trim((string)($command['skill'] ?? ''));
            if ($skill === '') {
                $errors[] = 'Field "skill" must be a non-empty string.';
            }
        }

        if (array_key_exists('input', $command) && !is_array($command['input'])) {
            $errors[] = 'Field "input" must be an object.';
        }

        if (array_key_exists('version', $command)) {
            $version = $command['version'];
            $isvalidversion = is_int($version)
                || (is_string($version) && preg_match('/^\d+$/', $version) === 1);
            if (!$isvalidversion || (int)$version <= 0) {
                $errors[] = 'Field "version" must be an integer > 0.';
            }
        }

        if (array_key_exists('depends_on', $command)) {
            $dependson = $command['depends_on'];
            if (!is_array($dependson)) {
                $errors[] = 'Field "depends_on" must be an array.';
            } else {
                foreach ($dependson as $idx => $dependency) {
                    if (trim((string)$dependency) === '') {
                        $errors[] = 'Field "depends_on[' . $idx . ']" must be a non-empty string.';
                    }
                }
            }
        }

        if (array_key_exists('output_bindings', $command)) {
            if (!is_array($command['output_bindings'])) {
                $errors[] = 'Field "output_bindings" must be an object.';
            } else {
                foreach ((array)$command['output_bindings'] as $field => $bindingref) {
                    if (trim((string)$field) === '' || trim((string)$bindingref) === '') {
                        $errors[] = 'Field "output_bindings" must contain non-empty string pairs.';
                        break;
                    }
                }
            }
        }

        if (array_key_exists('produced_outputs', $command) && !is_array($command['produced_outputs'])) {
            $errors[] = 'Field "produced_outputs" must be an object.';
        }

        if (array_key_exists('spawn_commands', $command)) {
            $spawncommands = $command['spawn_commands'];
            if (!is_array($spawncommands)) {
                $errors[] = 'Field "spawn_commands" must be an array.';
            } else {
                foreach ($spawncommands as $idx => $spawncommand) {
                    if (!is_array($spawncommand)) {
                        $errors[] = 'Field "spawn_commands[' . $idx . ']" must be an object.';
                        continue;
                    }
                    if (trim((string)($spawncommand['skill'] ?? '')) === '') {
                        $errors[] = 'Field "spawn_commands[' . $idx . '].skill" must be a non-empty string.';
                    }
                    if (array_key_exists('input', $spawncommand) && !is_array($spawncommand['input'])) {
                        $errors[] = 'Field "spawn_commands[' . $idx . '].input" must be an object.';
                    }
                    if (
                        array_key_exists('output_bindings', $spawncommand)
                        && !is_array($spawncommand['output_bindings'])
                    ) {
                        $errors[] = 'Field "spawn_commands[' . $idx . '].output_bindings" must be an object.';
                    }
                }
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => ['SCHEMA_ERROR'],
                'errors' => array_values(array_unique($errors)),
            ];
        }

        return [
            'valid' => true,
            'error_class' => '',
            'issue_codes' => [],
            'errors' => [],
        ];
    }

    /**
     * Load and cache the command schema.
     *
     * @return array
     */
    private function get_schema(): array {
        if (self::$cachedschema !== null) {
            return self::$cachedschema;
        }

        $path = __DIR__ . '/../config/command_schema.json';
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            self::$cachedschema = [];
            return self::$cachedschema;
        }

        $decoded = json_decode($raw, true);
        self::$cachedschema = is_array($decoded) ? $decoded : [];
        return self::$cachedschema;
    }
}
