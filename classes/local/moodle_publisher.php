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

use core_question\local\bank\question_version_status;
use mod_quiz\question\display_options;

/**
 * Publishes reviewed drafts into native Moodle quiz/question bank records.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_publisher {
    /**
     * Checks whether a published Moodle activity still exists.
     *
     * @param int|null $cmid Course module id.
     * @return bool
     */
    public function published_activity_exists(?int $cmid): bool {
        global $DB;

        if (empty($cmid)) {
            return false;
        }

        return $DB->record_exists('course_modules', ['id' => (int)$cmid, 'deletioninprogress' => 0]);
    }

    /**
     * Return the existing published activity ids from a list of course modules.
     *
     * @param array $cmids Course module ids.
     * @return array Existing course module ids indexed by id.
     */
    public function existing_published_activity_ids(array $cmids): array {
        global $DB;

        $cmids = array_values(array_unique(array_filter(array_map('intval', $cmids))));
        if (empty($cmids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        $params['deletioninprogress'] = 0;
        $records = $DB->get_records_select(
            'course_modules',
            "id {$insql} AND deletioninprogress = :deletioninprogress",
            $params,
            '',
            'id'
        );

        return array_fill_keys(array_map('intval', array_keys($records)), true);
    }

    /**
     * Publishes a draft as a Moodle quiz and returns add_moduleinfo result.
     *
     * @param int $draftid Draft id.
     * @return \stdClass Created quiz module info.
     */
    public function publish_quiz(int $draftid): \stdClass {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/lib/questionlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/classes/quiz_settings.php');

        $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draftid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $draft->courseid], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        require_capability('local/studybuddy:publish', $context);
        $data = json_decode($draft->resultjson, true);

        if (!is_array($data) || !(new activity_generator())->is_valid_quiz($data)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        $quizmodule = null;
        $questionids = [];
        try {
            $quizmodule = $this->create_quiz_activity($course, $draft->title);
            $category = question_make_default_categories([$context]);
            $page = 1;

            foreach ($data['questions'] as $questiondata) {
                $question = $this->save_multichoice_question($category, $questiondata, (int)$USER->id);
                $questionids[] = (int)$question->id;
                quiz_add_quiz_question($question->id, (object)[
                    'id' => $quizmodule->instance,
                    'course' => $course->id,
                    'cmid' => $quizmodule->coursemodule,
                ], $page++);
            }

            \mod_quiz\quiz_settings::create($quizmodule->instance)->get_grade_calculator()->recompute_quiz_sumgrades();
            $DB->update_record('local_studybuddy_drafts', (object)[
                'id' => $draft->id,
                'status' => 'published',
                'publishedcmid' => $quizmodule->coursemodule,
                'timemodified' => time(),
            ]);

            return $quizmodule;
        } catch (\Throwable $e) {
            if ($quizmodule && !empty($quizmodule->coursemodule)) {
                try {
                    course_delete_module($quizmodule->coursemodule);
                } catch (\Throwable $cleanup) {
                    unset($cleanup);
                }
            }
            foreach ($questionids as $questionid) {
                try {
                    question_delete_question($questionid);
                } catch (\Throwable $cleanup) {
                    unset($cleanup);
                }
            }
            throw $e;
        }
    }

    /**
     * Marks drafts as reusable when their published activity was deleted.
     *
     * @param int $cmid Deleted course module id.
     * @return void
     */
    public function mark_deleted_activity_reusable(int $cmid): void {
        global $DB;

        if ($cmid <= 0) {
            return;
        }

        $drafts = $DB->get_records('local_studybuddy_drafts', ['publishedcmid' => $cmid]);
        foreach ($drafts as $draft) {
            $status = ($draft->status === 'published') ? 'reviewed' : $draft->status;
            $DB->update_record('local_studybuddy_drafts', (object)[
                'id' => $draft->id,
                'status' => $status,
                'publishedcmid' => null,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Creates a Moodle quiz activity shell.
     *
     * @param \stdClass $course Course.
     * @param string $name Quiz name.
     * @return \stdClass Created module info.
     */
    private function create_quiz_activity(\stdClass $course, string $name): \stdClass {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        $moduleinfo = new \stdClass();
        $moduleinfo->add = 'quiz';
        $moduleinfo->course = $course->id;
        $moduleinfo->coursemodule = 0;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->module = $module->id;
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->name = $name;
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
        $moduleinfo->grade = 100.0;
        $moduleinfo->sumgrades = 0;
        $moduleinfo->attempts = 0;
        $moduleinfo->grademethod = QUIZ_GRADEHIGHEST;
        $moduleinfo->preferredbehaviour = 'deferredfeedback';
        $moduleinfo->canredoquestions = 0;
        $moduleinfo->attemptonlast = 0;
        $moduleinfo->questionsperpage = 1;
        $moduleinfo->navmethod = 'free';
        $moduleinfo->shuffleanswers = 1;
        $moduleinfo->timeopen = 0;
        $moduleinfo->timeclose = 0;
        $moduleinfo->timelimit = 0;
        $moduleinfo->overduehandling = 'autosubmit';
        $moduleinfo->graceperiod = 0;
        $moduleinfo->decimalpoints = 2;
        $moduleinfo->questiondecimalpoints = -1;
        $moduleinfo->password = '';
        $moduleinfo->subnet = '';
        $moduleinfo->browsersecurity = '-';
        $moduleinfo->delay1 = 0;
        $moduleinfo->delay2 = 0;
        $moduleinfo->showuserpicture = 0;
        $moduleinfo->showblocks = 0;
        $moduleinfo->completionattemptsexhausted = 0;
        $moduleinfo->completionpass = 0;
        $moduleinfo->reviewattempt = display_options::DURING;
        $moduleinfo->reviewcorrectness = display_options::IMMEDIATELY_AFTER;
        $moduleinfo->reviewmarks = display_options::LATER_WHILE_OPEN;
        $moduleinfo->reviewspecificfeedback = display_options::LATER_WHILE_OPEN;
        $moduleinfo->reviewgeneralfeedback = display_options::LATER_WHILE_OPEN;
        $moduleinfo->reviewrightanswer = display_options::AFTER_CLOSE;
        $moduleinfo->reviewoverallfeedback = display_options::AFTER_CLOSE;

        return add_moduleinfo($moduleinfo, $course);
    }

    /**
     * Saves a generated multichoice question in the course question bank.
     *
     * @param \stdClass $category Question category.
     * @param array $questiondata Generated question data.
     * @param int $userid Current user id.
     * @return \stdClass Saved question.
     */
    private function save_multichoice_question(\stdClass $category, array $questiondata, int $userid): \stdClass {
        $questiontext = clean_param((string)($questiondata['questiontext'] ?? ''), PARAM_CLEANHTML);
        $feedback = clean_param((string)($questiondata['feedback'] ?? ''), PARAM_CLEANHTML);
        $answers = array_values($questiondata['answers'] ?? []);
        $answers = array_values(array_filter($answers, static function (array $answer): bool {
            return trim((string)($answer['text'] ?? '')) !== '';
        }));
        $answers = $this->normalise_multichoice_answers($answers);

        if (count($answers) < 2) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        $question = (object)[
            'qtype' => 'multichoice',
            'createdby' => $userid,
            'idnumber' => null,
            'status' => question_version_status::QUESTION_STATUS_READY,
        ];

        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->name = shorten_text(clean_param($questiontext, PARAM_TEXT), 80);
        $form->questiontext = ['text' => $questiontext, 'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => $feedback, 'format' => FORMAT_HTML];
        $form->defaultmark = 1;
        $form->noanswers = count($answers);
        $form->numhints = 0;
        $form->penalty = 0.3333333;
        $form->status = question_version_status::QUESTION_STATUS_READY;
        $form->versionid = 0;
        $form->version = 1;
        $form->questionbankentryid = 0;
        $form->shuffleanswers = 1;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->single = '1';
        $form->correctfeedback = [
            'text' => get_string('feedbackcorrect', 'local_studybuddy'),
            'format' => FORMAT_HTML,
        ];
        $form->partiallycorrectfeedback = [
            'text' => get_string('feedbackpartiallycorrect', 'local_studybuddy'),
            'format' => FORMAT_HTML,
        ];
        $form->shownumcorrect = 1;
        $form->incorrectfeedback = [
            'text' => get_string('feedbackincorrect', 'local_studybuddy'),
            'format' => FORMAT_HTML,
        ];
        $form->fraction = [];
        $form->answer = [];
        $form->feedback = [];
        $form->hint = [];
        $form->hintclearwrong = [];
        $form->hintshownumcorrect = [];

        foreach ($answers as $index => $answer) {
            $fraction = ((float)($answer['fraction'] ?? 0)) / 100;
            $form->fraction[$index] = (string)$fraction;
            $form->answer[$index] = [
                'text' => clean_param((string)$answer['text'], PARAM_TEXT),
                'format' => FORMAT_PLAIN,
            ];
            $form->feedback[$index] = [
                'text' => $feedback,
                'format' => FORMAT_HTML,
            ];
        }

        return \question_bank::get_qtype('multichoice')->save_question($question, $form);
    }

    /**
     * Normalises generated answers for Moodle single-answer multichoice questions.
     *
     * Moodle 4.5 rejects save_question payloads that rely on the deprecated
     * noticeyesno flow, which is triggered when fractions are inconsistent.
     * We therefore enforce a single best correct answer with fraction 100 and
     * all remaining options at 0 for published quiz questions.
     *
     * @param array $answers Generated answers.
     * @return array
     */
    private function normalise_multichoice_answers(array $answers): array {
        if (empty($answers)) {
            return [];
        }

        $bestindex = 0;
        $bestfraction = null;

        foreach ($answers as $index => $answer) {
            $fraction = (float)($answer['fraction'] ?? 0);
            if ($bestfraction === null || $fraction > $bestfraction) {
                $bestfraction = $fraction;
                $bestindex = $index;
            }
        }

        foreach ($answers as $index => $answer) {
            $answers[$index]['fraction'] = ($index === $bestindex) ? 100 : 0;
        }

        return $answers;
    }
}
