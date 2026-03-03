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
require_sesskey();
$context = context_system::instance();
require_capability('local/rapidcmi5:deploy', $context);

$action = required_param('action', PARAM_ALPHA);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

switch ($action) {
    case 'upgradeplayer':
        // Upgrade player for a single deployment or unmanaged activity.
        $deploymentid = optional_param('deploymentid', 0, PARAM_INT);
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $playerversionid = optional_param('playerversionid', 0, PARAM_INT);

        if ($deploymentid) {
            $deployment = $DB->get_record('local_rapidcmi5_deployments', ['id' => $deploymentid], '*', MUST_EXIST);
            $cmid = (int) $deployment->cmid;
        } else if (!$cmid) {
            throw new moodle_exception('invalidaction');
        }

        // Use specified version or fall back to latest.
        if ($playerversionid) {
            $player = \local_rapidcmi5\player_manager::get_player_version($playerversionid);
        } else {
            $player = \local_rapidcmi5\player_manager::get_latest_player_version();
        }

        if (!$player) {
            throw new moodle_exception('noplayerversions', 'local_rapidcmi5');
        }

        $result = \local_rapidcmi5\player_manager::upgrade_activity_player(
            $cmid, (int) $player->id
        );

        if ($result->success) {
            \core\notification::success(get_string('playerupgraded', 'local_rapidcmi5'));
        }
        break;

    case 'upgradeall':
        // Upgrade all library packages using an old player version to the latest.
        $oldplayerid = required_param('oldplayerid', PARAM_INT);
        $oldplayer = \local_rapidcmi5\player_manager::get_player_version($oldplayerid);
        $latestplayer = \local_rapidcmi5\player_manager::get_latest_player_version();

        if (!$latestplayer || !$oldplayer) {
            throw new moodle_exception('noplayerversions', 'local_rapidcmi5');
        }

        if ($oldplayer->id == $latestplayer->id) {
            \core\notification::info(get_string('noplayerupgrade', 'local_rapidcmi5'));
            break;
        }

        // Find all library package versions whose player-manifest.json matches the old player version.
        $fs = get_file_storage();
        $syscontext = context_system::instance();
        $upgraded = 0;

        // Get all library_content files named player-manifest.json.
        $sql = "SELECT DISTINCT f.itemid
                FROM {files} f
                WHERE f.component = 'mod_cmi5'
                  AND f.filearea = 'library_content'
                  AND f.filename = 'player-manifest.json'
                  AND f.filepath = '/'";
        $versionids = $DB->get_fieldset_sql($sql);

        foreach ($versionids as $versionid) {
            $manifest = $fs->get_file($syscontext->id, 'mod_cmi5', 'library_content',
                (int) $versionid, '/', 'player-manifest.json');
            if (!$manifest || $manifest->is_directory()) {
                continue;
            }
            $data = json_decode($manifest->get_content(), true);
            $currentver = $data['playerVersion'] ?? '';

            if ($currentver === $oldplayer->version) {
                \local_rapidcmi5\player_manager::upgrade_package_player(
                    (int) $versionid, (int) $latestplayer->id
                );
                $upgraded++;
            }
        }

        \core\notification::success(
            get_string('allupgraded', 'local_rapidcmi5', $latestplayer->version) .
            " ({$upgraded} packages)"
        );
        break;

    case 'setlibraryplayer':
        // Set/upgrade player on a library package version.
        $libraryversionid = required_param('libraryversionid', PARAM_INT);
        $playerversionid = required_param('playerversionid', PARAM_INT);

        $player = \local_rapidcmi5\player_manager::get_player_version($playerversionid);
        if (!$player) {
            throw new moodle_exception('noplayerversions', 'local_rapidcmi5');
        }

        $result = \local_rapidcmi5\player_manager::upgrade_package_player(
            $libraryversionid, (int) $player->id
        );

        if ($result->success) {
            \core\notification::success(get_string('playerupgraded', 'local_rapidcmi5'));
        }
        break;

    case 'deleteproject':
        require_capability('local/rapidcmi5:manage', $context);
        $projectid = required_param('projectid', PARAM_INT);
        \local_rapidcmi5\project_manager::delete_project($projectid);
        \core\notification::success(get_string('projectdeleted', 'local_rapidcmi5'));
        $returnurl = new moodle_url('/local/rapidcmi5/index.php');
        break;

    case 'updatecontent':
        // Redeploy latest project version to a deployment.
        $deploymentid = required_param('deploymentid', PARAM_INT);
        $deployment = $DB->get_record('local_rapidcmi5_deployments', ['id' => $deploymentid], '*', MUST_EXIST);
        $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $deployment->projectid], '*', MUST_EXIST);

        if (!$project->currentversionid) {
            throw new moodle_exception('noversions', 'local_rapidcmi5');
        }

        $latestver = $DB->get_record('local_rapidcmi5_versions', ['id' => $project->currentversionid], '*', MUST_EXIST);

        // Get the library version ID from the rapidcmi5 version's package.
        // The version record stores the content library packageid; get its latest version.
        $libraryversion = $DB->get_record_sql(
            "SELECT * FROM {cmi5_package_versions}
             WHERE packageid = :packageid
             ORDER BY timecreated DESC LIMIT 1",
            ['packageid' => $latestver->packageid]
        );

        if (!$libraryversion) {
            throw new moodle_exception('error:projectnotfound', 'local_rapidcmi5');
        }

        // Use deployment_manager to update the activity.
        \local_rapidcmi5\deployment_manager::deploy_to_course(
            (int) $project->id,
            (int) $latestver->id,
            (int) $libraryversion->id,
            (int) $deployment->courseid,
            $project->name
        );

        \core\notification::success(
            get_string('contentupdated', 'local_rapidcmi5', $latestver->versionnumber)
        );
        break;

    default:
        throw new moodle_exception('invalidaction');
}

// Redirect back.
if (empty($returnurl)) {
    $returnurl = new moodle_url('/local/rapidcmi5/index.php');
} else {
    $returnurl = new moodle_url($returnurl);
}
redirect($returnurl);
