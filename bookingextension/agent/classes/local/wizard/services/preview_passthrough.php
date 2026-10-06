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
 * Generic, domain-agnostic preview passthrough.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\conversation_store;

/**
 * Resolves the user-facing preview for a webservice response purely from skill-provided data.
 *
 * A skill that wants a preview exposes get_result_preview(), which the executor invokes on the raw
 * result and attaches to the result as a self-contained data block under the 'preview' key:
 *
 *   [
 *     'type'      => 'booking_option',  // free, skill-defined string (for client dispatch)
 *     'html'      => '<div>…</div>',     // optional, server-rendered HTML (trusted plugin output)
 *     'js'        => 'require([...])…',   // optional, render-time JS (trusted, collected via get_end_code);
 *                                         // the client injects html + runs js via core/templates
 *     'js_module' => 'mod_x/preview',    // optional AMD module name for client-side rendering
 *     'payload'   => [ … ],              // optional data handed to the js_module
 *     'replace'   => true,               // optional: this block shows the CURRENT STATE of one object and
 *                                         // supersedes the accumulated same-type block instead of being
 *                                         // appended to it (e.g. a report re-rendered after each edit)
 *   ]
 *
 * This service never calls into skills and never renders anything: it only collects the precomputed
 * 'preview' blocks from the results and, across a multi-step confirm chain, concatenates HTML of the
 * same type (unless the new block sets 'replace'). Because the block is computed before result
 * sanitization (in the executor), previews no longer depend on any per-skill result field surviving
 * the sanitizer's whitelist.
 *
 * Besides the executed-result path (source A; source B is proposed_action_preview) this service
 * also carries the CLARIFICATION preview channel (source C): a skill's preflight issue may ship
 * the same self-contained preview block, which travels via thread metadata
 * (stash_clarification_preview / consume_clarification_preview_json) because a preflight
 * clarification never reaches the executor and its result dict is rebuilt on the way out.
 */
class preview_passthrough {
    /**
     * Thread-metadata key holding a preflight-clarification preview until the response ships.
     *
     * Why metadata and not a key on the result dict: between the decision service (where a
     * preflight clarification is built) and the webservice response, the result array is
     * rebuilt several times (interpreter, loop finalization, synchronizer message swap) —
     * any extra key would have to survive every hop. The thread metadata handoff sidesteps
     * that entirely and mirrors how executed-result previews already accumulate across a
     * confirm chain ('_confirm_previews').
     */
    private const CLARIFICATION_PREVIEW_KEY = '_clarification_preview';

    /**
     * Seconds a stashed clarification preview stays usable.
     *
     * Normally the stash is written and consumed within the same request. The TTL only
     * guards the corner case where a request errors out between the two points (the
     * endpoint never reaches the consume), so a leftover cannot attach itself to an
     * unrelated clarification turns later.
     */
    private const CLARIFICATION_PREVIEW_TTL = 300;

    /**
     * Extract the first usable preview block from preflight issues (source C).
     *
     * Skill contract: a 'needs_clarification' preflight issue MAY carry a self-contained
     * 'preview' block — the same shape get_result_preview() returns
     * ({type, html?, js?, js_module?, payload?}). The engine never inspects the block
     * beyond requiring a non-empty 'type'; it stays a pure data passthrough.
     *
     * @param mixed[] $issues Structured preflight issues.
     * @return array|null The first preview block carried by a clarification issue, or null.
     */
    public static function extract_clarification_preview_from_issues(array $issues): ?array {
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            if (trim((string)($issue['severity'] ?? '')) !== 'needs_clarification') {
                continue;
            }
            $preview = $issue['preview'] ?? null;
            if (is_array($preview) && trim((string)($preview['type'] ?? '')) !== '') {
                return $preview;
            }
        }

