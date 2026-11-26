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
 * Upgrade steps for local_studybuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin schema.
 *
 * @param int $oldversion Previous plugin version.
 * @return bool
 */
function xmldb_local_studybuddy_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();
    if ($oldversion < 2026112000) {
        $table = new xmldb_table('local_studybuddy_usage');
        $feature = new xmldb_field('feature', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'chat', 'model');
        if (!$dbman->field_exists($table, $feature)) {
            $dbman->add_field($table, $feature);
        }
        $activitytype = new xmldb_field('activitytype', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'feature');
        if (!$dbman->field_exists($table, $activitytype)) {
            $dbman->add_field($table, $activitytype);
        }
        upgrade_plugin_savepoint(true, 2026112000, 'local', 'studybuddy');
    }

    return true;
}
