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

use local_rapidcmi5\output\courses_page;

require_login();
$context = context_system::instance();
require_capability('local/rapidcmi5:manage', $context);
$courseid = optional_param('courseid', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 25;
$title = get_string('projectsbycourse', 'local_rapidcmi5');

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(get_string('manage_dashboard', 'local_rapidcmi5'), new moodle_url('/local/rapidcmi5/manage.php'));

if ($courseid) {
    // Detail view: the projects installed in one course.
    $listparams = courses_page::list_params($search, $page);
    $PAGE->set_url(courses_page::url(['courseid' => $courseid] + $listparams));
    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course) {
        redirect(courses_page::url($listparams), get_string('error:coursenotfound', 'local_rapidcmi5', $courseid),
            null, \core\output\notification::NOTIFY_ERROR);
    }
    $coursename = format_string($course->fullname, true, ['context' => context_course::instance($courseid)]);
    $PAGE->navbar->add($title, courses_page::url($listparams));
    $PAGE->navbar->add($coursename);
    $PAGE->set_title($title . ': ' . $coursename);
    $PAGE->set_heading($coursename);
    $data = courses_page::detail_data($course, $listparams);
} else {
    // List view: courses containing managed activities, searchable and paged.
    $data = courses_page::list_data($search, $page, $perpage);
    $PAGE->set_url(courses_page::url(courses_page::list_params($search, $data['page'])));
    $PAGE->navbar->add($title, courses_page::url());
    $PAGE->set_title($title);
    $PAGE->set_heading($title);
    $data['pagingbar'] = $OUTPUT->paging_bar($data['total'], $data['page'], $perpage,
        courses_page::url(courses_page::list_params($search, 0)));
}

echo \local_rapidcmi5\dashboard::header('courses', 'projectsbycourse_desc');
echo $OUTPUT->render_from_template('local_rapidcmi5/courses', $data);
echo \local_rapidcmi5\dashboard::footer();
