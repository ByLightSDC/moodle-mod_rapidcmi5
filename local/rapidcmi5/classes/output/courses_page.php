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

namespace local_rapidcmi5\output;

defined('MOODLE_INTERNAL') || die();

use local_rapidcmi5\course_browser;
use moodle_url;

/** Template data for the course list and course detail views of courses.php. */
class courses_page {
    /** URL of courses.php with the given parameters. */
    public static function url(array $params = []): moodle_url {
        return new moodle_url('/local/rapidcmi5/courses.php', $params);
    }

    /** Parameters that return to the same list search and page, leaving out the defaults. */
    public static function list_params(string $search, int $page): array {
        return array_filter(['search' => $search, 'page' => $page], fn($value) => $value !== '' && $value !== 0);
    }

    /**
     * One page of managed courses.
     *
     * A page past the end, such as a stale link after courses stopped being managed, shows the last page
     * rather than an empty list under a non-zero total.
     *
     * @return array Template data, including page (the page actually shown) and total.
     */
    public static function list_data(string $search, int $page, int $perpage): array {
        $result = course_browser::list_courses($search, $page * $perpage, $perpage);
        $lastpage = max(0, (int) ceil($result->total / $perpage) - 1);
        if ($page > $lastpage) {
            $page = $lastpage;
            $result = course_browser::list_courses($search, $page * $perpage, $perpage);
        }
        $listparams = self::list_params($search, $page);
        $courses = [];
        foreach ($result->courses as $course) {
            $coursecontext = \context_course::instance($course->id);
            $courses[] = [
                'fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
                'shortname' => format_string($course->shortname, true, ['context' => $coursecontext]),
                'projectcount' => $course->projectcount,
                'activitycount' => $course->activitycount,
                'detailurl' => self::url(['courseid' => $course->id] + $listparams)->out(false),
            ];
        }
        return [
            'page' => $page,
            'total' => $result->total,
            'search' => $search,
            'listurl' => self::url()->out(false),
            'courses' => $courses,
            'hascourses' => !empty($courses),
            'emptytext' => get_string($search !== '' ? 'nomatchingmanagedcourses' : 'nomanagedcourses', 'local_rapidcmi5'),
        ];
    }

    /**
     * Managed projects in one course, with links that return to the list the user came from.
     *
     * @param \stdClass $course Course record.
     * @param array $listparams Search and page of the list to return to (see list_params()).
     * @return array Template data.
     */
    public static function detail_data(\stdClass $course, array $listparams): array {
        $projects = course_browser::get_projects($course->id, ['courseid' => $course->id] + $listparams);
        return [
            'isdetail' => true,
            'backurl' => self::url($listparams)->out(false),
            'backlabel' => get_string('backtocourses', 'local_rapidcmi5'),
            'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'projects' => $projects,
            'hasprojects' => !empty($projects),
        ];
    }
}
