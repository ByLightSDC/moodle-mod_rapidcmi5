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

$string['pluginname'] = 'RapidCMI5 Integration';
$string['rapidcmi5:manage'] = 'Manage RapidCMI5 projects';
$string['rapidcmi5:deploy'] = 'Deploy RapidCMI5 packages';
$string['privacy:metadata'] = 'The RapidCMI5 plugin stores project and deployment metadata but does not store personal user data beyond creator IDs.';
$string['projects'] = 'RapidCMI5 Projects';
$string['project'] = 'Project';
$string['projectname'] = 'Project name';
$string['projectidentifier'] = 'Project identifier';
$string['version'] = 'Version';
$string['versions'] = 'Versions';
$string['deployments'] = 'Deployments';
$string['currentversion'] = 'Current version';
$string['gitrepo'] = 'Git repository';
$string['commithash'] = 'Commit hash';
$string['buildtimestamp'] = 'Build timestamp';
$string['releasenotes'] = 'Release notes';
$string['noversions'] = 'No versions found';
$string['nodeployments'] = 'No deployments found';
$string['noprojects'] = 'No projects found';
$string['deleteproject'] = 'Delete project';
$string['confirmdeleteproject'] = 'Are you sure you want to delete this project?';
$string['deletepackages'] = 'Also delete content library packages';
$string['deleteactivities'] = 'Also delete deployed activities';
$string['projectdeleted'] = 'Project deleted';
$string['deploytocourse'] = 'Deploy to course';
$string['deployed'] = 'Deployed';
$string['newproject'] = 'New project created';
$string['updatedproject'] = 'Project updated';
$string['error:projectnotfound'] = 'Project not found';
$string['error:versionexists'] = 'Version {$a} already exists for this project';
$string['error:coursenotfound'] = 'Course not found: {$a}';
$string['error:mod_cmi5_required'] = 'The mod_cmi5 plugin is required but not installed';
$string['error:playerversionexists'] = 'Player version {$a} already exists';
$string['error:nofileindraft'] = 'No file found in draft area';
$string['error:invalidmanifest'] = 'Invalid player-manifest.json: missing files list';
$string['error:playerfilenotfound'] = 'Player ZIP file not found in storage';
$string['playerversions'] = 'Player Versions';
$string['playerversion'] = 'Player version';
$string['uploadplayer'] = 'Upload player';
$string['noplayerversions'] = 'No player versions uploaded yet';
$string['currentplayerversion'] = 'Current';
$string['latestplayer'] = 'Latest player';
$string['upgradeall'] = 'Upgrade all';
$string['upgradeplayer'] = 'Upgrade player';
$string['playerupgraded'] = 'Player upgraded successfully';
$string['updateavailable'] = 'Update available';
$string['playerupdateavailable'] = 'Player update: {$a->current} → {$a->latest}';
$string['contentupdateavailable'] = 'Content update: {$a->current} → {$a->latest}';
$string['packagesusing'] = '{$a} packages using this version';
$string['filesreplaced'] = '{$a} files replaced';
$string['ausupdated'] = '{$a} AUs updated';
$string['uploadpackage'] = 'Upload package';
$string['packagefile'] = 'Package ZIP file';
$string['deploytocourseids'] = 'Deploy to course IDs (comma-separated)';
$string['uploadanddeploy'] = 'Upload package';
$string['packageuploaded'] = 'Package uploaded successfully';
$string['deployedtocourse'] = 'Deployed to course {$a}';
$string['error:invalidcourseids'] = 'Course IDs must be comma-separated numbers';
$string['projectidentifier_help'] = 'A stable identifier for the project (e.g. the courseId IRI from cmi5.xml). This links uploads to the same project across versions.';
$string['uploadplayerversion'] = 'Upload player version';
$string['playerfile'] = 'Player ZIP file';
$string['playeruploaded'] = 'Player version {$a} uploaded successfully';
$string['upgradeplayerconfirm'] = 'Upgrade player to latest version?';
$string['updatecontent'] = 'Update content';
$string['updatecontentconfirm'] = 'Update content to latest version?';
$string['contentupdated'] = 'Content updated to version {$a}';
$string['packages'] = 'Packages';
$string['actions'] = 'Actions';
$string['player'] = 'Player';
$string['upgradeallconfirm'] = 'Upgrade all packages using this player version to latest?';
$string['allupgraded'] = 'All packages upgraded to player {$a}';
$string['noplayerupgrade'] = 'Already on latest player version';
$string['unmanagedactivities'] = 'Unmanaged Activities';
$string['nounmanaged'] = 'No unmanaged RapidCMI5 activities found';
$string['activityname'] = 'Activity';
$string['aucount'] = 'AUs';
$string['packagemissing'] = 'The content library package for this project has been deleted. Uploading a new version will create a fresh package.';
$string['setplayer'] = 'Set player';
$string['created'] = 'Created';
$string['lastmodified'] = 'Last modified';
$string['description'] = 'Description';
$string['course'] = 'Course';
$string['search'] = 'Search';
$string['manage_dashboard'] = 'RapidCMI5 Management';
$string['projects_desc'] = 'Create and manage RapidCMI5 projects and deploy packages to courses.';
$string['playerversions_desc'] = 'Upload and manage cmi5 player versions used by deployed activities.';
$string['unmanagedactivities_desc'] = 'View cmi5 activities not currently linked to a RapidCMI5 project.';

