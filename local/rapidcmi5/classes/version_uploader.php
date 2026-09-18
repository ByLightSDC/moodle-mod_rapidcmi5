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

namespace local_rapidcmi5;

defined('MOODLE_INTERNAL') || die();

/** Validates and uploads RapidCMI5 packages as new project versions. */
class version_uploader {
    /**
     * Check a draft package can become the named project's next version, without changing any records.
     *
     * @return \stdClass zip (the draft stored_file), label (trimmed) and project (record).
     */
    public static function validate(int $projectid, int $draftitemid, string $label): \stdClass {
        global $DB;
        require_capability('local/rapidcmi5:deploy', \context_system::instance());
        $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid], '*', MUST_EXIST);
        $label = self::check_label($project, $label);
        $zip = self::draft_package($draftitemid);
        if (self::inspect_package($zip)->identifier !== $project->identifier) {
            throw new \moodle_exception('error:projectmismatch', 'local_rapidcmi5');
        }
        return (object) ['zip' => $zip, 'label' => $label, 'project' => $project];
    }

    /**
     * Check a draft package can be uploaded to the project its cmi5.xml names, without changing any records.
     *
     * @return \stdClass zip, label (trimmed), identifier and title from cmi5.xml, and project (record, or null if new).
     */
    public static function validate_package(int $draftitemid, string $label): \stdClass {
        global $DB;
        require_capability('local/rapidcmi5:deploy', \context_system::instance());
        $zip = self::draft_package($draftitemid);
        $package = self::inspect_package($zip);
        $project = $DB->get_record('local_rapidcmi5_projects', ['identifier' => $package->identifier]) ?: null;
        $package->label = self::check_label($project, $label);
        $package->zip = $zip;
        $package->project = $project;
        return $package;
    }

    /** Atomically append a library revision and make it the project's current version. */
    public static function upload(int $projectid, int $draftitemid, string $label,
            string $notes = ''): \stdClass {
        global $DB;
        // Checked again by validate(), but refuse before taking the project's upload lock.
        require_capability('local/rapidcmi5:deploy', \context_system::instance());
        $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid], '*', MUST_EXIST);
        return self::locked($project->identifier, function() use ($projectid, $draftitemid, $label, $notes) {
            $checked = self::validate($projectid, $draftitemid, $label);
            return self::store_revision($checked->project, $checked->zip, $checked->label, trim($notes));
        });
    }

    /**
     * Atomically upload a package as a new version of the project its cmi5.xml names, creating the project if needed.
     *
     * @param int $draftitemid Draft area holding the package ZIP.
     * @param string $label Version label.
     * @param string $notes Release notes.
     * @param array $details Optional name, gitrepourl, commithash and buildtimestamp.
     * @return \stdClass project, version and isnewproject.
     */
    public static function upload_package(int $draftitemid, string $label, string $notes = '',
            array $details = []): \stdClass {
        global $DB;
        // The identifier decides the lock, and the full checks are repeated under it.
        $identifier = self::validate_package($draftitemid, $label)->identifier;
        return self::locked($identifier, function() use ($DB, $draftitemid, $label, $notes, $details) {
            $checked = self::validate_package($draftitemid, $label);
            $name = trim($details['name'] ?? '');
            // Name a new project after its course; an existing project keeps its name unless one is given.
            if ($name === '' && !$checked->project) {
                $name = $checked->title;
            }
            $transaction = $DB->start_delegated_transaction();
            $project = project_manager::get_or_create_project($checked->identifier, $name,
                $details['gitrepourl'] ?? '')->project;
            $version = self::store_revision($project, $checked->zip, $checked->label, trim($notes),
                $details['commithash'] ?? '', (int) ($details['buildtimestamp'] ?? 0));
            $transaction->allow_commit();
            return (object) ['project' => $project, 'version' => $version, 'isnewproject' => !$checked->project];
        });
    }

    /** Run an upload holding the lock for its project identifier, so two uploads cannot claim the same label. */
    private static function locked(string $identifier, callable $upload): \stdClass {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_rapidcmi5')->get_lock('upload-' . sha1($identifier), 10);
        if (!$lock) {
            throw new \moodle_exception('error:uploadbusy', 'local_rapidcmi5');
        }
        try {
            return $upload();
        } finally {
            $lock->release();
        }
    }

    /** Append a library revision for the project and make it the current version, in one transaction. */
    private static function store_revision(\stdClass $project, \stored_file $zip, string $label, string $notes,
            string $commithash = '', int $buildtimestamp = 0): \stdClass {
        global $DB;
        $packageid = !empty($project->currentpackageid) &&
            $DB->record_exists('cmi5_packages', ['id' => $project->currentpackageid]) ?
            (int) $project->currentpackageid : 0;
        $transaction = $DB->start_delegated_transaction();
        $previous = empty($project->currentversionid) ? false :
            $DB->get_record('local_rapidcmi5_versions', ['id' => $project->currentversionid]);
        // Inherit the LRS profile from the revision being superseded. When that revision is
        // ambiguous, fall back to the package's own latest revision rather than silently
        // resetting the profile to none.
        $previouslibrary = $previous ? project_manager::get_library_version($previous) : false;
        if (!$previouslibrary && $packageid) {
            $latest = $DB->get_field('cmi5_packages', 'latestversion', ['id' => $packageid]);
            $previouslibrary = $latest ?
                $DB->get_record('cmi5_package_versions', ['id' => $latest]) : false;
        }
        $revision = \mod_cmi5\content_library::upload_package($zip, $project->name,
            $project->description ?? '', (int) ($previouslibrary->profileid ?? 0), $packageid);
        $version = project_manager::create_version($project->id, $label, $revision->packageid,
            $commithash, $buildtimestamp, $revision->sha256hash ?? '', $notes, (int) $revision->id);
        $transaction->allow_commit();
        return $version;
    }

    /** Trim a version label and check it is valid and unused by the project, if the project exists. */
    private static function check_label(?\stdClass $project, string $label): string {
        global $DB;
        $label = trim($label);
        if ($label === '' || \core_text::strlen($label) > 64) {
            throw new \moodle_exception('error:invalidversionlabel', 'local_rapidcmi5');
        }
        if ($project && $DB->record_exists('local_rapidcmi5_versions',
                ['projectid' => $project->id, 'versionnumber' => $label])) {
            throw new \moodle_exception('error:versionexists', 'local_rapidcmi5', '', $label);
        }
        return $label;
    }

    /** The single package ZIP in the current user's draft area. */
    private static function draft_package(int $draftitemid): \stored_file {
        global $USER;
        $files = get_file_storage()->get_area_files(\context_user::instance($USER->id)->id,
            'user', 'draft', $draftitemid, 'id', false);
        if (count($files) !== 1) {
            throw new \moodle_exception('error:singlepackage', 'local_rapidcmi5');
        }
        return reset($files);
    }

    /**
     * Check a ZIP is a RapidCMI5 cmi5 package and read its course identifier and title.
     *
     * Only cmi5.xml and player-manifest.json are unpacked; the full package is extracted once, on upload.
     *
     * @return \stdClass identifier and title.
     */
    private static function inspect_package(\stored_file $zip): \stdClass {
        $packer = get_file_packer('application/zip');
        $entries = $zip->list_files($packer);
        if (!is_array($entries)) {
            throw new \moodle_exception('error:invalidzip', 'local_rapidcmi5');
        }
        $rootfilenames = [];
        foreach ($entries as $entry) {
            if (!$entry->is_directory && strpos($entry->pathname, '/') === false) {
                $rootfilenames[] = $entry->pathname;
            }
        }
        if (!in_array('cmi5.xml', $rootfilenames, true)) {
            throw new \moodle_exception('cmi5xmlnotfound', 'mod_cmi5');
        }
        $dir = make_request_directory();
        try {
            // Extract from the local path: stored_file::extract_to_pathname() cannot limit which files it unpacks.
            $localzip = get_file_storage()->get_file_system()->get_local_path_from_storedfile($zip, true);
            $packer->extract_to_pathname($localzip, $dir, ['cmi5.xml', 'player-manifest.json']);
            if (!is_file($dir . '/cmi5.xml')) {
                throw new \moodle_exception('error:invalidzip', 'local_rapidcmi5');
            }
            $structure = \mod_cmi5\cmi5_package::parse_cmi5_xml_static(file_get_contents($dir . '/cmi5.xml'));
            // Not trimmed: adoption matches projects on the exact course ID.
            $identifier = (string) ($structure->courseid ?? '');
            if ($identifier === '' || \core_text::strlen($identifier) > 255) {
                throw new \moodle_exception('error:invalidprojectidentifier', 'local_rapidcmi5');
            }
            $manifestjson = is_file($dir . '/player-manifest.json') ? file_get_contents($dir . '/player-manifest.json') : null;
            if (!player_manager::is_rapid_package($rootfilenames, $manifestjson ?: null)) {
                throw new \moodle_exception('error:notrapidpackage', 'local_rapidcmi5');
            }
        } finally {
            remove_dir($dir);
        }
        return (object) ['identifier' => $identifier, 'title' => trim((string) ($structure->coursetitle ?? ''))];
    }
}
