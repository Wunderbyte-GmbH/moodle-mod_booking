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
 * Viewer for the markdown documentation shipped in the docs/ folder of the plugin.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\documentation;

use moodle_exception;
use moodle_url;

/**
 * Resolves, renders and rewrites the markdown documentation shipped in docs/.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class documentation_viewer {
    /** @var string Markdown page, rendered by the viewer page. */
    public const TYPE_MARKDOWN = 'markdown';

    /** @var string Image, served inline. */
    public const TYPE_IMAGE = 'image';

    /** @var string Other allowed file, served as download. */
    public const TYPE_DOWNLOAD = 'download';

    /** @var array Extensions served inline as images. */
    private const IMAGEEXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];

    /** @var array Extensions served as download. */
    private const DOWNLOADEXTENSIONS = ['csv'];

    /**
     * Absolute path of the docs root directory.
     *
     * @return string
     */
    public static function docs_root(): string {
        global $CFG;
        return $CFG->dirroot . '/mod/booking/docs';
    }

    /**
     * Validate a path relative to the docs root and resolve it to an absolute path.
     *
     * Only files inside the docs folder with an allowed extension pass, everything
     * else (traversal attempts, missing files, other file types) throws.
     *
     * @param string $relpath path relative to the docs root, e.g. "user/README.md"
     * @return array ['path' => absolute path, 'type' => one of the TYPE_ constants]
     * @throws moodle_exception
     */
    public static function resolve(string $relpath): array {
        $normalized = self::normalize_path($relpath);
        if ($normalized === null || $normalized === '') {
            throw new moodle_exception('invaliddocumentationfile', 'mod_booking');
        }

        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if ($extension === 'md') {
            $type = self::TYPE_MARKDOWN;
        } else if (in_array($extension, self::IMAGEEXTENSIONS, true)) {
            $type = self::TYPE_IMAGE;
        } else if (in_array($extension, self::DOWNLOADEXTENSIONS, true)) {
            $type = self::TYPE_DOWNLOAD;
        } else {
            throw new moodle_exception('invaliddocumentationfile', 'mod_booking');
        }

        $docsroot = realpath(self::docs_root());
        $path = realpath($docsroot . '/' . $normalized);
        // Defense in depth: even a path that slipped through normalization must resolve into docs/.
        if (
            $docsroot === false
            || $path === false
            || !is_file($path)
            || strpos($path, $docsroot . DIRECTORY_SEPARATOR) !== 0
        ) {
            throw new moodle_exception('invaliddocumentationfile', 'mod_booking');
        }

        return ['path' => $path, 'type' => $type];
    }

    /**
     * Render a markdown documentation page to HTML.
     *
     * @param string $relpath path relative to the docs root, must resolve to a markdown file
     * @return array ['html' => rendered page, 'title' => first heading or filename]
     * @throws moodle_exception
     */
    public static function render_page(string $relpath): array {
        $resolved = self::resolve($relpath);
        if ($resolved['type'] !== self::TYPE_MARKDOWN) {
            throw new moodle_exception('invaliddocumentationfile', 'mod_booking');
        }

        $markdown = file_get_contents($resolved['path']);
        if (preg_match('~^#\s+(.+)$~m', $markdown, $matches)) {
            $title = trim($matches[1]);
        } else {
            $title = basename($resolved['path']);
        }

        $html = markdown_to_html($markdown);
        $reldir = dirname(self::normalize_path($relpath));
        $html = self::rewrite_html($html, $reldir === '.' ? '' : $reldir);

        return ['html' => $html, 'title' => $title];
    }

    /**
     * Rewrite relative links and images of a rendered page to viewer URLs.
     *
     * External links, anchors and absolute paths stay untouched. Tables and images
     * get bootstrap classes, as markdown output comes without any.
     *
     * @param string $html rendered markdown
     * @param string $reldir directory of the current page relative to the docs root, '' for the root
     * @return string
     */
    public static function rewrite_html(string $html, string $reldir): string {
        $html = preg_replace_callback(
            '~(<(?:a|img)\s[^>]*(?:href|src)=")([^"]+)(")~i',
            function (array $matches) use ($reldir): string {
                return $matches[1] . self::rewrite_target($matches[2], $reldir) . $matches[3];
            },
            $html
        );
        $html = str_replace('<table>', '<table class="table table-bordered table-striped w-auto">', $html);
        return preg_replace('~<img(\s)~i', '<img class="img-fluid"\1', $html);
    }

    /**
     * Rewrite a single link target to a viewer URL, if it points to a documentation file.
     *
     * @param string $target the href/src value found in the rendered page
     * @param string $reldir directory of the current page relative to the docs root, '' for the root
     * @return string
     */
    private static function rewrite_target(string $target, string $reldir): string {
        // Leave external targets, anchors and absolute paths untouched.
        if (preg_match('~^([a-z][a-z0-9+.-]*:|//|#|/)~i', $target)) {
            return $target;
        }

        $fragment = '';
        if (($hashpos = strpos($target, '#')) !== false) {
            $fragment = substr($target, $hashpos);
            $target = substr($target, 0, $hashpos);
        }
        if ($target === '') {
            return $fragment;
        }

        // Directory links point to the README of that directory.
        if (substr($target, -1) === '/') {
            $target .= 'README.md';
        }

        $normalized = self::normalize_path(($reldir === '' ? '' : $reldir . '/') . $target);
        // A link escaping the docs folder cannot be resolved, send it to the index instead.
        if ($normalized === null || $normalized === '') {
            $normalized = 'README.md';
        }

        $url = new moodle_url('/mod/booking/documentation.php', ['file' => $normalized]);
        return $url->out(false) . $fragment;
    }

    /**
     * Collapse "." and ".." segments of a relative path without touching the filesystem.
     *
     * @param string $path relative path, may contain "." and ".." segments
     * @return string|null normalized path, or null if the path escapes the root
     */
    public static function normalize_path(string $path): ?string {
        $result = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($result)) {
                    return null;
                }
                array_pop($result);
                continue;
            }
            $result[] = $segment;
        }
        return implode('/', $result);
    }
}
