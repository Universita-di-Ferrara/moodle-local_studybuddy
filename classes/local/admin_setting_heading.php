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

/**
 * Heading setting compatible with Moodle hide_if() dependencies.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_heading extends \admin_setting_heading {
    /**
     * Returns an HTML string wrapped like a regular setting row.
     *
     * @param string $data Current setting value.
     * @param string $query Search query.
     * @return string
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        $context = new \stdClass();
        $context->title = $this->visiblename;
        $context->description = $this->description;
        $context->descriptionformatted = highlight($query, markdown_to_html($this->description));

        $hidden = \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => $this->get_full_name(),
            'value' => $data ?? 1,
        ]);

        return \html_writer::start_div('form-item row', ['id' => 'admin-' . $this->name]) .
            $hidden .
            \html_writer::div($OUTPUT->render_from_template('core_admin/setting_heading', $context), 'col-sm-12') .
            \html_writer::end_div();
    }
}
