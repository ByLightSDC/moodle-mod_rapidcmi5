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
| `local_rapidcmi5_deploy_package` | write | Deploy a RapidCMI5 cmi5 package with project/version tracking. The project is identified by the course ID in the package's `cmi5.xml`; `project_identifier` is optional and ignored. |
| `local_rapidcmi5_list_projects` | read | List all projects |
| `local_rapidcmi5_get_project` | read | Get project details with versions and deployments |
| `local_rapidcmi5_get_project_versions` | read | Get version history for a project |
| `local_rapidcmi5_delete_project` | write | Delete a project and optionally its packages/activities |
| `local_rapidcmi5_upload_player` | write | Upload a player version ZIP |
| `local_rapidcmi5_list_player_versions` | read | List available player versions |
| `local_rapidcmi5_detect_player` | read | Detect embedded player version in a package or activity |
| `local_rapidcmi5_upgrade_player` | write | Upgrade the embedded player in a package or activity |

## Managing existing RapidCMI5 activities

Open **RapidCMI5 management > Unmanaged activities**, then choose **Add to managed projects**
and confirm. This requires the RapidCMI5 manage and deploy capabilities plus permission to
manage activities in the activity's course.

The content's cmi5 course identifier determines the project. An existing matching project
is reused without changing its name or current version. Otherwise, a new project is created.
Library-backed activities reuse their package; directly uploaded activities have their current
content copied into the library. The original activity, AU records, and learner progress are
preserved. Future deployments of this project to the course will update the adopted activity.

Imported revisions use an `imported-<library revision ID>` label unless a matching tracked
package revision already exists. Only one activity per project per course is supported;
conflicts are reported without replacing the existing association.

## Dashboard presentation

Management pages share the `dashboard` presentation helper, Mustache navigation and overview
components, and a scoped `styles.css` stylesheet that Moodle compiles into the active theme.
They use Moodle's existing Bootstrap components and icon library, with no external UI
framework or CDN dependencies. The shared layout provides consistent navigation, responsive
table panels, forms, cards, and focus states.

## Viewing projects by course

Open **RapidCMI5 management > Projects by course** to browse Moodle courses (classes)
containing managed RapidCMI5 activities. Search by course name or short name; each row shows
its distinct project count and managed activity count.

Open a course to see its projects grouped into cards, with each activity's installed version,
the project's current version, and update status. Links open the project details, individual
activity, or Moodle course. Deleted activities are excluded. This view uses the same
RapidCMI5 management permission as the existing project list and shows managed content only.

## Uploading a new project version

Open **RapidCMI5 management > Projects**, choose a project, and click **Upload new version**.
Choose the RapidCMI5 ZIP, enter a unique version label (up to 64 characters), and optionally
add release notes. The package's cmi5 course identifier must match the selected project.
The project name and identifier are fixed in this form.

The upload creates a new revision in the project's existing library package and makes it the
current project version. If the previous library package was deleted, a replacement package
is created. The upload and project-version record are saved together in a transaction.
Existing activities stay on their installed revision. Use **Update content** on a deployment
when you are ready to update that activity.

Project versions now store the exact library revision ID. Older records are resolved by their
package and hash only when the match is unambiguous. Missing or ambiguous revisions cannot
be used to update activities; upload a new version to establish an exact revision.

This change includes a Moodle plugin upgrade adding the nullable `libraryversionid` field.
Run the usual Moodle upgrade when deploying these changes to another environment.

### Local integration checks

On a disposable local Moodle site with both plugins installed, run from the Moodle root:

```sh
php local/rapidcmi5/cli/adoption_smoke.php --run
php local/rapidcmi5/cli/version_upload_smoke.php --run
php local/rapidcmi5/cli/course_browser_smoke.php --run
php local/rapidcmi5/cli/bulk_update_smoke.php --run
```

These are CLI scripts rather than PHPUnit tests, so CI will not pick them up. They cover
version uploads, validation, revision selection, form rendering, library and direct-upload
adoption, repeated requests, project reuse, conflicts, permissions, learner-data preservation,
action rendering, and failure rollback.

Fixture database changes are rolled back, although database sequences may advance and
temporary file content may remain until Moodle cleanup. Purge Moodle caches after adding the
new class and strings.

## License

This plugin is licensed under the [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.en.html).
