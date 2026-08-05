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
 * Booking cache report: read-only metrics about the booking option table caches.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\local\cachereport\cachereport_service;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('modbookingcachereport');

$samplesize = optional_param('samplesize', cachereport_service::DEFAULTSAMPLESIZE, PARAM_INT);
$samplesize = max(1, min($samplesize, cachereport_service::MAXSAMPLESIZE));

$service = new cachereport_service();
$configuration = $service->configuration();
$sample = $service->stem_sample($samplesize);
$timing = $service->query_timing();
$storeusage = $service->store_usage();
$logfilter = $service->logfilter_stats();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cachereport', 'mod_booking'));
echo html_writer::div(get_string('cachereportintro', 'mod_booking'), 'alert alert-info');

// Section: traffic light findings with action recommendations.
echo $OUTPUT->heading(get_string('cachereportsectionfindings', 'mod_booking'), 3);
$statusclasses = [
    'ok' => 'alert alert-success',
    'warning' => 'alert alert-warning',
    'critical' => 'alert alert-danger',
];
$statuslabels = [
    'ok' => get_string('cachereportstatusok', 'mod_booking'),
    'warning' => get_string('cachereportstatuswarning', 'mod_booking'),
    'critical' => get_string('cachereportstatuscritical', 'mod_booking'),
];
foreach ($service->evaluate($configuration, $sample, $timing, $storeusage) as $finding) {
    $content = html_writer::tag('strong', $statuslabels[$finding['status']] . ': ') . $finding['message'];
    if (!empty($finding['action'])) {
        $content .= ' ' . html_writer::tag('strong', $finding['action']);
    }
    echo html_writer::div($content, $statusclasses[$finding['status']]);
}

// Section: configuration.
echo $OUTPUT->heading(get_string('cachereportsectionconfig', 'mod_booking'), 3);
$table = new html_table();
$table->data[] = [
    get_string('cachereportfilteractive', 'mod_booking'),
    $configuration['active'] ? get_string('yes') : get_string('no'),
];
foreach ($configuration['markercounts'] as $markervalue => $count) {
    $table->data[] = [
        get_string('cachereportmarkervalue', 'mod_booking') . " $markervalue",
        get_string('cachereportoptioncount', 'mod_booking') . ": $count",
    ];
}
foreach ($configuration['usedconditions'] as $info) {
    $table->data[] = [
        get_string('cachereportusedconditions', 'mod_booking') . ": " . $info['name'],
        get_string('cachereportreferencedvalues', 'mod_booking') . ": " . $info['referencedvalues'],
    ];
}
$table->data[] = [
    get_string('cachereportdaybucket', 'mod_booking'),
    $configuration['daybucket'] ? userdate($configuration['daybucket']) : '-',
];
$table->data[] = [
    get_string('cachereportpendingpurgetasks', 'mod_booking'),
    $configuration['pendingpurgetasks'],
];
echo html_writer::table($table);

// Section: cache key sharing.
echo $OUTPUT->heading(get_string('cachereportsectionsample', 'mod_booking'), 3);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => new moodle_url('/mod/booking/cachereport.php')]);
echo html_writer::label(get_string('cachereportsamplesize', 'mod_booking'), 'samplesize');
echo ' ' . html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'samplesize',
    'id' => 'samplesize',
    'value' => $samplesize,
    'min' => 1,
    'max' => cachereport_service::MAXSAMPLESIZE,
]);
echo ' ' . html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('refresh')]);
echo html_writer::end_tag('form');
if (empty($sample['active'])) {
    echo html_writer::div(get_string('cachereportinactive', 'mod_booking'));
} else {
    $table = new html_table();
    $table->data[] = [get_string('cachereportsamplesize', 'mod_booking'), $sample['samplesize']];
    $table->data[] = [get_string('cachereportdistinctstems', 'mod_booking'), $sample['distinctstems']];
    $table->data[] = [get_string('cachereporttopclasses', 'mod_booking'), implode(', ', $sample['topclasses'])];
    echo html_writer::table($table);
    echo html_writer::div(get_string('cachereportsampleinterpretation', 'mod_booking'), 'small text-muted');
}

