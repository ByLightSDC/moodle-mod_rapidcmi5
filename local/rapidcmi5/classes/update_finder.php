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

namespace local_rapidcmi5;

defined('MOODLE_INTERNAL') || die();

/** Finds managed activities that are on an older version than their project's current one. */
class update_finder {
    /** Deployments behind their project's current version, joined to the records the Updates page shows. */
    private const FROM = " FROM {local_rapidcmi5_deployments} d
                           JOIN {local_rapidcmi5_projects} p ON p.id = d.projectid
                           JOIN {local_rapidcmi5_versions} cur ON cur.id = p.currentversionid
                      LEFT JOIN {local_rapidcmi5_versions} inst ON inst.id = d.versionid
                           JOIN {course} c ON c.id = d.courseid
                           JOIN {course_modules} cm ON cm.id = d.cmid AND cm.course = c.id AND cm.deletioninprogress = 0
                           JOIN {modules} m ON m.id = cm.module AND m.name = 'cmi5'
                           JOIN {cmi5} a ON a.id = cm.instance
                          WHERE d.versionid <> p.currentversionid";

    /**
     * Activities behind their project's current version, grouped by project.
     *
     * @param array $filters Optional search (project name or identifier), projectid and
     *                       deploymentids (only these deployments).
     * @return \stdClass[] Projects keyed by ID, each with id, name, identifier, currentversionid, currentlabel,
     *                     canupdate (false when the current version's library revision is missing) and activities
     *                     (deploymentid, cmid, courseid, coursename, activityname, installedlabel).
     */
    public static function find(array $filters = []): array {
        global $DB;
        [$where, $params] = self::filter_sql($filters);
        $rows = $DB->get_recordset_sql("SELECT d.id AS deploymentid, d.cmid, d.courseid, d.projectid,
                                               p.name AS projectname, p.identifier, p.currentversionid,
                                               cur.versionnumber AS currentlabel, inst.versionnumber AS installedlabel,
                                               c.fullname AS coursename, a.name AS activityname" . self::FROM . $where . "
                                      ORDER BY p.name, p.id, c.fullname, a.name, d.id", $params);
        $projects = [];
        foreach ($rows as $row) {
            if (!isset($projects[$row->projectid])) {
                $current = $DB->get_record('local_rapidcmi5_versions', ['id' => $row->currentversionid]);
                $projects[$row->projectid] = (object) [
                    'id' => (int) $row->projectid,
                    'name' => $row->projectname,
                    'identifier' => $row->identifier,
                    'currentversionid' => (int) $row->currentversionid,
                    'currentlabel' => $row->currentlabel,
                    'canupdate' => $current && project_manager::get_library_version($current) !== false,
                    'activities' => [],
                ];
            }
            $projects[$row->projectid]->activities[] = (object) [
                'deploymentid' => (int) $row->deploymentid,
                'cmid' => (int) $row->cmid,
                'courseid' => (int) $row->courseid,
                'coursename' => $row->coursename,
                'activityname' => $row->activityname,
                'installedlabel' => $row->installedlabel,
            ];
        }
        $rows->close();
        return $projects;
    }

    /**
     * Number of activities behind their project's current version.
     *
     * @param array $filters As for find().
     * @return int
     */
    public static function count(array $filters = []): int {
        global $DB;
        [$where, $params] = self::filter_sql($filters);
        return $DB->count_records_sql('SELECT COUNT(1)' . self::FROM . $where, $params);
    }

    /** Extra WHERE conditions and parameters for the supported filters. */
    private static function filter_sql(array $filters): array {
        global $DB;
        $where = '';
        $params = [];
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%' . $DB->sql_like_escape($search) . '%';
            $where .= ' AND (' . $DB->sql_like('p.name', ':name', false)
                . ' OR ' . $DB->sql_like('p.identifier', ':identifier', false) . ')';
            $params += ['name' => $pattern, 'identifier' => $pattern];
        }
        if (!empty($filters['projectid'])) {
            $where .= ' AND d.projectid = :projectid';
            $params['projectid'] = (int) $filters['projectid'];
        }
        if (isset($filters['deploymentids'])) {
            $ids = array_map('intval', $filters['deploymentids']) ?: [0];
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'dep');
            $where .= " AND d.id $insql";
            $params += $inparams;
        }
        return [$where, $params];
    }
}
