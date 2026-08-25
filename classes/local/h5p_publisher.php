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

namespace local_studybuddy\local;

use mod_h5pactivity\local\manager;

/**
 * Publishes reviewed StudyBuddy H5P drafts to Moodle.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_publisher {
    /**
     * Publish a reviewed H5P flashcards draft.
     *
     * @param int $draftid Draft id.
     * @return \stdClass Created module info.
     */
    public function publish_flashcards(int $draftid): \stdClass {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/h5pactivity/lib.php');

        $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draftid], '*', MUST_EXIST);
        if ((string)$draft->activitytype !== 'h5p_flashcards') {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        $course = $DB->get_record('course', ['id' => $draft->courseid], '*', MUST_EXIST);
        $coursecontext = \context_course::instance($course->id);
        $data = json_decode((string)$draft->resultjson, true);
        if (!is_array($data) || !(new activity_generator())->is_valid_flashcards($data)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        require_capability('local/studybuddy:publish', $coursecontext);
        $packagepath = null;
        $contentbank = null;
        $moduleinfo = null;
        try {
            $packagepath = (new h5p_flashcards_builder())->build_package($data);
            $contentbank = $this->create_contentbank_content($coursecontext, (int)$USER->id, $packagepath, $draft->title);
            $moduleinfo = $this->create_h5p_activity($course, $packagepath, $draft->title);

            $metadata = json_decode((string)$draft->metadata, true);
            if (!is_array($metadata)) {
                $metadata = [];
            }
            $metadata['h5pcontentbankid'] = $contentbank->get_id();

            $DB->update_record('local_studybuddy_drafts', (object)[
                'id' => $draft->id,
                'status' => 'published',
                'publishedcmid' => $moduleinfo->coursemodule,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'timemodified' => time(),
            ]);

            return $moduleinfo;
        } catch (\Throwable $e) {
            if ($moduleinfo && !empty($moduleinfo->coursemodule)) {
                try {
                    course_delete_module($moduleinfo->coursemodule);
                } catch (\Throwable $cleanup) {
                    unset($cleanup);
                }
            }
            if ($contentbank) {
                try {
                    $contentbank->get_content_type_instance()->delete_content($contentbank);
                } catch (\Throwable $cleanup) {
                    unset($cleanup);
                }
            }
            if ($packagepath && file_exists($packagepath)) {
                @unlink($packagepath);
            }
            throw $e;
        }
    }

    /**
     * Import the generated package into the course content bank.
     *
     * @param \context_course $context Course context.
     * @param int $userid User id.
     * @param string $packagepath Local package path.
     * @param string $title Content title.
     * @return \core_contentbank\content Created content bank item.
     */
    private function create_contentbank_content(
        \context_course $context,
        int $userid,
        string $packagepath,
        string $title
    ): \core_contentbank\content {
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_studybuddy',
            'filearea' => 'h5p_package',
            'itemid' => time(),
            'filepath' => '/',
            'filename' => $this->package_filename($title),
        ];

        $fs = get_file_storage();
        $storedfile = $fs->create_file_from_pathname($filerecord, $packagepath);
        $contentbank = new \core_contentbank\contentbank();

        return $contentbank->create_content_from_file($context, $userid, $storedfile);
    }

    /**
     * Create the H5P activity module from the generated package.
     *
     * @param \stdClass $course Course.
     * @param string $packagepath Local package path.
     * @param string $title Activity title.
     * @return \stdClass Created module info.
     */
    private function create_h5p_activity(\stdClass $course, string $packagepath, string $title): \stdClass {
        global $CFG, $DB, $USER;

        $module = $DB->get_record('modules', ['name' => 'h5pactivity'], '*', MUST_EXIST);
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $filename = $this->package_filename($title);

        get_file_storage()->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $packagepath);

        $factory = new \core_h5p\factory();
        $core = $factory->get_core();
        $displayoptions = \core_h5p\helper::get_display_options(
            $core,
            \core_h5p\helper::decode_display_options($core)
        );

        $moduleinfo = new \stdClass();
        $moduleinfo->add = 'h5pactivity';
        $moduleinfo->course = $course->id;
        $moduleinfo->coursemodule = 0;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->module = $module->id;
        $moduleinfo->modulename = 'h5pactivity';
        $moduleinfo->name = $title;
        $moduleinfo->intro = '';
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->section = 0;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->groupmode = NOGROUPS;
        $moduleinfo->groupingid = 0;
        $moduleinfo->availabilityconditionsjson = null;
        $moduleinfo->completion = COMPLETION_TRACKING_NONE;
        $moduleinfo->completionview = COMPLETION_VIEW_NOT_REQUIRED;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->showdescription = 0;
        $moduleinfo->packagefile = $draftitemid;
        $moduleinfo->displayoptions = $displayoptions;
        $moduleinfo->enabletracking = 1;
        $moduleinfo->grademethod = manager::GRADEHIGHESTATTEMPT;
        $moduleinfo->reviewmode = manager::REVIEWCOMPLETION;
        $moduleinfo->grade = $CFG->gradepointdefault;

        return add_moduleinfo($moduleinfo, $course);
    }

    /**
     * Build a stable .h5p filename.
     *
     * @param string $title Title.
     * @return string
     */
    private function package_filename(string $title): string {
        $filename = clean_filename(shorten_text($title, 80));
        if ($filename === '') {
            $filename = 'studybuddy-h5p-flashcards';
        }

        return $filename . '.h5p';
    }
}
