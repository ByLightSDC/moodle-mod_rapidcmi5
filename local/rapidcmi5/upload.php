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

use core\output\notification;
use local_rapidcmi5\form\upload_package_form;
use local_rapidcmi5\output\courses_page;
use local_rapidcmi5\version_uploader;

require_login();
$context = context_system::instance();
require_capability('local/rapidcmi5:deploy', $context);

$projectid = optional_param('projectid', 0, PARAM_INT);
$selectedproject = $projectid ? $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid], '*', MUST_EXIST) : null;
// The course only shapes the breadcrumb back to the course view, so a missing course is simply dropped.
$courseid = $projectid ? optional_param('courseid', 0, PARAM_INT) : 0;
$course = $courseid ? $DB->get_record('course', ['id' => $courseid]) : false;
$navigationparams = courses_page::list_params(trim(optional_param('search', '', PARAM_TEXT)),
    max(0, optional_param('page', 0, PARAM_INT)));
if ($course) {
    $navigationparams['courseid'] = $course->id;
}
$uploadtitle = get_string($selectedproject ? 'uploadnewversion' : 'uploadpackage', 'local_rapidcmi5');
$uploadurl = new moodle_url('/local/rapidcmi5/upload.php', $projectid ? ['projectid' => $projectid] + $navigationparams : []);
$cancelurl = $selectedproject ? new moodle_url('/local/rapidcmi5/project.php', ['id' => $projectid] + $navigationparams) :
    new moodle_url('/local/rapidcmi5/index.php');
$PAGE->set_context($context);
$PAGE->set_url($uploadurl);
$PAGE->set_title($uploadtitle);
$PAGE->set_heading($uploadtitle);
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(get_string('manage_dashboard', 'local_rapidcmi5'), new moodle_url('/local/rapidcmi5/manage.php'));
if ($course) {
    $listparams = array_diff_key($navigationparams, ['courseid' => true]);
    $PAGE->navbar->add(get_string('projectsbycourse', 'local_rapidcmi5'), courses_page::url($listparams));
    $PAGE->navbar->add(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]),
        courses_page::url($navigationparams));
} else {
    $PAGE->navbar->add(get_string('projects', 'local_rapidcmi5'),
        new moodle_url('/local/rapidcmi5/index.php', $navigationparams));
}
if ($selectedproject) {
    $PAGE->navbar->add(format_string($selectedproject->name, true, ['context' => $context]), $cancelurl);
}
$PAGE->navbar->add($uploadtitle);
$form = new upload_package_form($uploadurl, ['project' => $selectedproject]);
if ($form->is_cancelled()) {
    redirect($cancelurl);
}
$uploaderror = null;

if ($data = $form->get_data()) {
    // Both uploads validate first and save nothing on failure, so errors are shown on the form.
    try {
        if ($selectedproject) {
            version_uploader::upload($projectid, (int) $data->packagefile, $data->version, $data->release_notes ?? '');
        } else {
            $uploaded = version_uploader::upload_package((int) $data->packagefile, $data->version,
                $data->release_notes ?? '', ['name' => $data->project_name ?? '', 'gitrepourl' => $data->git_repo_url ?? '']);
        }
    } catch (moodle_exception $e) {
        $uploaderror = $e->getMessage();
    }
    if ($uploaderror === null && $selectedproject) {
        redirect($cancelurl, get_string('versionuploaded', 'local_rapidcmi5'), null, notification::NOTIFY_SUCCESS);
    }
    if ($uploaderror === null) {
        // Deploy to the requested courses. A failed course must not hide the successful upload.
        $messages = [get_string('packageuploaded', 'local_rapidcmi5')];
        $deployfailed = false;
        $results = \local_rapidcmi5\deployment_manager::deploy_to_courses($uploaded->project->id, $uploaded->version->id,
            (int) $uploaded->version->libraryversionid, upload_package_form::parse_course_ids($data->deploy_course_ids ?? ''),
            $uploaded->project->name);
        foreach ($results as $result) {
            $deployfailed = $deployfailed || $result['status'] !== 'success';
            $messages[] = $result['status'] === 'success' ?
                get_string('deployedtocourse', 'local_rapidcmi5', $result['courseid']) :
                get_string('error:deployfailed', 'local_rapidcmi5', (object) $result);
        }
        redirect(new moodle_url('/local/rapidcmi5/project.php', ['id' => $uploaded->project->id]), implode(' ', $messages),
            null, $deployfailed ? notification::NOTIFY_WARNING : notification::NOTIFY_SUCCESS);
    }
}

echo \local_rapidcmi5\dashboard::header($course ? 'courses' : 'projects', 'uploadpageintro');
if ($uploaderror !== null) {
    // Already a formatted language string from our own moodle_exception; escaping again would
    // render entities literally.
    echo $OUTPUT->notification($uploaderror, notification::NOTIFY_ERROR);
}
echo html_writer::start_div('rcmi-form-panel');
$form->display();
echo html_writer::end_div();
echo \local_rapidcmi5\dashboard::footer();
