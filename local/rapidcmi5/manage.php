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

/**
 * RapidCMI5 management dashboard — hub page for all RapidCMI5 admin areas.
 *
 * @package    local_rapidcmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();

if (!has_capability('local/rapidcmi5:manage', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access RapidCMI5 management');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/rapidcmi5/manage.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manage_dashboard', 'local_rapidcmi5'));
$PAGE->set_heading(get_string('manage_dashboard', 'local_rapidcmi5'));

echo $OUTPUT->header();

$items = [
    [
        'url'   => new moodle_url('/local/rapidcmi5/index.php'),
        'icon'  => 'i/settings',
        'title' => get_string('projects', 'local_rapidcmi5'),
        'desc'  => get_string('projects_desc', 'local_rapidcmi5'),
    ],
    [
        'url'   => new moodle_url('/local/rapidcmi5/player.php'),
        'icon'  => 'i/settings',
        'title' => get_string('playerversions', 'local_rapidcmi5'),
        'desc'  => get_string('playerversions_desc', 'local_rapidcmi5'),
    ],
    [
        'url'   => new moodle_url('/local/rapidcmi5/unmanaged.php'),
        'icon'  => 'i/settings',
        'title' => get_string('unmanagedactivities', 'local_rapidcmi5'),
        'desc'  => get_string('unmanagedactivities_desc', 'local_rapidcmi5'),
    ],
];

echo html_writer::start_div('container-fluid mt-3');
echo html_writer::start_div('row');
foreach ($items as $item) {
    echo html_writer::start_div('col-sm-6 col-lg-4 col-xl-3 mb-3');
    echo html_writer::start_tag('a', [
        'href'  => $item['url']->out(false),
        'class' => 'card h-100 text-decoration-none',
    ]);
    echo html_writer::start_div('card-body d-flex flex-column');
    echo html_writer::tag('h5', $item['title'], ['class' => 'card-title']);
    echo html_writer::tag('p', $item['desc'], ['class' => 'card-text text-muted small']);
    echo html_writer::end_div();
    echo html_writer::end_tag('a');
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
