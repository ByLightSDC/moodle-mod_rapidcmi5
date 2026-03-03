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

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Create a category folder for RapidCMI5 under Local plugins.
    $ADMIN->add('localplugins', new admin_category(
        'local_rapidcmi5',
        get_string('pluginname', 'local_rapidcmi5')
    ));

    $ADMIN->add('local_rapidcmi5', new admin_externalpage(
        'local_rapidcmi5_projects',
        get_string('projects', 'local_rapidcmi5'),
        new moodle_url('/local/rapidcmi5/index.php'),
        'local/rapidcmi5:manage'
    ));
    $ADMIN->add('local_rapidcmi5', new admin_externalpage(
        'local_rapidcmi5_player',
        get_string('playerversions', 'local_rapidcmi5'),
        new moodle_url('/local/rapidcmi5/player.php'),
        'local/rapidcmi5:manage'
    ));
    $ADMIN->add('local_rapidcmi5', new admin_externalpage(
        'local_rapidcmi5_unmanaged',
        get_string('unmanagedactivities', 'local_rapidcmi5'),
        new moodle_url('/local/rapidcmi5/unmanaged.php'),
        'local/rapidcmi5:manage'
    ));
}
