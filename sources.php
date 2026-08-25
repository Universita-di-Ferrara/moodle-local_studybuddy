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
 * StudyBuddy source management page.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_studybuddy\local\source_service;
use local_studybuddy\output\navigation;
use local_studybuddy\output\renderer;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
$service = new source_service();
$message = '';

require_login($course);
require_capability('local/studybuddy:managesources', $context);

$formdata = data_submitted();
if ($formdata && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action === 'sync') {
        $queue = $service->queue_sync($courseid, 'manual');
        $message = get_string('sync:queued', 'local_studybuddy', (object)$queue);
    }
}

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_url(new moodle_url('/local/studybuddy/sources.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('sourcesheading', 'local_studybuddy'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/studybuddy/styles.css'));
$PAGE->requires->js_call_amd('local_studybuddy/studybuddy', 'initSources', [$courseid]);

/** @var renderer $renderer */
$renderer = $PAGE->get_renderer('local_studybuddy');
$sources = $service->list_sources($courseid);
$status = $service->get_sync_status($courseid);
$statuslabel = !empty($status['needsreindex']) ?
    str_replace(
        '%%PROVIDER%%',
        $status['providerlabel'],
        get_string('syncstatus:providerchanged', 'local_studybuddy')
    ) :
    get_string('sourcestatus', 'local_studybuddy', (object)$status);

echo $OUTPUT->header();
echo $renderer->render_sources_table([
    'courseid' => $courseid,
    'sesskey' => sesskey(),
    'sectionnav' => navigation::section_nav($courseid, $context, 'sources'),
    'heading' => get_string('sourcesheading', 'local_studybuddy'),
    'intro' => get_string('sourcesintro', 'local_studybuddy'),
    'messagehtml' => $message !== '' ? $OUTPUT->notification($message, 'notifysuccess') : '',
    'statuslabel' => $statuslabel,
    'statuswarning' => !empty($status['needsreindex']),
    'syncbutton' => get_string('indexcourse', 'local_studybuddy'),
    'hassources' => !empty($sources),
    'sources' => $sources,
]);
echo $OUTPUT->footer();
