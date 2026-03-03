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

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/rapidcmi5/unmanaged.php'));
$PAGE->set_title(get_string('unmanagedactivities', 'local_rapidcmi5'));
$PAGE->set_heading(get_string('unmanagedactivities', 'local_rapidcmi5'));
$PAGE->set_pagelayout('admin');

// Find all cmi5 course_modules NOT tracked via deployments.
$sql = "SELECT cm.id AS cmid, cm.course, cm.instance, c.fullname AS coursename, cmi5.name AS activityname
        FROM {course_modules} cm
        JOIN {modules} m ON m.id = cm.module AND m.name = 'cmi5'
        JOIN {course} c ON c.id = cm.course
        JOIN {cmi5} cmi5 ON cmi5.id = cm.instance
        WHERE cm.id NOT IN (SELECT cmid FROM {local_rapidcmi5_deployments})
        ORDER BY c.fullname, cmi5.name";
$unmanagedcms = $DB->get_records_sql($sql);

$latestplayer = \local_rapidcmi5\player_manager::get_latest_player_version();
$allplayerversions = \local_rapidcmi5\player_manager::list_player_versions();
$actionurl = new moodle_url('/local/rapidcmi5/player_action.php');
$returnurl = (new moodle_url('/local/rapidcmi5/unmanaged.php'))->out(false);

// Build player version options for selector.
$playeroptions = [];
foreach ($allplayerversions as $pv) {
    $label = $pv->version;
    if ($latestplayer && $pv->id == $latestplayer->id) {
        $label .= ' (' . get_string('latestplayer', 'local_rapidcmi5') . ')';
    }
    $playeroptions[] = [
        'id' => $pv->id,
        'label' => $label,
    ];
}

$activities = [];
foreach ($unmanagedcms as $row) {
    $detection = \local_rapidcmi5\player_manager::detect_player_in_activity((int) $row->cmid);
    if (!$detection->is_rapidcmi5) {
        continue;
    }

    $hasupdate = false;
    $currentplayer = $detection->player_version ?? '';
    if ($latestplayer) {
        if (!empty($currentplayer) && $currentplayer !== $latestplayer->version) {
            $hasupdate = true;
        } else if (empty($currentplayer)) {
            $hasupdate = true;
            $currentplayer = 'unknown';
        }
    }

    $activities[] = [
        'cmid' => $row->cmid,
        'coursename' => $row->coursename,
        'activityname' => $row->activityname,
        'playerversion' => $currentplayer,
        'aucount' => $detection->au_count ?? 0,
        'hasplayerupdate' => $hasupdate,
        'hasplayerversions' => !empty($allplayerversions),
        'playeroptions' => $playeroptions,
        'actionurl' => $actionurl->out(false),
        'sesskey' => sesskey(),
        'returnurl' => $returnurl,
    ];
}

$templatedata = [
    'activities' => $activities,
    'hasactivities' => !empty($activities),
    'projectsurl' => (new moodle_url('/local/rapidcmi5/index.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_rapidcmi5/unmanaged_activities', $templatedata);
echo $OUTPUT->footer();
