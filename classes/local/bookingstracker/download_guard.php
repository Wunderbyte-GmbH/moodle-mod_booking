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

namespace mod_booking\local\bookingstracker;

use local_wunderbyte_table\local\exception\download_query_exception;
use local_wunderbyte_table\wunderbyte_table;
use moodle_exception;
use moodle_url;
use Throwable;

/**
 * Turns a failed download of a Bookings tracker table (download_report2.php) into a hint for the user.
 *
 * A download reads all rows of a table at once, while the page only reads the rows it shows. On big
 * tables the download can therefore fail although the page works: the database gives up on the query
 * (timeout, limit for temporary files) or PHP runs out of memory. The user cannot do anything about
 * the cause, but can make the download work by narrowing the table down with the search or the filters.
 *
 * So whenever a download fails in a way that is most likely caused by the amount of data, the user is
 * sent back to the page of the table, which shows the hint (see {@see self::PARAM}). The hint is passed
 * as a URL parameter and not as a session notification, because the export closes the session for
 * writing as soon as the download is requested. Every other error keeps its regular error page.
 *
 * "Most likely caused by the amount of data" means:
 * - PHP ran out of memory or time, or
 * - the database could not read the query of the table (not a query a column runs for a single row,
 *   see download_query_exception) and the table has at least {@see self::MINROWS} rows. With fewer
 *   rows, or if the rows cannot even be counted, the amount of data is no plausible cause: the query
 *   is rather broken.
 *
 * local_wunderbyte_table announces the file to the browser only once all rows are queried and
 * formatted. This is what makes the redirect possible: before that, nothing has been sent.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_guard {
    /** @var string URL parameter of report2.php that shows the hint. */
    public const PARAM = 'downloaderror';

    /** @var string URL parameter of report2.php with the number of rows of the table, if it could be counted. */
    public const PARAMROWS = 'downloadrows';

    /** @var int From this number of rows on, the amount of data is a plausible cause of a failed query. */
    public const MINROWS = 5000;

    /** @var int Bytes kept free, so the shutdown function can still run after PHP ran out of memory. */
    private const RESERVEDBYTES = 262144;

    /** @var string|null Memory reserve, released by the shutdown function. */
    private static ?string $reserve = null;

    /** @var string|null Page to send the user to after a fatal error, as a string: no memory is needed to build it later. */
    private static ?string $fatalredirect = null;

    /**
     * The page of the Bookings tracker that shows the table of a scope, with the hint about the failed download.
     *
     * @param string $scope scope of the table, e.g. instanceanswers
     * @param int $scopeid id the scope refers to (optiondateid, optionid, cmid, courseid or 0)
     * @param int|null $rows number of rows of the table, null if unknown
     * @return moodle_url|null null for tables that are not shown on report2.php
     */
    public static function return_url(string $scope, int $scopeid, ?int $rows = null): ?moodle_url {
        $params = match ($scope) {
            'system' => [],
            'systemanswers' => ['viewtype' => 'answers'],
            'course' => ['courseid' => $scopeid],
            'courseanswers' => ['courseid' => $scopeid, 'viewtype' => 'answers'],
            'instance' => ['cmid' => $scopeid],
            'instanceanswers' => ['cmid' => $scopeid, 'viewtype' => 'answers'],
            'option' => ['optionid' => $scopeid],
            'optiondate' => ['optiondateid' => $scopeid],
            default => null,
        };
        if ($params === null) {
            return null;
        }
        // Every scope but the whole site needs its id.
        if (!in_array($scope, ['system', 'systemanswers']) && empty($scopeid)) {
            return null;
        }
        $params[self::PARAM] = 1;
        if ($rows !== null) {
            $params[self::PARAMROWS] = $rows;
        }
        return new moodle_url('/mod/booking/report2.php', $params);
    }

    /**
     * The hint for the user.
     *
     * @param int|null $rows number of rows of the table, null if unknown
     * @return string
     */
    public static function hint(?int $rows = null): string {
        if (empty($rows)) {
            return get_string('bookingstrackerdownloadtoolarge', 'mod_booking');
        }
        return get_string('bookingstrackerdownloadtoolargerows', 'mod_booking', $rows);
    }

    /**
     * Whether an exception thrown during a download comes from the query of the table, i.e. from the
     * only query that reads all rows at once.
     *
     * local_wunderbyte_table gives the read error of that query a name of its own. Read errors of other
     * queries (e.g. the ones a column runs for every row) and all other exceptions do not depend on the
     * number of rows. With a version of local_wunderbyte_table that does not know the class yet, no
     * exception is recognised and every error keeps its regular error page.
     *
     * @param Throwable $exception
     * @return bool
     */
    public static function is_table_query_error(Throwable $exception): bool {
        return $exception instanceof download_query_exception;
    }

    /**
     * Whether the number of rows of a table makes the amount of data a plausible cause of a failed query.
     *
     * @param int|null $rows number of rows of the table, null if they could not be counted
     * @return bool
     */
    public static function is_plausible_rowcount(?int $rows): bool {
        // A table that cannot even be counted tells us nothing about its size. Counting is cheap, so a
        // failing count rather points to a broken query (e.g. a broken filter), which is the same for
        // few and many rows. Such an error keeps its regular error page with the message of the database.
        return $rows !== null && $rows >= self::MINROWS;
    }

    /**
     * Counts the rows of a table with its current search and filters.
     *
     * Counting is cheap compared to reading the rows: the database does not have to compute the columns
     * or sort the result. It is only done after a download has failed.
     *
     * @param wunderbyte_table $table
     * @return int|null null if the rows could not be counted
     */
    public static function count_rows(wunderbyte_table $table): ?int {
        global $DB;

        try {
            return $DB->count_records_sql(
                "SELECT COUNT(*) FROM {$table->sql->from} WHERE {$table->sql->where}",
                $table->sql->params
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the script was ended by a fatal PHP error that is most likely caused by the amount of data:
     * PHP ran out of memory or out of time.
     *
     * @param array|null $error the result of error_get_last()
     * @return bool
     */
    public static function is_size_related_fatal(?array $error): bool {
        if (empty($error) || ($error['type'] ?? 0) !== E_ERROR) {
            return false;
        }
        $message = (string)($error['message'] ?? '');
        return str_contains($message, 'Allowed memory size') || str_contains($message, 'Maximum execution time');
    }

    /**
     * Watches the download that is about to start: if PHP runs out of memory or time, the user is sent
     * back to the page of the table. Call this before the table is printed.
     *
     * A fatal error cannot be caught, but shutdown functions still run. Ours needs a bit of memory that
     * is not available any more at that point, so we reserve it now and release it then.
     *
     * @param string $scope
     * @param int $scopeid
     * @return void
     */
    public static function register(string $scope, int $scopeid): void {
        $returnurl = self::return_url($scope, $scopeid);
        if ($returnurl === null) {
            return;
        }
        self::$fatalredirect = $returnurl->out(false);
        self::$reserve = str_repeat('x', self::RESERVEDBYTES);
        register_shutdown_function([self::class, 'handle_shutdown']);
    }

    /**
     * Shutdown function registered by {@see self::register()}. Not to be called directly.
     *
     * @return void
     */
    public static function handle_shutdown(): void {
        self::$reserve = null;
        if (self::$fatalredirect === null || !self::is_size_related_fatal(error_get_last())) {
            return;
        }
        if (headers_sent()) {
            // PHP has already printed the error (display_errors), we can only add the hint.
            echo self::hint();
            return;
        }
        header('Location: ' . self::$fatalredirect, true, 303);
    }

    /**
     * Handles an exception thrown while the table of a download was printed.
     *
     * If the query of the table failed and the table is big enough for the amount of data to be a
     * plausible cause, the error is written to the PHP error log and the user is sent back to the page
     * of the table, which shows the hint. Tables that are not shown on report2.php get the hint on the
     * error page. Every other exception is thrown again unchanged.
     *
     * @param Throwable $exception
     * @param string $scope
     * @param int $scopeid
     * @param wunderbyte_table $table the table of the download
     * @return void
     * @throws Throwable
     */
    public static function handle_exception(Throwable $exception, string $scope, int $scopeid, wunderbyte_table $table): void {
        if (!self::is_table_query_error($exception)) {
            throw $exception;
        }
        $rows = self::count_rows($table);
        if (!self::is_plausible_rowcount($rows)) {
            throw $exception;
        }

        $debuginfo = $exception->getMessage();
        if (!empty($exception->debuginfo)) {
            $debuginfo .= ' ' . $exception->debuginfo;
        }
        // The user only gets the hint, so the cause has to be kept for the administrator.
        // Not with debugging(): its output would be in the way of the redirect.
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('mod_booking: download of the Bookings tracker table ' . $scope . ' (' . $scopeid . ', '
            . $rows . ' rows) failed: ' . $debuginfo);

        $returnurl = self::return_url($scope, $scopeid, $rows);
        if ($returnurl === null) {
            throw new moodle_exception('bookingstrackerdownloadtoolargerows', 'mod_booking', '', $rows, $debuginfo);
        }
        // The fatal error handler must not act on the end of this script any more.
        self::$fatalredirect = null;
        redirect($returnurl);
    }
}
