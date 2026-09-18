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

/** Validates and uploads new revisions for an existing project. */
class version_uploader {
    /** Validate the user's draft package without changing library or project records. */
    public static function validate(int $projectid, int $draftitemid, string $label): \stored_file {
        global $DB, $USER;
        require_capability('local/rapidcmi5:deploy', \context_system::instance());
        $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid], '*', MUST_EXIST);
        $label = trim($label);
        if ($label === '' || \core_text::strlen($label) > 64) {
            throw new \moodle_exception('error:invalidversionlabel', 'local_rapidcmi5');
        }
        if ($DB->record_exists('local_rapidcmi5_versions', ['projectid' => $projectid, 'versionnumber' => $label])) {
            throw new \moodle_exception('error:versionexists', 'local_rapidcmi5', '', $label);
        }
        $files = get_file_storage()->get_area_files(\context_user::instance($USER->id)->id,
            'user', 'draft', $draftitemid, 'id', false);
        if (count($files) !== 1) {
            throw new \moodle_exception('error:singlepackage', 'local_rapidcmi5');
        }
        $zip = reset($files);
        $dir = make_request_directory();
        try {
            if (!$zip->extract_to_pathname(get_file_packer('application/zip'), $dir) ||
                    !is_file($dir . '/cmi5.xml')) {
                throw new \moodle_exception('cmi5xmlnotfound', 'mod_cmi5');
            }
            $structure = \mod_cmi5\cmi5_package::parse_cmi5_xml_static(file_get_contents($dir . '/cmi5.xml'));
            if ($structure->courseid !== $project->identifier) {
                throw new \moodle_exception('error:projectmismatch', 'local_rapidcmi5');
            }
            // Match the RapidCMI5 detector used by the unmanaged activities screen.
            $manifest = is_file($dir . '/player-manifest.json') ?
                json_decode(file_get_contents($dir . '/player-manifest.json'), true) : [];
            $israpid = !empty($manifest['playerVersion']);
            foreach (scandir($dir) as $filename) {
                if (is_file($dir . '/' . $filename) && preg_match('/^main\.[a-f0-9]+\.js$/', $filename)) {
                    $israpid = true;
                }
            }
            if (!$israpid) {
                throw new \moodle_exception('error:notrapidpackage', 'local_rapidcmi5');
            }
        } finally {
            remove_dir($dir);
        }
        return $zip;
    }

    /** Atomically append a library revision and make it the project's current version. */
    public static function upload(int $projectid, int $draftitemid, string $label,
            string $notes = ''): \stdClass {
        global $DB;
        require_capability('local/rapidcmi5:deploy', \context_system::instance());
        $lock = \core\lock\lock_config::get_lock_factory('local_rapidcmi5')->get_lock('upload-' . $projectid, 10);
        if (!$lock) {
            throw new \moodle_exception('error:uploadbusy', 'local_rapidcmi5');
        }
        $transaction = null;
        try {
            $zip = self::validate($projectid, $draftitemid, $label);
            $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid], '*', MUST_EXIST);
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
            $version = project_manager::create_version($projectid, trim($label), $revision->packageid,
                '', 0, $revision->sha256hash ?? '', $notes, (int) $revision->id);
            $transaction->allow_commit();
            return $version;
        } catch (\Throwable $e) {
            if ($transaction) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
