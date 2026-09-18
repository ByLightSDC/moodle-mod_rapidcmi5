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
    /** Require the manage capability and return the system context it was checked in. */
    private static function require_manage(): \context_system {
        $context = \context_system::instance();
        require_capability('local/rapidcmi5:manage', $context);
        return $context;
    }

    /** FROM clause limited to deployments whose course, project and cmi5 activity still exist. */
    private static function live_deployments_from(): string {
        return " FROM {local_rapidcmi5_deployments} d
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
        $pattern = '%' . $DB->sql_like_escape($search) . '%';
        $where = ' WHERE (' . $DB->sql_like('c.fullname', ':fullname', false)
            . ' OR ' . $DB->sql_like('c.shortname', ':shortname', false) . ')';
        return [$where, ['fullname' => $pattern, 'shortname' => $pattern]];
    }

    /** Count matching courses without building the page of results. */
    public static function count_courses(string $search = ''): int {
        global $DB;
        self::require_manage();
        [$where, $params] = self::search_clause($search);
        return $DB->count_records_sql('SELECT COUNT(DISTINCT c.id)' . self::live_deployments_from() . $where, $params);
    }

    /**
     * Return matching courses with project and activity counts, plus the total for pagination.
     *
     * Course contexts are preloaded, so context_course::instance() on the results needs no further queries.
     *
     * @return \stdClass with int total and array courses (id, fullname, shortname, projectcount, activitycount)
     */
    public static function list_courses(string $search = '', int $offset = 0, int $limit = 25): \stdClass {
        global $DB;
        $total = self::count_courses($search);
        [$where, $params] = self::search_clause($search);
        $ctxcolumns = \context_helper::get_preload_record_columns('ctx');
        $ctxselect = \context_helper::get_preload_record_columns_sql('ctx');
        $sql = "SELECT c.id, c.fullname, c.shortname, $ctxselect,
                       COUNT(DISTINCT p.id) AS projectcount, COUNT(DISTINCT cm.id) AS activitycount"
            . self::live_deployments_from() . "
              LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel"
            . $where . "
              GROUP BY c.id, c.fullname, c.shortname, " . implode(', ', array_keys($ctxcolumns)) . "
              ORDER BY c.fullname, c.id";
        $params['contextlevel'] = CONTEXT_COURSE;
        $courses = array_values($DB->get_records_sql($sql, $params, max(0, $offset), $limit));
        foreach ($courses as $course) {
            if ($course->ctxid) {
                \context_helper::preload_from_record($course);
            } else {
                // No context row yet; context_course::instance() creates it on first use.
                foreach ($ctxcolumns as $alias) {
                    unset($course->$alias);
                }
            }
        }
        return (object) ['total' => $total, 'courses' => $courses];
    }

    /**
     * Group a course's installed activities by project for the detail template.
     *
     * @param int $courseid
     * @param array $linkparams Extra parameters for each project link, e.g. to return to this course.
     * @return array
     */
    public static function get_projects(int $courseid, array $linkparams = []): array {
        global $DB;
        $systemcontext = self::require_manage();
        $sql = "SELECT d.id, d.projectid, d.versionid, d.cmid,
                       p.name, p.identifier, p.currentversionid, a.name AS activityname,
                       v.versionnumber, latest.versionnumber AS latestversion"
            . self::live_deployments_from() . "
              LEFT JOIN {local_rapidcmi5_versions} v ON v.id = d.versionid AND v.projectid = p.id
              LEFT JOIN {local_rapidcmi5_versions} latest ON latest.id = p.currentversionid AND latest.projectid = p.id
                  WHERE c.id = :courseid
               ORDER BY p.name, p.id, a.name, d.id";
        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $unknownversion = get_string('unknownversion', 'local_rapidcmi5');
        $projects = [];
        foreach ($rows as $row) {
            $projects[$row->projectid] ??= self::format_project($row, $systemcontext, $unknownversion, $linkparams);
            $projects[$row->projectid]['activities'][] = self::format_activity($row, $unknownversion);
        }
        return array_values($projects);
    }

    /** Template data for a project heading, before its activities are added. */
    private static function format_project(\stdClass $row, \context_system $context, string $unknownversion,
            array $linkparams): array {
        $projecturl = new \moodle_url('/local/rapidcmi5/project.php', ['id' => $row->projectid] + $linkparams);
        return [
            'name' => format_string($row->name, true, ['context' => $context]),
            'identifier' => $row->identifier,
            'projecturl' => $projecturl->out(false),
            'latestversion' => $row->latestversion ?? $unknownversion,
            'activities' => [],
        ];
    }

    /** Template data for one installed activity, including how its version compares to the project's latest. */
    private static function format_activity(\stdClass $row, string $unknownversion): array {
        $versionknown = $row->versionnumber !== null && $row->latestversion !== null;
        $outdated = $versionknown && (int) $row->versionid !== (int) $row->currentversionid;
        if (!$versionknown) {
            $statuskey = 'unknownversion';
        } else if ($outdated) {
            $statuskey = 'olderprojectversion';
        } else {
            $statuskey = 'currentversion';
        }
        return [
            'activityname' => format_string($row->activityname, true, ['context' => \context_module::instance($row->cmid)]),
            'activityurl' => (new \moodle_url('/mod/cmi5/view.php', ['id' => $row->cmid]))->out(false),
            'versionnumber' => $row->versionnumber ?? $unknownversion,
            'isoutdated' => $outdated,
            'status' => get_string($statuskey, 'local_rapidcmi5'),
        ];
    }
}
