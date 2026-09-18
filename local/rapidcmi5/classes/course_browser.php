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

/** Read-only course view of managed projects and their installed activities. */
class course_browser {
    /** Only count deployments whose course, project and cmi5 activity still exist. */
    private static function joins(): string {
        return "FROM {local_rapidcmi5_deployments} d
            JOIN {course} c ON c.id = d.courseid
            JOIN {local_rapidcmi5_projects} p ON p.id = d.projectid
            JOIN {course_modules} cm ON cm.id = d.cmid AND cm.course = c.id AND cm.deletioninprogress = 0
            JOIN {modules} m ON m.id = cm.module AND m.name = 'cmi5'
            JOIN {cmi5} a ON a.id = cm.instance AND a.course = c.id";
    }

    /** Build the optional name filter shared by the count and list queries. */
    private static function search_clause(string $search): array {
        global $DB;
        if ($search === '') {
            return ['', []];
        }
        $where = ' WHERE (' . $DB->sql_like('c.fullname', ':fullname', false) . ' OR ' .
            $DB->sql_like('c.shortname', ':shortname', false) . ')';
        return [$where, ['fullname' => '%' . $DB->sql_like_escape($search) . '%',
            'shortname' => '%' . $DB->sql_like_escape($search) . '%']];
    }

    /** Count matching courses without building the page of results. */
    public static function count_courses(string $search = ''): int {
        global $DB;
        require_capability('local/rapidcmi5:manage', \context_system::instance());
        [$where, $params] = self::search_clause($search);
        return $DB->count_records_sql('SELECT COUNT(DISTINCT c.id) ' . self::joins() . $where, $params);
    }

    /** Return matching courses with project and activity counts, plus the total for pagination. */
    public static function list_courses(string $search = '', int $offset = 0, int $limit = 25): \stdClass {
        global $DB;
        require_capability('local/rapidcmi5:manage', \context_system::instance());
        [$where, $params] = self::search_clause($search);
        $from = self::joins();
        $total = $DB->count_records_sql("SELECT COUNT(DISTINCT c.id) $from $where", $params);
        $courses = $DB->get_records_sql("SELECT c.id, c.fullname, c.shortname,
                COUNT(DISTINCT p.id) AS projectcount, COUNT(DISTINCT cm.id) AS activitycount
            $from $where GROUP BY c.id, c.fullname, c.shortname ORDER BY c.fullname, c.id",
            $params, max(0, $offset), $limit);
        return (object) ['total' => $total, 'courses' => array_values($courses)];
    }

    /** Group a course's installed activities by project for the detail template. */
    public static function get_projects(int $courseid): array {
        global $DB;
        $systemcontext = \context_system::instance();
        require_capability('local/rapidcmi5:manage', $systemcontext);
        $from = self::joins();
        $rows = $DB->get_records_sql("SELECT d.id, d.projectid, d.versionid, d.cmid,
                p.name, p.identifier, p.currentversionid, a.name AS activityname,
                v.versionnumber, latest.versionnumber AS latestversion
            $from
            LEFT JOIN {local_rapidcmi5_versions} v ON v.id = d.versionid AND v.projectid = p.id
            LEFT JOIN {local_rapidcmi5_versions} latest ON latest.id = p.currentversionid AND latest.projectid = p.id
            WHERE c.id = :courseid ORDER BY p.name, p.id, a.name, d.id", ['courseid' => $courseid]);
        $projects = [];
        foreach ($rows as $row) {
            if (!isset($projects[$row->projectid])) {
                $projects[$row->projectid] = [
                    'name' => format_string($row->name, true, ['context' => $systemcontext]),
                    'identifier' => $row->identifier,
                    'projecturl' => (new \moodle_url('/local/rapidcmi5/project.php', ['id' => $row->projectid]))->out(false),
                    'latestversion' => $row->latestversion ?? get_string('unknownversion', 'local_rapidcmi5'),
                    'activities' => [],
                ];
            }
            $known = $row->versionnumber !== null && $row->latestversion !== null;
            $outdated = $known && (int) $row->versionid !== (int) $row->currentversionid;
            $projects[$row->projectid]['activities'][] = [
                'activityname' => format_string($row->activityname, true,
                    ['context' => \context_module::instance($row->cmid)]),
                'activityurl' => (new \moodle_url('/mod/cmi5/view.php', ['id' => $row->cmid]))->out(false),
                'versionnumber' => $row->versionnumber ?? get_string('unknownversion', 'local_rapidcmi5'),
                'isoutdated' => $outdated,
                'status' => get_string(!$known ? 'unknownversion' : ($outdated ? 'olderprojectversion' : 'currentversion'),
                    'local_rapidcmi5'),
            ];
        }
        return array_values($projects);
    }
}