        return null;
    }

    /**
     * Stash a clarification preview for the same-turn response builder.
     *
     * Written by the decision service when a preflight clarification carries a preview;
     * consumed (and always cleared) by ai_send_message / ai_confirm_run via
     * consume_clarification_preview_json().
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param array $preview Self-contained preview block ({type, html?, js?, js_module?, payload?}).
     * @return void
     */
    public static function stash_clarification_preview(
        conversation_store $store,
        int $threadid,
        array $preview
    ): void {
        $store->set_thread_metadata_value($threadid, self::CLARIFICATION_PREVIEW_KEY, [
            'preview' => $preview,
            'stashedat' => time(),
        ]);
    }

    /**
     * Consume the stashed clarification preview for this response (read + ALWAYS clear).
     *
     * Precedence and semantics:
     * - the stash is cleared on every call, whatever the response type — a stale preview
     *   must never leak into a later turn;
     * - an already-resolved preview (executed results / proposed actions) always wins:
     *   a non-empty $previewjson is returned unchanged;
     * - the stash is attached only when the turn actually ends as a 'clarification' and
     *   the stash is fresh (see CLARIFICATION_PREVIEW_TTL).
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param string $responsetype Final response_type of this turn.
     * @param string $previewjson Preview JSON resolved so far ('' when none).
     * @return string The preview JSON to ship ('' when none).
     */
    public static function consume_clarification_preview_json(
        conversation_store $store,
        int $threadid,
        string $responsetype,
        string $previewjson
    ): string {
        $stored = $store->get_thread_metadata_value($threadid, self::CLARIFICATION_PREVIEW_KEY);
        if ($stored !== null) {
            $store->set_thread_metadata_value($threadid, self::CLARIFICATION_PREVIEW_KEY, null);
        }

        if (trim($previewjson) !== '') {
            return $previewjson;
        }
        if ($responsetype !== 'clarification' || !is_array($stored)) {
            return '';
        }

        $preview = $stored['preview'] ?? null;
        $stashedat = (int)($stored['stashedat'] ?? 0);
        if (!is_array($preview) || $stashedat < time() - self::CLARIFICATION_PREVIEW_TTL) {
            return '';
        }

        $encoded = json_encode($preview);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Resolve the preview JSON for a webservice response from executed skill results.
     *
     * @param mixed[] $results Executed skill results (each may carry a precomputed 'preview').
     * @param int $threadid
     * @param string $metadatakey Thread-metadata key used to accumulate previews across a chain.
     * @param mixed[] $loopresults Per-step loop results (each entry: {..., results: [...]}).
     * @return string JSON-encoded preview block, or '' when there is none.
     */
    public static function resolve_preview_json(
        array $results,
        int $threadid,
        string $metadatakey = '_confirm_previews',
        array $loopresults = []
    ): string {
        $preview = self::extract_first_preview($results, $loopresults);

        $store = new conversation_store();
        $stored = $store->get_thread_metadata_value($threadid, $metadatakey);
        $accumulated = is_array($stored) ? $stored : [];

        if ($preview !== null) {
            $preview = self::merge_with_accumulated($accumulated, $preview);
            $store->set_thread_metadata_value($threadid, $metadatakey, $preview);
        } else {
            $preview = !empty($accumulated) ? $accumulated : null;
        }

        if ($preview === null) {
            return '';
        }

        $encoded = json_encode($preview);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Return the first precomputed preview block, or null.
     *
     * Scans the terminal top-level results first (e.g. a confirmed mutation), then the loop-step
     * results (read skills such as get_option_details/search_options/explain_docs execute as internal
     * loop steps, so their result lives in loop_results, not in the terminal `results`). Most recent
     * loop step wins.
     *
     * @param mixed[] $results Terminal top-level results.
     * @param mixed[] $loopresults Per-step loop results (each entry: {..., results: [...]}).
     * @return array|null
     */
    private static function extract_first_preview(array $results, array $loopresults): ?array {
        $preview = self::first_preview_in_entries($results);
        if ($preview !== null) {
            return $preview;
        }

        // Most recent loop step first.
        for ($i = count($loopresults) - 1; $i >= 0; $i--) {
            $step = $loopresults[$i];
            if (!is_array($step)) {
                continue;
            }
            $preview = self::first_preview_in_entries((array)($step['results'] ?? []));
            if ($preview !== null) {
                return $preview;
            }
        }

        return null;
    }

    /**
     * Return the first precomputed preview block within a flat list of result entries.
     *
     * Each entry may carry a self-contained 'preview' block attached by the executor at execution
     * time. This service does not call into skills; it only forwards the precomputed data.
     *
     * @param mixed[] $entries
     * @return array|null
     */
    private static function first_preview_in_entries(array $entries): ?array {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $preview = $entry['preview'] ?? null;
            if (is_array($preview) && trim((string)($preview['type'] ?? '')) !== '') {
                return $preview;
            }
        }

        return null;
    }

    /**
     * Combine a freshly produced preview with the accumulated one (same chain, same type).
     *
     * Type-agnostic: for HTML-based previews of the same type, the new HTML is appended to the
     * accumulated HTML so a multi-step chain shows every affected item.
     *
     * A block carrying `replace => true` is a state preview: it renders the current state of one
     * object (the same object the chain keeps editing), so appending would show stale copies. Such
     * a block supersedes the accumulator as a whole (HTML, JS and payload). The flag is per block:
     * a later block without it accumulates again on top of the replaced state.
     *
     * @param array $accumulated
     * @param array $preview
     * @return array
     */
    private static function merge_with_accumulated(array $accumulated, array $preview): array {
        if (!empty($preview['replace'])) {
            return $preview;
        }

        $sametype = isset($accumulated['type'], $preview['type'])
            && (string)$accumulated['type'] === (string)$preview['type'];
        if (!$sametype) {
            return $preview;
        }

        $oldhtml = isset($accumulated['html']) && is_string($accumulated['html']) ? $accumulated['html'] : '';
        $newhtml = isset($preview['html']) && is_string($preview['html']) ? $preview['html'] : '';
        if ($oldhtml !== '' && $newhtml !== '' && strpos($newhtml, $oldhtml) === false) {
            $preview['html'] = $oldhtml . $newhtml;
        }

        // Concatenate render-time JS the same way as HTML, so a multi-step chain that accumulates
        // several HTML blocks also accumulates each block's initialisation JS.
        $oldjs = isset($accumulated['js']) && is_string($accumulated['js']) ? $accumulated['js'] : '';
        $newjs = isset($preview['js']) && is_string($preview['js']) ? $preview['js'] : '';
        if ($oldjs !== '' && strpos($newjs, $oldjs) === false) {
            $preview['js'] = trim($oldjs . "\n" . $newjs);
        }

        // Merge payloads (especially list of optionids) across the confirm chain.
        $oldpayload = isset($accumulated['payload']) && is_array($accumulated['payload']) ? $accumulated['payload'] : [];
        $newpayload = isset($preview['payload']) && is_array($preview['payload']) ? $preview['payload'] : [];
        if (!empty($oldpayload) && !empty($newpayload)) {
            $mergedpayload = $newpayload;
            foreach ($oldpayload as $key => $oldval) {
                if (isset($newpayload[$key])) {
                    if (is_array($oldval) && is_array($newpayload[$key])) {
                        $mergedpayload[$key] = array_values(array_unique(array_merge($oldval, $newpayload[$key])));
                    }
                } else {
                    $mergedpayload[$key] = $oldval;
                }
            }
            $preview['payload'] = $mergedpayload;
        } else if (!empty($oldpayload)) {
            $preview['payload'] = $oldpayload;
        }

        return $preview;
    }
}