// Adopt existing activities.
$string['addtomanaged'] = 'Add to managed projects';
$string['addtomanagedconfirm'] = 'Add “{$a->activity}” to project “{$a->project}” ({$a->identifier})? An existing project with this identifier will be reused. The activity and learner progress will be preserved. Locally uploaded content will also be copied into the content library. Future project deployments to this course will update this activity.';
$string['activitymanaged'] = 'The activity is now tracked in managed projects.';
$string['error:notrapidcmi5'] = 'This activity does not contain detectable RapidCMI5 content.';
$string['error:invalidprojectidentifier'] = 'The content must have a consistent course identifier of at most 255 characters before it can be managed.';
$string['error:managedcourseconflict'] = 'This project already manages another activity in this course. Only one activity per project per course can be managed.';
$string['error:adoptionbusy'] = 'Another activity is being added to managed projects. Please try again.';
$string['error:adoptionarchive'] = 'The activity content could not be copied into a package. No management records were saved.';

// Upload project revisions.
$string['uploadnewversion'] = 'Upload new version';
$string['uploadversionnotice'] = 'Upload a RapidCMI5 ZIP with the same course identifier as this project. This becomes the current project version. Existing activities stay on their installed version until you choose Update content.';
$string['versionuploaded'] = 'New project version uploaded. Existing activities have not been changed.';
$string['error:invalidversionlabel'] = 'Enter a version label between 1 and 64 characters.';
$string['error:singlepackage'] = 'Choose one cmi5 package ZIP.';
$string['error:projectmismatch'] = 'This package has a different course identifier. Upload a package built from this project.';
$string['error:notrapidpackage'] = 'This package does not contain detectable RapidCMI5 content.';
$string['error:uploadbusy'] = 'Another version is being uploaded to this project. Please try again.';
$string['error:libraryrevisionmissing'] = 'The exact library revision for this project version is missing or ambiguous. Upload a new project version before updating activities.';

// Browse managed projects by Moodle course (class).
$string['projectsbycourse'] = 'Projects by course';
$string['projectsbycourse_desc'] = 'Browse Moodle courses (classes) and see the managed RapidCMI5 projects and activities in each course.';
$string['searchmanagedcourses'] = 'Search by course name or short name';
$string['clearcoursesearch'] = 'Clear search';
$string['managedcoursecount'] = 'Matching courses';
$string['managedprojectcount'] = 'Managed projects';
$string['managedactivities'] = 'Managed activities';
$string['opencourse'] = 'Open Moodle course';
$string['installedversion'] = 'Installed version';
$string['versionstatus'] = 'Version status';
$string['olderprojectversion'] = 'Update available';
$string['unknownversion'] = 'Unknown version';
$string['nomanagedcourses'] = 'No courses have managed RapidCMI5 activities yet. Add activities to managed projects from the Unmanaged activities page.';
$string['nomatchingmanagedcourses'] = 'No courses with managed RapidCMI5 activities match your search.';
$string['nocourseprojects'] = 'This course has no managed RapidCMI5 projects.';

// Shared dashboard presentation.
$string['dashboardoverview'] = 'Overview';
$string['dashboardnavigation'] = 'RapidCMI5 management sections';
$string['dashboardintro'] = 'Manage your content, see where it is used, and keep your player versions up to date.';
$string['projectdetailintro'] = 'Review version history, upload a release, and manage deployed activities.';
$string['uploadpageintro'] = 'Upload content and record its version details.';
$string['adoptpageintro'] = 'Bring an existing activity into your managed project library.';
$string['dashboardexplore'] = 'Explore';
$string['dashboardprojectcount'] = 'projects';
$string['dashboardcoursecount'] = 'courses with managed content';
$string['dashboardplayercount'] = 'player versions';
$string['dashboardfiles'] = 'Files';
$string['searchprojects'] = 'Search projects';
$string['viewrepository'] = 'View repository';

$string['backtoprojects'] = 'Back to projects';
$string['backtocourses'] = 'Back to courses';
$string['backtocourseprojects'] = 'Back to {$a} projects';
