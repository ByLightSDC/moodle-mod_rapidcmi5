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
$cmid = required_param('cmid', PARAM_INT);
$source = \local_rapidcmi5\activity_manager::get_source($cmid);
$returnurl = new moodle_url('/local/rapidcmi5/unmanaged.php');
$url = new moodle_url('/local/rapidcmi5/adopt.php', ['cmid' => $cmid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('addtomanaged', 'local_rapidcmi5'));
$PAGE->set_heading(get_string('addtomanaged', 'local_rapidcmi5'));

if (data_submitted()) {
    require_sesskey();
    try {
        $deployment = \local_rapidcmi5\activity_manager::adopt($cmid);
    } catch (moodle_exception $e) {
        redirect($returnurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(new moodle_url('/local/rapidcmi5/project.php', ['id' => $deployment->projectid]),
        get_string('activitymanaged', 'local_rapidcmi5'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$project = \local_rapidcmi5\project_manager::get_project_by_identifier($source->identifier);
$details = (object) ['activity' => format_string($source->instance->name),
    'project' => format_string($project ? $project->name : $source->instance->name),
    'identifier' => s($source->identifier)];
echo \local_rapidcmi5\dashboard::header('unmanaged', 'adoptpageintro');
echo $OUTPUT->confirm(get_string('addtomanagedconfirm', 'local_rapidcmi5', $details),
    new single_button($url, get_string('addtomanaged', 'local_rapidcmi5'), 'post'), $returnurl);
echo \local_rapidcmi5\dashboard::footer();
