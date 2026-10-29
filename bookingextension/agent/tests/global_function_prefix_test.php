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
 * Guard test for the frankenstyle prefix of global functions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Invariant guard: every function the plugin declares in the global namespace starts with the component name.
 *
 * Moodle loads the code of core and all plugins into one PHP process, so an unprefixed global function can
 * collide with a function of the same name elsewhere ("Cannot redeclare"). The frankenstyle rule therefore
 * requires the component name as prefix. phpcs (moodle standard) does not check this, so this test does
 * (Wunderbyte-GmbH/moodle-local_wizard#4).
 *
 * @package    bookingextension_agent
 * @category   test
 * @coversNothing
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class global_function_prefix_test extends \basic_testcase {
    /** @var string The component whose prefix every global function must carry. */
    private const COMPONENT = 'bookingextension_agent';

    /** @var string[] Top-level directories that are not scanned: bundled libraries and development tools. */
    private const SKIPPED_DIRS = ['node_modules', 'thirdparty', 'tools'];

    /**
     * Every global function of the plugin starts with the component name (or is a Moodle xmldb callback).
     */
    public function test_global_functions_carry_the_component_prefix(): void {
        $root = \core_component::get_component_directory(self::COMPONENT);
        $this->assertNotEmpty($root, 'The plugin directory must be resolvable.');

        $allowedprefixes = [self::COMPONENT . '_', 'xmldb_' . self::COMPONENT . '_'];
        $offenders = [];
        $scannedfiles = 0;
        foreach (self::plugin_php_files($root) as $relpath => $path) {
            $scannedfiles++;
            foreach (self::global_function_declarations((string)file_get_contents($path)) as [$name, $line]) {
                if (!self::has_allowed_prefix($name, $allowedprefixes)) {
                    $offenders[] = "{$relpath}:{$line} {$name}()";
                }
            }
        }

        $this->assertGreaterThan(0, $scannedfiles, 'The scan must see the plugin files.');
        $this->assertSame(
            [],
            $offenders,
            "Global functions must start with '" . self::COMPONENT . "_' (frankenstyle), or live in a class."
        );
    }

    /**
     * The scanner reports named global functions only: no methods, closures, imports or namespaced functions.
     *
     * Keeps the guard above from passing vacuously when the scanner misses or misreads a declaration.
     */
    public function test_scanner_reports_only_named_global_functions(): void {
        $code = <<<'PHP'
<?php
function plain_global() {}
function &by_reference() {}
use function other\imported;
$closure = function () {};
$arrow = fn() => 1;
echo "{$closure}";
class sample_class {
    public function sample_method() {}
}
$anonymous = new class {
    public function anonymous_method() {}
};
interface sample_interface {
    public function interface_method();
}
$name = sample_class::class;
if (!function_exists('conditional_global')) {
    function conditional_global() {}
}
PHP;
        $this->assertSame(
            [['plain_global', 2], ['by_reference', 3], ['conditional_global', 19]],
            self::global_function_declarations($code)
        );

        $namespaced = "<?php\nnamespace some\\space;\n\nfunction inside_namespace() {}\n";
        $this->assertSame([], self::global_function_declarations($namespaced));
    }

    /**
     * Collect the PHP files of the plugin, keyed by plugin-relative path.
     *
     * @param string $root Plugin root directory.
     * @return array<string, string> Relative path => absolute path, sorted by relative path.
     */
    private static function plugin_php_files(string $root): array {
        $root = rtrim($root, '/');
        $filter = static function (SplFileInfo $file, string $path) use ($root): bool {
            if (!$file->isDir()) {
                return $file->getExtension() === 'php';
            }
            $relpath = substr($path, strlen($root) + 1);
            return !str_starts_with($file->getFilename(), '.') && !in_array($relpath, self::SKIPPED_DIRS, true);
        };
        $directory = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator($directory, $filter));

        $files = [];
        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    /**
     * Find named functions declared in the global namespace, outside any class, interface, trait or enum.
     *
     * Structural scan with PHP's own tokenizer. Braced namespace blocks are not used in Moodle and are not modelled.
     *
     * @param string $code PHP source code.
     * @return array<int, array{0: string, 1: int}> Pairs of function name and line, in source order.
     */
    private static function global_function_declarations(string $code): array {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $namespace = '';
        $depth = 0;
        $classdepths = [];
        $classpending = false;
        $declarations = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                    if ($classpending) {
                        $classdepths[] = $depth;
                        $classpending = false;
                    }
                } else if ($token === '}') {
                    if (!empty($classdepths) && end($classdepths) === $depth) {
                        array_pop($classdepths);
                    }
                    $depth--;
                }
                continue;
            }

            switch ($token[0]) {
                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    // Interpolation braces in strings are closed by a plain '}'.
                    $depth++;
                    break;
                case T_NAMESPACE:
                    $namespace = self::read_namespace_name($tokens, $i);
                    break;
                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    if (!self::token_is($tokens, self::significant_index($tokens, $i, -1), T_DOUBLE_COLON)) {
                        // Not "::class": the next '{' opens a class-like body.
                        $classpending = true;
                    }
                    break;
                case T_FUNCTION:
                    if (!empty($classdepths) || $namespace !== '') {
                        break;
                    }
                    if (self::token_is($tokens, self::significant_index($tokens, $i, -1), T_USE)) {
                        // A "use function" import, not a declaration.
                        break;
                    }
                    $next = self::significant_index($tokens, $i, 1);
                    if ($next !== null && self::token_text($tokens[$next]) === '&') {
                        // Return by reference: "function &name()".
                        $next = self::significant_index($tokens, $next, 1);
                    }
                    if (self::token_is($tokens, $next, T_STRING)) {
                        $declarations[] = [$tokens[$next][1], $tokens[$next][2]];
                    }
                    break;
            }
        }

        return $declarations;
    }

    /**
     * Read the name of a namespace declaration that starts at the given token.
     *
     * @param array $tokens Token list.
     * @param int $index Index of the T_NAMESPACE token.
     * @return string Namespace name, '' for the global namespace.
     */
    private static function read_namespace_name(array $tokens, int $index): string {
        $name = '';
        $count = count($tokens);
        for ($i = $index + 1; $i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{'; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $tokens[$i][1];
            }
        }

        return $name;
    }

    /**
     * Find the nearest token before or after the given index that is not whitespace or a comment.
     *
     * @param array $tokens Token list.
     * @param int $index Start index (excluded).
     * @param int $direction -1 for backwards, 1 for forwards.
     * @return int|null Index of that token, or null when there is none.
     */
    private static function significant_index(array $tokens, int $index, int $direction): ?int {
        $count = count($tokens);
        for ($i = $index + $direction; $i >= 0 && $i < $count; $i += $direction) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }

        return null;
    }

    /**
     * Whether the token at the given index has the given token type.
     *
     * @param array $tokens Token list.
     * @param int|null $index Token index, or null for "no token".
     * @param int $type Token type constant (T_...).
     * @return bool True when the token exists and has that type.
     */
    private static function token_is(array $tokens, ?int $index, int $type): bool {
        return $index !== null && is_array($tokens[$index]) && $tokens[$index][0] === $type;
    }

    /**
     * The source text of a token.
     *
     * @param array|string $token The token.
     * @return string The text.
     */
    private static function token_text($token): string {
        return is_array($token) ? $token[1] : $token;
    }

    /**
     * Whether a function name starts with one of the allowed prefixes.
     *
     * @param string $name Function name.
     * @param string[] $prefixes Allowed prefixes.
     * @return bool True when the name carries an allowed prefix.
     */
    private static function has_allowed_prefix(string $name, array $prefixes): bool {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
