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

$id = required_param('id', PARAM_INT);

$project = \local_rapidcmi5\project_manager::get_project($id);
if (!$project) {
    throw new moodle_exception('error:projectnotfound', 'local_rapidcmi5');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/rapidcmi5/project.php', ['id' => $id]));
$PAGE->set_title($project->name);
$PAGE->set_heading($project->name);
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(
    get_string('projects', 'local_rapidcmi5'),
    new moodle_url('/local/rapidcmi5/index.php')
);
$PAGE->navbar->add($project->name);

$versions = \local_rapidcmi5\project_manager::get_versions($id);
$deployments = \local_rapidcmi5\project_manager::get_deployments($id);

// Check if the library package still exists.
$packagemissing = false;
if (!empty($project->currentpackageid)) {
    if (!$DB->record_exists('cmi5_packages', ['id' => $project->currentpackageid])) {
        $packagemissing = true;
    }
}

// Clean up deployments referencing deleted course modules.
$validdeployments = [];
foreach ($deployments as $d) {
    if ($DB->record_exists('course_modules', ['id' => $d->cmid])) {
        $validdeployments[] = $d;
    } else {
        // Activity was deleted externally — clean up the orphaned record.
        $DB->delete_records('local_rapidcmi5_deployments', ['id' => $d->id]);
    }
}
$deployments = $validdeployments;

$latestplayer = \local_rapidcmi5\player_manager::get_latest_player_version();
$allplayerversions = \local_rapidcmi5\player_manager::list_player_versions();
$actionurl = new moodle_url('/local/rapidcmi5/player_action.php');
$returnurl = (new moodle_url('/local/rapidcmi5/project.php', ['id' => $id]))->out(false);

$versiondata = [];
foreach ($versions as $v) {
    $iscurrent = ($project->currentversionid == $v->id);

    // Find the library package version for this project version.
    $libraryversion = $DB->get_record_sql(
        "SELECT * FROM {cmi5_package_versions}
         WHERE packageid = :packageid
         ORDER BY timecreated DESC LIMIT 1",
        ['packageid' => $v->packageid]
    );

    // Detect player version in the library package.
    $playerversion = '';
    $libraryversionid = 0;
    if ($libraryversion) {
        $libraryversionid = (int) $libraryversion->id;
        $detection = \local_rapidcmi5\player_manager::detect_player_in_library($libraryversionid);
        if ($detection->is_rapidcmi5 && !empty($detection->player_version)) {
            $playerversion = $detection->player_version;
        } else if ($detection->is_rapidcmi5) {
            $playerversion = 'unknown';
        }
    }

    // Build player version options for selector.
    $versionplayeroptions = [];
    foreach ($allplayerversions as $pv) {
        $label = $pv->version;
        if ($latestplayer && $pv->id == $latestplayer->id) {
            $label .= ' (' . get_string('latestplayer', 'local_rapidcmi5') . ')';
        }
        $versionplayeroptions[] = [
            'id' => $pv->id,
            'label' => $label,
        ];
    }

    $versiondata[] = [
        'id' => $v->id,
        'versionnumber' => $v->versionnumber,
        'commithash' => $v->commithash ?? '',
        'hascommit' => !empty($v->commithash),
        'shortcommit' => !empty($v->commithash) ? substr($v->commithash, 0, 7) : '',
        'buildtimestamp' => $v->buildtimestamp ? userdate($v->buildtimestamp) : '',
        'releasenotes' => $v->releasenotes ?? '',
        'hasreleasenotes' => !empty($v->releasenotes),
        'timecreated' => userdate($v->timecreated),
        'iscurrent' => $iscurrent,
        'playerversion' => $playerversion,
        'libraryversionid' => $libraryversionid,
        'hasplayerversions' => !empty($allplayerversions),
        'playeroptions' => $versionplayeroptions,
        'actionurl' => $actionurl->out(false),
        'sesskey' => sesskey(),
        'returnurl' => $returnurl,
    ];
}

$deploymentdata = [];
foreach ($deployments as $d) {
    $coursename = '';
    $course = $DB->get_record('course', ['id' => $d->courseid], 'fullname');
    if ($course) {
        $coursename = $course->fullname;
    }
    $ver = $DB->get_record('local_rapidcmi5_versions', ['id' => $d->versionid]);
    $versionnumber = $ver ? $ver->versionnumber : '?';
    $isoutdated = ($project->currentversionid && $d->versionid != $project->currentversionid);
    $currentver = null;
    if ($isoutdated && $project->currentversionid) {
        $cv = $DB->get_record('local_rapidcmi5_versions', ['id' => $project->currentversionid]);
        $currentver = $cv ? $cv->versionnumber : null;
    }

    // Detect player version for this deployment.
    $playerversion = '';
    $hasplayerupdate = false;
    $latestplayerversion = $latestplayer ? $latestplayer->version : '';
    try {
        $status = \local_rapidcmi5\update_checker::check_activity((int) $d->cmid);
        $playerversion = $status->current_player;
        $hasplayerupdate = $status->has_player_update;
    } catch (\Exception $e) {
        $playerversion = '?';
    }

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

    $deploymentdata[] = [
        'id' => $d->id,
        'courseid' => $d->courseid,
        'coursename' => $coursename,
        'cmid' => $d->cmid,
        'versionnumber' => $versionnumber,
        'timemodified' => userdate($d->timemodified),
        'courseurl' => (new moodle_url('/course/view.php', ['id' => $d->courseid]))->out(false),
        'isoutdated' => $isoutdated,
        'latestversion' => $currentver ?? '',
        'playerversion' => $playerversion,
        'latestplayerversion' => $latestplayerversion,
        'hasplayerupdate' => $hasplayerupdate,
        'hascontentupdate' => $isoutdated,
        'hasplayerversions' => !empty($allplayerversions),
        'playeroptions' => $playeroptions,
        'actionurl' => $actionurl->out(false),
        'sesskey' => sesskey(),
        'returnurl' => $returnurl,
    ];
}

$templatedata = [
    'project' => [
        'id' => $project->id,
        'name' => $project->name,
        'identifier' => $project->identifier,
        'gitrepourl' => $project->gitrepourl ?? '',
        'hasgitrepo' => !empty($project->gitrepourl),
        'description' => $project->description ?? '',
        'hasdescription' => !empty($project->description),
        'timecreated' => userdate($project->timecreated),
        'timemodified' => userdate($project->timemodified),
    ],
    'versions' => $versiondata,
    'hasversions' => !empty($versiondata),
    'deployments' => $deploymentdata,
    'hasdeployments' => !empty($deploymentdata),
    'packagemissing' => $packagemissing,
    'backurl' => (new moodle_url('/local/rapidcmi5/index.php'))->out(false),
    'actionurl' => $actionurl->out(false),
    'sesskey' => sesskey(),
];

echo $OUTPUT->header();
echo html_writer::link(
    new moodle_url('/local/rapidcmi5/manage.php'),
    get_string('backtomanagement', 'local_rapidcmi5'),
    ['class' => 'btn btn-secondary mb-3']
);
$form->display();
echo $OUTPUT->render_from_template('local_rapidcmi5/project_detail', $templatedata);
echo $OUTPUT->footer();
