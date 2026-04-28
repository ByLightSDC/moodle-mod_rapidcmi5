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
$PAGE->set_url(new moodle_url('/local/rapidcmi5/player.php'));
$PAGE->set_title(get_string('playerversions', 'local_rapidcmi5'));
$PAGE->set_heading(get_string('playerversions', 'local_rapidcmi5'));
$PAGE->set_pagelayout('admin');

// Handle upload form submission.
$form = new \local_rapidcmi5\form\upload_player_form();
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rapidcmi5/player.php'));
} else if ($data = $form->get_data()) {
    $draftitemid = file_get_submitted_draft_itemid('playerfile');
    $result = \local_rapidcmi5\player_manager::upload_player($draftitemid, $data->version);
    \core\notification::success(get_string('playeruploaded', 'local_rapidcmi5', $data->version));
    redirect(new moodle_url('/local/rapidcmi5/player.php'));
}

$versions = \local_rapidcmi5\player_manager::list_player_versions();
$latest = \local_rapidcmi5\player_manager::get_latest_player_version();
$latestid = $latest ? $latest->id : 0;

// Count library packages using each player version by scanning player-manifest.json files.
$packagecounts = [];
$fs = get_file_storage();
$syscontext = context_system::instance();
$sql = "SELECT DISTINCT f.itemid
        FROM {files} f
        WHERE f.component = 'mod_cmi5'
          AND f.filearea = 'library_content'
          AND f.filename = 'player-manifest.json'
          AND f.filepath = '/'";
$libversionids = $DB->get_fieldset_sql($sql);
foreach ($libversionids as $lvid) {
    $manifest = $fs->get_file($syscontext->id, 'mod_cmi5', 'library_content', (int) $lvid, '/', 'player-manifest.json');
    if ($manifest && !$manifest->is_directory()) {
        $data = json_decode($manifest->get_content(), true);
        $pver = $data['playerVersion'] ?? '';
        if (!empty($pver)) {
            $packagecounts[$pver] = ($packagecounts[$pver] ?? 0) + 1;
        }
    }
}

$actionurl = new moodle_url('/local/rapidcmi5/player_action.php');
$returnurl = (new moodle_url('/local/rapidcmi5/player.php'))->out(false);

$versiondata = [];
foreach ($versions as $v) {
    $manifest = json_decode($v->manifest, true);
    $filecount = !empty($manifest['files']) ? count($manifest['files']) : 0;
    $islatest = ($v->id == $latestid);
    $pkgcount = $packagecounts[$v->version] ?? 0;

    $versiondata[] = [
        'id' => $v->id,
        'version' => $v->version,
        'sha256hash' => $v->sha256hash ? substr($v->sha256hash, 0, 12) . '...' : '',
        'filecount' => $filecount,
        'timecreated' => userdate($v->timecreated),
        'islatest' => $islatest,
        'packagecount' => $pkgcount,
        'haspackages' => $pkgcount > 0,
        'canupgrade' => !$islatest && $pkgcount > 0,
        'upgradeallurl' => $actionurl->out(false),
        'sesskey' => sesskey(),
        'returnurl' => $returnurl,
    ];
}

$templatedata = [
    'versions' => $versiondata,
    'hasversions' => !empty($versiondata),
    'projectsurl' => (new moodle_url('/local/rapidcmi5/index.php'))->out(false),
];

echo $OUTPUT->header();
echo html_writer::link(
    new moodle_url('/local/rapidcmi5/manage.php'),
    get_string('backtomanagement', 'local_rapidcmi5'),
    ['class' => 'btn btn-secondary mb-3']
);
$form->display();
echo $OUTPUT->render_from_template('local_rapidcmi5/player_versions', $templatedata);
echo $OUTPUT->footer();
