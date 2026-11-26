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
 * Site-wide StudyBuddy AI usage report.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
$fromdate = optional_param('from', '', PARAM_RAW_TRIMMED);
$todate = optional_param('to', '', PARAM_RAW_TRIMMED);
$report = optional_param('report', 'system', PARAM_ALPHA);
$reporttypes = ['system', 'course', 'function', 'provider', 'coursefunction'];
if (!in_array($report, $reporttypes, true)) {
    $report = 'system';
}
$parsedate = static function (string $value): int {
    if ($value === '') {
        return 0;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        throw new \moodle_exception('invalidreportdate', 'local_studybuddy');
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        throw new \moodle_exception('invalidreportdate', 'local_studybuddy');
    }

    return make_timestamp($year, $month, $day, 0, 0, 0);
};
$from = $parsedate($fromdate);
$to = $parsedate($todate);
if ($from > 0 && $to > 0 && $from > $to) {
    throw new \moodle_exception('invaliddaterange', 'local_studybuddy');
}
$PAGE->set_url(new moodle_url('/local/studybuddy/usage.php', [
    'from' => $fromdate,
    'to' => $todate,
    'report' => $report,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('usagereportheading', 'local_studybuddy'));
$PAGE->set_heading(get_string('usagereportheading', 'local_studybuddy'));

$where = '1 = 1';
$params = [];
if ($from > 0) {
    $where .= ' AND u.timecreated >= :timefrom';
    $params['timefrom'] = $from;
}
if ($to > 0) {
    $where .= ' AND u.timecreated < :timeto';
    $params['timeto'] = $to + DAYSECS;
}
$totals = $DB->get_record_sql(
    "SELECT COUNT(*) AS requests, COALESCE(SUM(u.inputtokens), 0) AS inputtokens,
            COALESCE(SUM(u.outputtokens), 0) AS outputtokens, COALESCE(SUM(u.totaltokens), 0) AS totaltokens
       FROM {local_studybuddy_usage} u WHERE $where",
    $params
);
$bycourse = $DB->get_records_sql(
    "SELECT MIN(u.id) AS id, u.courseid, c.fullname AS coursename, COUNT(*) AS requests,
            SUM(u.inputtokens) AS inputtokens, SUM(u.outputtokens) AS outputtokens,
            SUM(u.totaltokens) AS totaltokens
       FROM {local_studybuddy_usage} u JOIN {course} c ON c.id = u.courseid
      WHERE $where GROUP BY u.courseid, c.fullname ORDER BY totaltokens DESC",
    $params
);
$byfeature = $DB->get_records_sql(
    "SELECT MIN(u.id) AS id, u.feature, u.activitytype, COUNT(*) AS requests,
            SUM(u.inputtokens) AS inputtokens, SUM(u.outputtokens) AS outputtokens,
            SUM(u.totaltokens) AS totaltokens
       FROM {local_studybuddy_usage} u WHERE $where
      GROUP BY u.feature, u.activitytype ORDER BY totaltokens DESC",
    $params
);
$byprovider = $DB->get_records_sql(
    "SELECT MIN(u.id) AS id, u.provider, u.model, COUNT(*) AS requests,
            SUM(u.inputtokens) AS inputtokens, SUM(u.outputtokens) AS outputtokens,
            SUM(u.totaltokens) AS totaltokens
       FROM {local_studybuddy_usage} u WHERE $where
      GROUP BY u.provider, u.model ORDER BY totaltokens DESC",
    $params
);
$bycoursefeature = $DB->get_records_sql(
    "SELECT MIN(u.id) AS id, u.courseid, c.fullname AS coursename, u.feature, u.activitytype, COUNT(*) AS requests,
            SUM(u.inputtokens) AS inputtokens, SUM(u.outputtokens) AS outputtokens,
            SUM(u.totaltokens) AS totaltokens
       FROM {local_studybuddy_usage} u JOIN {course} c ON c.id = u.courseid
      WHERE $where GROUP BY u.courseid, c.fullname, u.feature, u.activitytype ORDER BY c.fullname, totaltokens DESC",
    $params
);

$reportoptions = [];
$selectedreporturl = '';
foreach ($reporttypes as $reporttype) {
    $reporturl = (new moodle_url('/local/studybuddy/usage.php', [
        'report' => $reporttype,
        'from' => $fromdate,
        'to' => $todate,
    ]))->out(false);
    $reportoptions[$reporturl] = get_string('usageview:' . $reporttype, 'local_studybuddy');
    if ($reporttype === $report) {
        $selectedreporturl = $reporturl;
    }
}
$reportselector = new url_select($reportoptions, $selectedreporturl, null, 'studybuddyusagereport');
$reportselector->set_label(get_string('usagereporttype', 'local_studybuddy'), [
    'class' => 'font-weight-bold d-block mb-2 w-100',
]);

$form = html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-row align-items-end mb-4']);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'report', 'value' => $report]);
$form .= html_writer::start_tag('div', ['class' => 'form-group col-12 col-sm-6 col-md-auto mb-2']);
$form .= html_writer::tag('label', get_string('usagefrom', 'local_studybuddy'), ['for' => 'usage-from']);
$form .= html_writer::empty_tag('input', ['type' => 'date', 'id' => 'usage-from', 'name' => 'from', 'value' => $fromdate,
    'class' => 'form-control']);
$form .= html_writer::end_tag('div');
$form .= html_writer::start_tag('div', ['class' => 'form-group col-12 col-sm-6 col-md-auto mb-2']);
$form .= html_writer::tag('label', get_string('usageto', 'local_studybuddy'), ['for' => 'usage-to']);
$form .= html_writer::empty_tag('input', ['type' => 'date', 'id' => 'usage-to', 'name' => 'to', 'value' => $todate,
    'class' => 'form-control']);
$form .= html_writer::end_tag('div');
$form .= html_writer::start_tag('div', ['class' => 'form-group col-12 col-md-auto mb-2']);
$form .= html_writer::tag('button', get_string('usagefilter', 'local_studybuddy'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
$form .= html_writer::end_tag('div');
$form .= html_writer::end_tag('form');

echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('usagereportintro', 'local_studybuddy'), \core\output\notification::NOTIFY_INFO);
echo html_writer::start_div('mb-4');
echo $OUTPUT->render($reportselector);
echo html_writer::end_div();
echo $form;

if ($report === 'system') {
    $summary = new html_table();
    $summary->head = [get_string('usagetotalrequests', 'local_studybuddy'), get_string('usageinputtokens', 'local_studybuddy'),
        get_string('usageoutputtokens', 'local_studybuddy'), get_string('usagetotaltokens', 'local_studybuddy')];
    $summary->data[] = [(int)$totals->requests, (int)$totals->inputtokens, (int)$totals->outputtokens,
        (int)$totals->totaltokens];
    echo $OUTPUT->heading(get_string('usagebysystem', 'local_studybuddy'), 3);
    echo html_writer::table($summary);
}

foreach (
    [
    [$bycourse, 'usagebycourse', 'course'],
    [$byfeature, 'usagebyfeature', 'feature'],
    [$byprovider, 'usagebyprovider', 'provider'],
    ] as [$records, $heading, $groupby]
) {
    if ($report !== $groupby) {
        continue;
    }
    echo $OUTPUT->heading(get_string($heading, 'local_studybuddy'), 3);
    if (!$records) {
        echo $OUTPUT->notification(get_string('usagenoentries', 'local_studybuddy'), \core\output\notification::NOTIFY_INFO);
        continue;
    }
    $table = new html_table();
    if ($groupby === 'course') {
        $firstcolumn = get_string('usagecourse', 'local_studybuddy');
    } else if ($groupby === 'provider') {
        $firstcolumn = get_string('usageprovider', 'local_studybuddy');
    } else {
        $firstcolumn = get_string('usagefeature', 'local_studybuddy');
    }
    $table->head = [$firstcolumn,
        get_string('usagetotalrequests', 'local_studybuddy'), get_string('usageinputtokens', 'local_studybuddy'),
        get_string('usageoutputtokens', 'local_studybuddy'), get_string('usagetotaltokens', 'local_studybuddy')];
    foreach ($records as $record) {
        if ($groupby === 'course') {
            $label = format_string($record->coursename);
        } else if ($groupby === 'provider') {
            $label = s((string)$record->provider) . ' / ' . s((string)$record->model);
        } else {
            $label = get_string('usagefeature:' . $record->feature, 'local_studybuddy');
        }
        if ($groupby === 'feature' && !empty($record->activitytype)) {
            $type = (string)$record->activitytype;
            $typelabel = get_string_manager()->string_exists('activitytype:' . $type, 'local_studybuddy') ?
                get_string('activitytype:' . $type, 'local_studybuddy') : s($type);
            $label .= ' (' . $typelabel . ')';
        }
        $table->data[] = [$label, (int)$record->requests, (int)$record->inputtokens,
            (int)$record->outputtokens, (int)$record->totaltokens];
    }
    echo html_writer::table($table);
}
if ($report === 'coursefunction') {
    echo $OUTPUT->heading(get_string('usagebycoursefeature', 'local_studybuddy'), 3);
    if (!$bycoursefeature) {
        echo $OUTPUT->notification(get_string('usagenoentries', 'local_studybuddy'), \core\output\notification::NOTIFY_INFO);
    } else {
        $table = new html_table();
        $table->head = [get_string('usagecourse', 'local_studybuddy'), get_string('usagefeature', 'local_studybuddy'),
            get_string('activitytype', 'local_studybuddy'), get_string('usagetotalrequests', 'local_studybuddy'),
            get_string('usageinputtokens', 'local_studybuddy'), get_string('usageoutputtokens', 'local_studybuddy'),
            get_string('usagetotaltokens', 'local_studybuddy')];
        foreach ($bycoursefeature as $record) {
            $feature = get_string_manager()->string_exists('usagefeature:' . $record->feature, 'local_studybuddy') ?
                get_string('usagefeature:' . $record->feature, 'local_studybuddy') : s((string)$record->feature);
            $activitytype = '';
            if (!empty($record->activitytype)) {
                $type = (string)$record->activitytype;
                $activitytype = get_string_manager()->string_exists('activitytype:' . $type, 'local_studybuddy') ?
                    get_string('activitytype:' . $type, 'local_studybuddy') : s($type);
            }
            $table->data[] = [format_string($record->coursename), $feature, $activitytype, (int)$record->requests,
                (int)$record->inputtokens, (int)$record->outputtokens, (int)$record->totaltokens];
        }
        echo html_writer::table($table);
    }
}
echo $OUTPUT->footer();
