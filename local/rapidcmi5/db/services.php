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

$functions = [
    'local_rapidcmi5_deploy_package' => [
        'classname' => 'local_rapidcmi5\external\deploy_package',
        'description' => 'Deploy a cmi5 package from the RapidCMI5 CLI with project/version tracking',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/rapidcmi5:deploy',
    ],
    'local_rapidcmi5_list_projects' => [
        'classname' => 'local_rapidcmi5\external\list_projects',
        'description' => 'List RapidCMI5 projects',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/rapidcmi5:manage',
    ],
    'local_rapidcmi5_get_project' => [
        'classname' => 'local_rapidcmi5\external\get_project',
        'description' => 'Get a RapidCMI5 project with versions and deployments',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/rapidcmi5:manage',
    ],
    'local_rapidcmi5_get_project_versions' => [
        'classname' => 'local_rapidcmi5\external\get_project_versions',
        'description' => 'Get version history for a RapidCMI5 project',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/rapidcmi5:manage',
    ],
    'local_rapidcmi5_delete_project' => [
        'classname' => 'local_rapidcmi5\external\delete_project',
        'description' => 'Delete a RapidCMI5 project and optionally its packages and activities',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/rapidcmi5:manage',
    ],
    'local_rapidcmi5_upload_player' => [
        'classname' => 'local_rapidcmi5\external\upload_player',
        'description' => 'Upload a RapidCMI5 player version ZIP',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/rapidcmi5:manage',
    ],
    'local_rapidcmi5_list_player_versions' => [
        'classname' => 'local_rapidcmi5\external\list_player_versions',
        'description' => 'List available RapidCMI5 player versions',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/rapidcmi5:manage',
    ],
    'local_rapidcmi5_detect_player' => [
        'classname' => 'local_rapidcmi5\external\detect_player',
        'description' => 'Detect embedded RapidCMI5 player version in a package or activity',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/rapidcmi5:manage',
    ],
    'local_rapidcmi5_upgrade_player' => [
        'classname' => 'local_rapidcmi5\external\upgrade_player',
        'description' => 'Upgrade the embedded player in a package or activity',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'local/rapidcmi5:deploy',
    ],
];

$services = [
    'RapidCMI5 Integration' => [
        'functions' => [
            'local_rapidcmi5_deploy_package',
            'local_rapidcmi5_list_projects',
            'local_rapidcmi5_get_project',
            'local_rapidcmi5_get_project_versions',
            'local_rapidcmi5_delete_project',
            'local_rapidcmi5_upload_player',
            'local_rapidcmi5_list_player_versions',
            'local_rapidcmi5_detect_player',
            'local_rapidcmi5_upgrade_player',
            'core_webservice_get_site_info',
            'core_course_get_courses',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'local_rapidcmi5',
        'uploadfiles' => 1,
        'downloadfiles' => 1,
    ],
];