// Section: query timing.
echo $OUTPUT->heading(get_string('cachereportsectiontiming', 'mod_booking'), 3);
if (!empty($timing['available'])) {
    $table = new html_table();
    $table->data[] = [
        get_string('cachereportlargestinstance', 'mod_booking'),
        $timing['optioncount'],
    ];
    $table->data[] = [get_string('cachereportquerytimeplain', 'mod_booking'), $timing['plainms']];
    if ($timing['filteredms'] !== null) {
        $table->data[] = [get_string('cachereportquerytimefiltered', 'mod_booking'), $timing['filteredms']];
    }
    echo html_writer::table($table);
}

// Section: store usage.
echo $OUTPUT->heading(get_string('cachereportsectionstores', 'mod_booking'), 3);
$table = new html_table();
$table->head = [
    get_string('definition', 'cache'),
    get_string('store', 'cache'),
    get_string('cachereportitems', 'mod_booking'),
    get_string('cachereportmeansize', 'mod_booking'),
    get_string('cachereporttotalsize', 'mod_booking'),
];
foreach ($storeusage as $row) {
    if (!$row['supported']) {
        $table->data[] = [
            $row['definition'],
            $row['store'],
            get_string('cachereportnotsupported', 'mod_booking'),
            '-',
            '-',
        ];
        continue;
    }
    $table->data[] = [
        $row['definition'],
        $row['store'],
        $row['items'],
        display_size((int) round($row['meansize'])),
        $row['totalsize'] !== null ? display_size($row['totalsize']) : '-',
    ];
}
echo html_writer::table($table);

// Section: wunderbyte table filter cache log.
echo $OUTPUT->heading(get_string('cachereportlogfilter', 'mod_booking'), 3);
$wbsettingsurl = new moodle_url(
    '/admin/settings.php',
    ['section' => 'local_wunderbyte_table_settings'],
    'admin-logfiltercaches'
);
echo html_writer::div(
    html_writer::link($wbsettingsurl, get_string('cachereportlogfiltersettinglink', 'mod_booking'))
);
if ($logfilter === null) {
    echo html_writer::div(get_string('cachereportlogfilterdisabled', 'mod_booking'), 'small text-muted');
} else {
    $table = new html_table();
    $table->data[] = [get_string('cachereportitems', 'mod_booking'), $logfilter['keycount']];
    $table->data[] = [get_string('cachereporthits', 'mod_booking'), $logfilter['hitsum']];
    $table->data[] = [get_string('from'), $logfilter['oldest'] ? userdate($logfilter['oldest']) : '-'];
    $table->data[] = [get_string('to'), $logfilter['newest'] ? userdate($logfilter['newest']) : '-'];
    echo html_writer::table($table);
}

// Section: snapshots trend.
echo $OUTPUT->heading(get_string('cachereportsectionsnapshots', 'mod_booking'), 3);
$snapshots = $DB->get_records('booking_cachereport_snapshots', null, 'timecreated DESC', '*', 0, 30);
if (empty($snapshots)) {
    echo html_writer::div(get_string('cachereportsnapshotsempty', 'mod_booking'));
} else {
    $snapshots = array_reverse($snapshots);

    $labels = [];
    $stemseries = [];
    $timingseries = [];
    foreach ($snapshots as $snapshot) {
        $labels[] = userdate($snapshot->timecreated, get_string('strftimedateshort', 'langconfig'));
        $stemseries[] = (int) $snapshot->distinctstems;
        $timingseries[] = (int) ($snapshot->querytimefiltered ?? 0);
    }
    $chart = new \core\chart_line();
    $chart->set_labels($labels);
    $chart->add_series(
        new \core\chart_series(get_string('cachereportdistinctstems', 'mod_booking'), $stemseries)
    );
    $chart->add_series(
        new \core\chart_series(get_string('cachereportquerytimefiltered', 'mod_booking'), $timingseries)
    );
    echo $OUTPUT->render($chart);

    $table = new html_table();
    $table->head = [
        get_string('date'),
        get_string('cachereportsamplesize', 'mod_booking'),
        get_string('cachereportdistinctstems', 'mod_booking'),
        get_string('cachereportmarkervalue', 'mod_booking'),
        get_string('cachereportquerytimeplain', 'mod_booking'),
        get_string('cachereportquerytimefiltered', 'mod_booking'),
    ];
    foreach (array_reverse($snapshots) as $snapshot) {
        $table->data[] = [
            userdate($snapshot->timecreated),
            $snapshot->samplesize,
            $snapshot->distinctstems,
            $snapshot->sqlfilteroptions,
            $snapshot->querytimeplain ?? '-',
            $snapshot->querytimefiltered ?? '-',
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
