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

namespace local_studybuddy\output;

/**
 * Plugin renderer backed by Mustache templates.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the RAG chat page.
     *
     * @param array $context Template context.
     * @return string
     */
    public function render_chat_page(array $context): string {
        return $this->render_from_template('local_studybuddy/chat_page', $context);
    }

    /**
     * Render the source management table.
     *
     * @param array $context Template context.
     * @return string
     */
    public function render_sources_table(array $context): string {
        return $this->render_from_template('local_studybuddy/sources_table', $context);
    }

    /**
     * Render the student practice page.
     *
     * @param array $context Template context.
     * @return string
     */
    public function render_practice_page(array $context): string {
        return $this->render_from_template('local_studybuddy/practice_page', $context);
    }

    /**
     * Render the teacher studio page.
     *
     * @param array $context Template context.
     * @return string
     */
    public function render_teacher_page(array $context): string {
        return $this->render_from_template('local_studybuddy/teacher_page', $context);
    }

    /**
     * Render the interactive or preview quiz block.
     *
     * @param array $context Template context.
     * @return string
     */
    public function render_practice_quiz(array $context): string {
        return $this->render_from_template('local_studybuddy/practice_quiz', $context);
    }

    /**
     * Render the teacher draft editor block.
     *
     * @param array $context Template context.
     * @return string
     */
    public function render_teacher_draft_editor(array $context): string {
        return $this->render_from_template('local_studybuddy/teacher_draft_editor', $context);
    }

    /**
     * Render the recent drafts block.
     *
     * @param array $context Template context.
     * @return string
     */
    public function render_teacher_recent_drafts(array $context): string {
        return $this->render_from_template('local_studybuddy/teacher_recent_drafts', $context);
    }
}
