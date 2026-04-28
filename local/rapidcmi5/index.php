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
$PAGE->set_url(new moodle_url('/local/rapidcmi5/index.php'));
$PAGE->set_title(get_string('projects', 'local_rapidcmi5'));
$PAGE->set_heading(get_string('projects', 'local_rapidcmi5'));
$PAGE->set_pagelayout('admin');

$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;

$projects = \local_rapidcmi5\project_manager::list_projects($search, $page * $perpage, $perpage);
$total = \local_rapidcmi5\project_manager::count_projects($search);

// Build template data.
$projectdata = [];
foreach ($projects as $project) {
    $currentversion = '';
    if ($project->currentversionid) {
        $ver = $DB->get_record('local_rapidcmi5_versions', ['id' => $project->currentversionid]);
        if ($ver) {
            $currentversion = $ver->versionnumber;
        }
    }
    $projectdata[] = [
        'id' => $project->id,
        'name' => $project->name,
        'identifier' => $project->identifier,
        'currentversion' => $currentversion,
        'gitrepourl' => $project->gitrepourl ?? '',
        'hasgitrepo' => !empty($project->gitrepourl),
        'timemodified' => userdate($project->timemodified),
        'detailurl' => (new moodle_url('/local/rapidcmi5/project.php', ['id' => $project->id]))->out(false),
    ];
}

$templatedata = [
    'projects' => $projectdata,
    'hasprojects' => !empty($projectdata),
    'search' => $search,
    'searchurl' => (new moodle_url('/local/rapidcmi5/index.php'))->out(false),
    'uploadurl' => (new moodle_url('/local/rapidcmi5/upload.php'))->out(false),
];

echo $OUTPUT->header();
echo html_writer::link(
    new moodle_url('/local/rapidcmi5/manage.php'),
    get_string('backtomanagement', 'local_rapidcmi5'),
    ['class' => 'btn btn-secondary mb-3']
);
echo $OUTPUT->render_from_template('local_rapidcmi5/projects_list', $templatedata);

// Paging bar.
echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/local/rapidcmi5/index.php', ['search' => $search]));

echo $OUTPUT->footer();
