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

use local_rapidcmi5\course_browser;
use local_rapidcmi5\dashboard;

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

// Counts are deferred so that adding a card here does not add a query until it is displayed.
$cards = [
    dashboard::overview_card('index.php', 'projects', 'i/folder',
        fn() => $DB->count_records('local_rapidcmi5_projects'), 'dashboardprojectcount'),
    dashboard::overview_card('courses.php', 'projectsbycourse', 'i/course',
        fn() => course_browser::count_courses(), 'dashboardcoursecount'),
    dashboard::overview_card('player.php', 'playerversions', 'i/settings',
        fn() => $DB->count_records('local_rapidcmi5_player_versions'), 'dashboardplayercount'),
    dashboard::overview_card('updates.php', 'updates', 'i/reload',
        fn() => \local_rapidcmi5\update_finder::count(), 'dashboardupdatecount'),
    dashboard::overview_card('unmanaged.php', 'unmanagedactivities', 'i/search'),
];
echo dashboard::header('overview', 'dashboardintro');
echo $OUTPUT->render_from_template('local_rapidcmi5/dashboard_overview', ['cards' => $cards]);
echo dashboard::footer();
