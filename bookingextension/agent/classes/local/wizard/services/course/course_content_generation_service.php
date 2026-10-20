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

namespace bookingextension_agent\local\wizard\services\course;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;

/**
 * Generates course scaffold content (outline + chapter pages) for scaffold_course_content.
 *
 * Mirrors question_generation_service: deterministic prompts through
 * llm_call_service::invoke_for_context(), one outline call plus one call per chapter page,
 * so each response stays small enough to survive output limits. The target language is an
 * explicit data parameter (outputlang) — never detected from the topic text.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_content_generation_service {
    /** Maximum chapters a scaffold may request. */
    public const MAX_CHAPTERS = 8;

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Generate the course outline: welcome/overview texts, chapter titles, summary text.
     *
     * @param int    $threadid  Thread id (for debug logging; 0 when unknown).
     * @param int    $contextid Context id the LLM call runs against.
     * @param int    $userid
     * @param string $topic     The user's topic, passed through verbatim.
     * @param int    $chapters  Number of content chapters (1..MAX_CHAPTERS).
     * @param string $lang      Output language code, e.g. de or en.
     * @return array{success:bool,outline:array,error:string}
     */
    public function generate_outline(
        int $threadid,
        int $contextid,
        int $userid,
        string $topic,
        int $chapters,
        string $lang
    ): array {
        $chapters = max(1, min(self::MAX_CHAPTERS, $chapters));
        $langline = $lang !== '' ? $lang : 'the language of the topic';

        $prompt = "You are drafting the structure of a small self-study course.\n"
            . "Topic (verbatim, do not reinterpret): {$topic}\n"
            . "Number of content chapters: {$chapters}\n"
            . "Write ALL text in: {$langline}\n\n"
            . "Return EXACTLY ONE JSON object, no markdown fences, no prose, with these keys:\n"
            . "{\n"
            . "  \"welcometitle\": string,        // short welcome section heading\n"
            . "  \"welcomehtml\": string,         // 2-4 sentence welcome text as simple HTML (<p>)\n"
            . "  \"overviewhtml\": string,        // overview page: what the course covers, learning goals,"
            . " structure; simple HTML with <h3>/<p>/<ul>\n"
            . "  \"chapters\": [ { \"title\": string } ],  // exactly {$chapters} chapter titles, ordered\n"
            . "  \"summarytitle\": string,        // closing section heading\n"
            . "  \"summaryhtml\": string          // closing page: recap of the key points; simple HTML\n"
            . "}\n"
            . "Interesting and factual; no invented citations; no external links or images.";

        $call = (new llm_call_service($this->store))->invoke_for_context(
            $threadid,
            $contextid,
            $userid,
            'scaffold_outline',
            $this->mask($threadid, $prompt),
            llm_call_service::content_action_class()
        );
        $fallback = !empty($call['fallback']);
        if (empty($call['success'])) {
            return [
                'success' => false,
                'outline' => [],
                'error' => (string)($call['errormessage'] ?? 'model call failed'),
                'fallback' => $fallback,
            ];
        }

        $outline = $this->extract_json($this->unmask($threadid, (string)($call['rawcontent'] ?? '')));
        $chapterlist = array_values(array_filter(array_map(
            static fn($chapter): string => trim((string)(is_array($chapter) ? ($chapter['title'] ?? '') : $chapter)),
            (array)($outline['chapters'] ?? [])
        )));
        if ($outline === null || empty($chapterlist)) {
            return [
                'success' => false,
                'outline' => [],
                'error' => 'The model did not return a valid outline.',
                'fallback' => $fallback,
            ];
        }
        $outline['chapters'] = array_slice($chapterlist, 0, $chapters);

        return ['success' => true, 'outline' => $outline, 'error' => '', 'fallback' => $fallback];
    }

    /**
     * Generate one chapter page as simple HTML.
     *
     * @param int    $threadid
     * @param int    $contextid
     * @param int    $userid
     * @param string $topic        The course topic, verbatim.
     * @param string $chaptertitle This chapter's title.
     * @param int    $index        1-based chapter number.
     * @param int    $total        Total chapter count.
     * @param string $lang         Output language code.
     * @return array{success:bool,html:string,error:string}
     */
    public function generate_chapter_html(
        int $threadid,
        int $contextid,
        int $userid,
        string $topic,
        string $chaptertitle,
        int $index,
        int $total,
        string $lang
    ): array {
        $langline = $lang !== '' ? $lang : 'the language of the topic';

        $prompt = "You are writing chapter {$index} of {$total} of a small self-study course.\n"
            . "Course topic (verbatim): {$topic}\n"
            . "Chapter title: {$chaptertitle}\n"
            . "Write ALL text in: {$langline}\n\n"
            . "Return ONLY the chapter body as simple HTML (no <html>/<head>/<body> wrapper, no markdown "
            . "fences): 600-1000 words, structured with <h3> sub-headings and <p> paragraphs, one short "
            . "key-takeaway box as <blockquote> at the end. Factual and engaging; no invented citations; "
            . "no external links or images.";

        $call = (new llm_call_service($this->store))->invoke_for_context(
            $threadid,
            $contextid,
            $userid,
            'scaffold_chapter',
            $this->mask($threadid, $prompt),
            llm_call_service::content_action_class()
        );
        $fallback = !empty($call['fallback']);
        if (empty($call['success'])) {
            return [
                'success' => false,
                'html' => '',
                'error' => (string)($call['errormessage'] ?? 'model call failed'),
                'fallback' => $fallback,
            ];
        }

        $html = trim($this->unmask($threadid, (string)($call['rawcontent'] ?? '')));
        $html = preg_replace('/^\x60{3}(?:html)?\s*|\s*\x60{3}$/m', '', $html) ?? $html;
        $html = trim($html);

        return [
            'success' => $html !== '',
            'html' => $html,
            'error' => $html === '' ? 'The model returned no chapter content.' : '',
            'fallback' => $fallback,
        ];
    }

    /**
     * Mask personal data in a content prompt before it leaves the site (AI privacy mode, thread token map).
     *
     * @param int $threadid
     * @param string $prompt
     * @return string
     */
    private function mask(int $threadid, string $prompt): string {
        if ($threadid <= 0) {
            return $prompt;
        }
        return (string)(new privacy_anonymizer($this->store))->anonymize_value_for_llm($threadid, $prompt);
    }

    /**
     * Resolve the thread's tokens in generated content before it is stored in the course.
     *
     * @param int $threadid
     * @param string $content
     * @return string
     */
    private function unmask(int $threadid, string $content): string {
        if ($threadid <= 0) {
            return $content;
        }
        // The executor's resolver: originals without the display marker, this text is stored in the course.
        $resolved = (new privacy_anonymizer($this->store))->deanonymize_command_input($threadid, ['text' => $content]);
        return (string)($resolved['text'] ?? $content);
    }

    /**
     * Extract one JSON object from a raw model response (tolerates markdown fences).
     *
     * @param string $raw
     * @return array|null
     */
    private function extract_json(string $raw): ?array {
        $raw = trim($raw);
        $raw = preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/m', '', $raw) ?? $raw;
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }
}
