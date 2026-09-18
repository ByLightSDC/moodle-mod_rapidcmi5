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

use core\notification;
use local_rapidcmi5\output\updates_page;

require_login();
$context = context_system::instance();
require_capability('local/rapidcmi5:manage', $context);
$canupdate = has_capability('local/rapidcmi5:deploy', $context);
$filters = [
    'search' => trim(optional_param('search', '', PARAM_TEXT)),
    'projectid' => optional_param('projectid', 0, PARAM_INT),
];
$title = get_string('updates', 'local_rapidcmi5');
$PAGE->set_context($context);
$PAGE->set_url(updates_page::url($filters));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->navbar->add(get_string('manage_dashboard', 'local_rapidcmi5'), new moodle_url('/local/rapidcmi5/manage.php'));
$PAGE->navbar->add($title);

if (data_submitted() && confirm_sesskey()) {
    require_capability('local/rapidcmi5:deploy', $context);
    $result = \local_rapidcmi5\bulk_updater::update(optional_param_array('deploymentids', [], PARAM_INT));
    if ($result->updated) {
        notification::success(get_string('updatesupdated', 'local_rapidcmi5', count($result->updated)));
    }
    if ($result->skipped) {
        notification::info(get_string('updatesskipped', 'local_rapidcmi5', count($result->skipped)));
    }
    foreach ($result->failed as $failure) {
        notification::error(get_string('updatesfailed', 'local_rapidcmi5', $failure));
    }
    // Failed activities stay ticked so they can be retried.
    redirect(updates_page::url($filters + ['selected' => array_column($result->failed, 'deploymentid')]));
}

$data = updates_page::data($filters, optional_param_array('selected', [], PARAM_INT), $canupdate);
echo \local_rapidcmi5\dashboard::header('updates', 'updates_desc');
if (!$canupdate && $data['hasprojects']) {
    echo $OUTPUT->notification(get_string('updatesnopermission', 'local_rapidcmi5'), notification::INFO);
}
echo $OUTPUT->render_from_template('local_rapidcmi5/updates', $data);
echo \local_rapidcmi5\dashboard::footer();
