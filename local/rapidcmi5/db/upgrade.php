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

function xmldb_local_rapidcmi5_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026030200) {
        // Add player_versions table.
        $table = new xmldb_table('local_rapidcmi5_player_versions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('version', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sha256hash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('manifest', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('version_unique', XMLDB_INDEX_UNIQUE, ['version']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026030200, 'local', 'rapidcmi5');
    }

    if ($oldversion < 2026091700) {
        $table = new xmldb_table('local_rapidcmi5_versions');
        $field = new xmldb_field('libraryversionid', XMLDB_TYPE_INTEGER, '10', null,
            null, null, null, 'packageid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Legacy records are resolved by package and hash when unambiguous.
        upgrade_plugin_savepoint(true, 2026091700, 'local', 'rapidcmi5');
    }

    if ($oldversion < 2026091800) {
        // Player manifests used to list only unhashed files, and wrapped ZIPs were stored with none,
        // so Set player removed the old bundles without installing new ones. Rebuild from each ZIP.
        foreach ($DB->get_fieldset_select('local_rapidcmi5_player_versions', 'id', '1 = 1') as $playerversionid) {
            try {
                \local_rapidcmi5\player_manager::rebuild_player_manifest((int) $playerversionid);
            } catch (\moodle_exception $e) {
                // An incomplete player keeps its manifest; Set player now refuses it instead of breaking packages.
                mtrace("Player version {$playerversionid} could not be rebuilt: " . $e->getMessage());
            }
        }
        upgrade_plugin_savepoint(true, 2026091800, 'local', 'rapidcmi5');
    }

    if ($oldversion < 2026091801) {
        // Activities added from the mod_cmi5 activity library used to stay untracked; link existing ones.
        $cmids = $DB->get_fieldset_sql("SELECT cm.id
                                          FROM {course_modules} cm
                                          JOIN {modules} m ON m.id = cm.module AND m.name = 'cmi5'
                                          JOIN {cmi5} a ON a.id = cm.instance
                                     LEFT JOIN {local_rapidcmi5_deployments} d ON d.cmid = cm.id
                                         WHERE a.packageversionid IS NOT NULL AND d.id IS NULL
                                               AND cm.deletioninprogress = 0
                                      ORDER BY cm.id");
        foreach ($cmids as $cmid) {
            \local_rapidcmi5\deployment_manager::link_library_activity((int) $cmid);
        }
        upgrade_plugin_savepoint(true, 2026091801, 'local', 'rapidcmi5');
    }

    return true;
}
