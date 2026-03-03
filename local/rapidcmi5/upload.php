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

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/rapidcmi5/upload.php'));
$PAGE->set_title(get_string('uploadpackage', 'local_rapidcmi5'));
$PAGE->set_heading(get_string('uploadpackage', 'local_rapidcmi5'));
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(get_string('projects', 'local_rapidcmi5'),
    new moodle_url('/local/rapidcmi5/index.php'));
$PAGE->navbar->add(get_string('uploadpackage', 'local_rapidcmi5'));

$form = new \local_rapidcmi5\form\upload_package_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rapidcmi5/index.php'));
}

if ($data = $form->get_data()) {
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
        $data->release_notes ?? ''
    );

    // Deploy to courses if specified.
    $deployresults = [];
    if (!empty($data->deploy_course_ids)) {
        $courseids = array_map('intval', array_filter(array_map('trim', explode(',', $data->deploy_course_ids))));
        foreach ($courseids as $courseid) {
            try {
                \local_rapidcmi5\deployment_manager::deploy_to_course(
                    $project->id,
                    $versionrecord->id,
                    $libraryversionid,
                    $courseid,
                    $project->name,
                    0
                );
                $deployresults[] = get_string('deployedtocourse', 'local_rapidcmi5', $courseid);
            } catch (\Exception $e) {
                $deployresults[] = get_string('error:coursenotfound', 'local_rapidcmi5', $courseid) .
                    ': ' . $e->getMessage();
            }
        }
    }

    // Redirect to project detail page.
    $url = new moodle_url('/local/rapidcmi5/project.php', ['id' => $project->id]);
    redirect($url, get_string('packageuploaded', 'local_rapidcmi5'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
