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
require_capability('local/rapidcmi5:deploy', $context);

$projectid = optional_param('projectid', 0, PARAM_INT);
$courseid = $projectid ? optional_param('courseid', 0, PARAM_INT) : 0;
$navigationparams = [
    'search' => trim(optional_param('search', '', PARAM_TEXT)),
    'page' => max(0, optional_param('page', 0, PARAM_INT)),
];
if ($courseid) {
    $navigationparams['courseid'] = $courseid;
}
$selectedproject = $projectid ? $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid], '*', MUST_EXIST) : null;
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
if ($courseid) {
    $course = get_course($courseid);
    $PAGE->navbar->add(get_string('projectsbycourse', 'local_rapidcmi5'),
        new moodle_url('/local/rapidcmi5/courses.php', array_diff_key($navigationparams, ['courseid' => true])));
    $PAGE->navbar->add(format_string($course->fullname, true, ['context' => context_course::instance($courseid)]),
        new moodle_url('/local/rapidcmi5/courses.php', $navigationparams));
} else {
    $PAGE->navbar->add(get_string('projects', 'local_rapidcmi5'),
        new moodle_url('/local/rapidcmi5/index.php', $navigationparams));
}
if ($selectedproject) {
    $PAGE->navbar->add($selectedproject->name, $cancelurl);
}
$PAGE->navbar->add($uploadtitle);
$form = new \local_rapidcmi5\form\upload_package_form($uploadurl, ['project' => $selectedproject]);
if ($form->is_cancelled()) {
    redirect($cancelurl);
}
$uploaderror = null;

if ($data = $form->get_data()) {
    if ($selectedproject) {
        try {
            \local_rapidcmi5\version_uploader::upload($projectid, (int) $data->packagefile,
                $data->version, $data->release_notes ?? '');
        } catch (moodle_exception $e) {
            $uploaderror = $e->getMessage();
        }
        if ($uploaderror === null) {
            redirect($cancelurl, get_string('versionuploaded', 'local_rapidcmi5'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
    } else {
        // Get the draft item ID from the filepicker.
        $draftitemid = file_get_submitted_draft_itemid('packagefile');

        // Get or create the project.
        $result = \local_rapidcmi5\project_manager::get_or_create_project(
            $data->project_identifier,
            $data->project_name ?? '',
            $data->git_repo_url ?? ''
        );
        $project = $result->project;

        // Upload to content library.
        $existingpackageid = !empty($project->currentpackageid) ? (int) $project->currentpackageid : 0;
        $libraryversion = \mod_cmi5\content_library::upload_package_from_draft(
            $draftitemid,
            $data->project_name ?: $data->project_identifier,
            '',
            0,
            $existingpackageid
        );
        $packageid = (int) $libraryversion->packageid;
        $libraryversionid = (int) $libraryversion->id;

        // Create version record.
        $versionrecord = \local_rapidcmi5\project_manager::create_version(
            $project->id,
            $data->version,
            $packageid,
            '',
            0,
            $libraryversion->sha256hash ?? '',
            $data->release_notes ?? '',
            $libraryversionid
        );

        // Deploy to courses if specified. A failed course must not hide the successful upload.
        $deployresults = [];
        $deployfailed = false;
        if (!empty($data->deploy_course_ids)) {
            $deploycourseids = array_map('intval',
                array_filter(array_map('trim', explode(',', $data->deploy_course_ids))));
            foreach ($deploycourseids as $deploycourseid) {
                try {
                    \local_rapidcmi5\deployment_manager::deploy_to_course(
                        $project->id,
                        $versionrecord->id,
                        $libraryversionid,
                        $deploycourseid,
                        $project->name,
                        0
                    );
                    $deployresults[] = get_string('deployedtocourse', 'local_rapidcmi5', $deploycourseid);
                } catch (\Exception $e) {
                    $deployfailed = true;
                    $deployresults[] = get_string('error:coursenotfound', 'local_rapidcmi5', $deploycourseid) .
                        ': ' . $e->getMessage();
                }
            }
        }

        // Redirect to project detail page, reporting each requested deployment.
        $url = new moodle_url('/local/rapidcmi5/project.php', ['id' => $project->id]);
        $message = implode(' ', array_merge([get_string('packageuploaded', 'local_rapidcmi5')], $deployresults));
        redirect($url, $message, null, $deployfailed ?
            \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo \local_rapidcmi5\dashboard::header($courseid ? 'courses' : 'projects', 'uploadpageintro');
if ($uploaderror !== null) {
    // Already a formatted language string from our own moodle_exception; escaping again would
    // render entities literally.
    echo $OUTPUT->notification($uploaderror, \core\output\notification::NOTIFY_ERROR);
}
echo html_writer::start_div('rcmi-form-panel');
$form->display();
echo html_writer::end_div();
echo \local_rapidcmi5\dashboard::footer();
