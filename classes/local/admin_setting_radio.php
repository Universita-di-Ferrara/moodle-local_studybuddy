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
 * Selects one admin setting value with native radio controls.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_radio extends \admin_setting_configselect {
    /**
     * Renders the configured choices as an accessible radio group.
     *
     * @param string $data Current value.
     * @param string $query Search query.
     * @return string
     */
    public function output_html($data, $query = '') {
        $items = '';
        foreach ($this->choices as $value => $label) {
            $id = $this->get_id() . '_' . clean_param((string)$value, PARAM_ALPHANUMEXT);
            $attributes = [
                'type' => 'radio',
                'name' => $this->get_full_name(),
                'id' => $id,
                'value' => (string)$value,
            ];
            if ((string)$data === (string)$value) {
                $attributes['checked'] = 'checked';
            }
            if ($this->is_readonly()) {
                $attributes['disabled'] = 'disabled';
            }
            $items .= html_writer::div(
                html_writer::empty_tag('input', $attributes) . ' ' .
                    html_writer::tag('label', $label, ['for' => $id]),
                'form-check'
            );
        }

        $group = html_writer::tag(
            'fieldset',
            html_writer::tag('legend', $this->visiblename, ['class' => 'visually-hidden']) . $items,
            ['class' => 'form-check-group']
        );

        return format_admin_setting($this, $this->visiblename, $group, $this->description, false, '', null, $query);
    }
}
