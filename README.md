# RapidCMI5 Local Plugin for Moodle (`local_rapidcmi5`)

Bridge plugin between the [RapidCMI5 Electron CLI](https://github.com/bylightsdc/RapidCMI5) and the `mod_cmi5` content library. Provides project/version tracking, automated course deployment, and player version management.

## Requirements

- **Moodle 4.5+** (version 2024100700 or later)
- **[mod_cmi5](https://github.com/bylightsdc/moodle-mod_cmi5)** v2026022600 or later — the self-contained cmi5 activity module that provides the content library and cmi5 player

`mod_cmi5` **must be installed first**. Moodle will refuse to install this plugin without it.

## Installation

1. Install `mod_cmi5` if you haven't already — copy it to `mod/cmi5/` in your Moodle directory.
2. Copy the `local/rapidcmi5/` directory from this repo into your Moodle `local/` directory.
3. Visit **Site administration > Notifications** (or run `php admin/cli/upgrade.php`) to complete the install.

## Features

- **Project tracking** — organizes cmi5 packages into projects with version history
- **Package deployment** — deploys cmi5 packages as Moodle course activities with a single web service call
- **Player version management** — upload, detect, and upgrade the embedded cmi5 player across packages and activities
- **Admin UI** — manage projects, player versions, and unmanaged activities from Site administration > Local plugins > RapidCMI5
- **Web service API** — 9 endpoints exposed via the "RapidCMI5 Integration" external service for CLI automation

## Capabilities

| Capability | Description | Default role |
|---|---|---|
| `local/rapidcmi5:manage` | Manage projects, view admin pages, upload player versions | Manager |
| `local/rapidcmi5:deploy` | Deploy packages and upgrade players in activities | Manager |

## Web Service Endpoints

| Function | Type | Description |
|---|---|---|
| `local_rapidcmi5_deploy_package` | write | Deploy a cmi5 package with project/version tracking |
| `local_rapidcmi5_list_projects` | read | List all projects |
| `local_rapidcmi5_get_project` | read | Get project details with versions and deployments |
| `local_rapidcmi5_get_project_versions` | read | Get version history for a project |
| `local_rapidcmi5_delete_project` | write | Delete a project and optionally its packages/activities |
| `local_rapidcmi5_upload_player` | write | Upload a player version ZIP |
| `local_rapidcmi5_list_player_versions` | read | List available player versions |
| `local_rapidcmi5_detect_player` | read | Detect embedded player version in a package or activity |
| `local_rapidcmi5_upgrade_player` | write | Upgrade the embedded player in a package or activity |

## License

This plugin is licensed under the [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.en.html).
