#!/bin/bash
# PHPUnit for the subplugins bundled in bookingextension/ (called from moodle-plugin-ci.yml, custom_steps).
#
# moodle-plugin-ci phpunit runs the mod_booking testsuite only; the subplugins have test suites of their own that it
# never starts. The small suites run in every job. The agent suite (about 1500 tests, ~20 minutes) runs once per
# database family: Moodle 5.2 with PostgreSQL and Moodle 5.1 with MariaDB - PostgreSQL compares strings differently
# (case, accents, transactions in tests), MariaDB is what most sites run.
set -u
cd "$GITHUB_WORKSPACE/moodle" || exit 1
root=.
if [ -d public ]; then
    root=public
fi
branch=$(sed -n "s/^\$branch *= *'\([0-9]*\)'.*/\1/p" "$root/version.php")
dbtype=$(sed -n "s/^\$CFG->dbtype *= *'\([a-z]*\)'.*/\1/p" config.php)

suites="confirmation_supervisor todolist"
case "$branch-$dbtype" in
    502-pgsql|501-mariadb) suites="agent $suites" ;;
esac

php "$root/admin/tool/phpunit/cli/util.php" --buildcomponentconfigs > /dev/null || exit 1

status=0
for suite in $suites; do
    dir="$root/mod/booking/bookingextension/$suite"
    if [ ! -f "$dir/phpunit.xml" ]; then
        echo "No phpunit.xml for bookingextension_$suite, skipped."
        continue
    fi
    echo "::group::PHPUnit bookingextension_$suite (Moodle $branch, $dbtype)"
    vendor/bin/phpunit -c "$dir" --testdox || status=1
    echo "::endgroup::"
done
exit $status
