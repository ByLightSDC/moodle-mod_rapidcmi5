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

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/rapidcmi5:manage', $context);
$courseid = optional_param('courseid', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 25;
$listurl = new moodle_url('/local/rapidcmi5/courses.php');
$listparams = ['search' => $search, 'page' => $page];
$backurl = new moodle_url('/local/rapidcmi5/courses.php', $listparams);
$title = get_string('projectsbycourse', 'local_rapidcmi5');
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/rapidcmi5/courses.php',
    $courseid ? ['courseid' => $courseid] + $listparams : $listparams));
$PAGE->navbar->add(get_string('manage_dashboard', 'local_rapidcmi5'), new moodle_url('/local/rapidcmi5/manage.php'));
$PAGE->navbar->add($title, $backurl);
$data = ['listurl' => $listurl->out(false), 'search' => $search,
    'backurl' => $backurl->out(false), 'backlabel' => get_string('backtocourses', 'local_rapidcmi5')];
if ($courseid) {
    $course = get_course($courseid);
    $coursename = format_string($course->fullname, true, ['context' => context_course::instance($courseid)]);
    $PAGE->navbar->add($coursename);
    $PAGE->set_title($title . ': ' . $coursename);
    $PAGE->set_heading($coursename);
    $data['isdetail'] = true;
    $data['courseurl'] = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
    $data['projects'] = \local_rapidcmi5\course_browser::get_projects($courseid);
    foreach ($data['projects'] as &$projectdata) {
        $projecturl = new moodle_url($projectdata['projecturl']);
        $projecturl->params(['courseid' => $courseid] + $listparams);
        $projectdata['projecturl'] = $projecturl->out(false);
    }
    unset($projectdata);
    $data['hasprojects'] = !empty($data['projects']);
} else {
    $PAGE->set_title($title);
    $PAGE->set_heading($title);
    $result = \local_rapidcmi5\course_browser::list_courses($search, $page * $perpage, $perpage);
    $data['courses'] = [];
    foreach ($result->courses as $course) {
        $coursecontext = context_course::instance($course->id);
        $data['courses'][] = [
            'fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
            'shortname' => format_string($course->shortname, true, ['context' => $coursecontext]),
            'projectcount' => $course->projectcount, 'activitycount' => $course->activitycount,
            'detailurl' => (new moodle_url('/local/rapidcmi5/courses.php', ['courseid' => $course->id] + $listparams))->out(false),
        ];
    }
    $data['hascourses'] = !empty($data['courses']);
    $data['total'] = $total = $result->total;
    $data['emptytext'] = get_string($search !== '' ? 'nomatchingmanagedcourses' : 'nomanagedcourses', 'local_rapidcmi5');
}
echo \local_rapidcmi5\dashboard::header('courses', 'projectsbycourse_desc');
echo $OUTPUT->render_from_template('local_rapidcmi5/courses', $data);
if (!$courseid) {
    echo $OUTPUT->paging_bar($total, $page, $perpage,
        new moodle_url('/local/rapidcmi5/courses.php', ['search' => $search]));
}
echo \local_rapidcmi5\dashboard::footer();
