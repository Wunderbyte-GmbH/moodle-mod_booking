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
 * Normalizes spawn command contracts and resolves output bindings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spawn_contract_service {
    /**
     * Normalize skill result for spawn contract keys.
     *
     * @param string $skillname
     * @param array $result
     * @return array
     */
    public function normalize_skill_result(string $skillname, array $result): array {
        $result['produced_outputs'] = $this->normalize_produced_outputs($skillname, $result);
        $result['spawn_commands'] = $this->normalize_spawn_commands((array)($result['spawn_commands'] ?? []));
        return $result;
    }

    /**
     * Resolve output bindings for one child command.
     *
     * @param array $input
     * @param array $outputbindings
     * @param array $availableoutputs
     * @return array{input:array,errors:string[]}
     */
    public function apply_output_bindings(array $input, array $outputbindings, array $availableoutputs): array {
        $errors = [];

        foreach ($outputbindings as $field => $bindingref) {
            $field = trim((string)$field);
            $bindingref = trim((string)$bindingref);
            if ($field === '' || $bindingref === '') {
                continue;
            }

            $normalizedref = $this->normalize_binding_reference($bindingref);
            if ($normalizedref === '') {
                $errors[] = 'Invalid output binding reference: ' . $bindingref;
                continue;
            }

            if (!array_key_exists($normalizedref, $availableoutputs)) {
                $errors[] = 'Output binding source not found: ' . $bindingref;
                continue;
            }

            $input[$field] = $availableoutputs[$normalizedref];
        }

        return [
            'input' => $input,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * Normalize spawn command list to deterministic command records.
     *
     * @param mixed[] $spawncommands
     * @return array[]
     */
    public function normalize_spawn_commands(array $spawncommands): array {
        $normalized = [];

        foreach ($spawncommands as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $skill = trim((string)($entry['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            $input = is_array($entry['input'] ?? null) ? (array)$entry['input'] : [];
            $outputbindings = is_array($entry['output_bindings'] ?? null)
                ? (array)$entry['output_bindings']
                : [];
            $dependson = array_values(array_filter(array_map(
                'strval',
                is_array($entry['depends_on'] ?? null) ? (array)$entry['depends_on'] : []
            )));

            $normalized[] = [
                'skill' => $skill,
                'version' => max(1, (int)($entry['version'] ?? 1)),
                'input' => $input,
                'output_bindings' => $outputbindings,
                'depends_on' => array_values(array_unique($dependson)),
            ];
        }

        return $normalized;
    }

    /**
     * Build available output map for binding resolution.
     *
     * @param string $skillname
     * @param array $result
     * @return array
     */
    private function normalize_produced_outputs(string $skillname, array $result): array {
        $raw = is_array($result['produced_outputs'] ?? null) ? (array)$result['produced_outputs'] : [];
        $outputs = [];

        foreach ($raw as $key => $value) {
            $name = trim((string)$key);
            if ($name === '') {
                continue;
            }
            $outputs[$name] = $value;
            $outputs['parent.' . $name] = $value;
            if ($skillname !== '') {
                $outputs[$skillname . '.' . $name] = $value;
            }
        }

        return $outputs;
    }

    /**
     * Normalize accepted output-binding reference formats.
     *
     * @param string $reference
     * @return string
     */
    private function normalize_binding_reference(string $reference): string {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }

        if (str_starts_with($reference, 'outputs.')) {
            return 'parent.' . substr($reference, 8);
        }

        if (str_starts_with($reference, 'parent.')) {
            return $reference;
        }

        return $reference;
    }
}
