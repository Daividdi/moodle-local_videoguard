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

namespace local_videoguard\task;

use local_videoguard\local\segmenter;

/**
 * Safety net: finds videos the event observer never heard about.
 *
 * The observer only fires on the activity form. Course restore, web services and
 * direct database work all create activities without it, and every one of those
 * would otherwise stay on the unprotected MP4 forever. This sweep is what stops
 * the protection decaying quietly.
 *
 * @package    local_videoguard
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sweep extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksweep', 'local_videoguard');
    }

    /**
     * Queues segmentation for anything unprotected or stale.
     */
    public function execute(): void {
        global $DB;

        $sql = "SELECT cm.id AS cmid, iv.id AS instanceid, iv.source, iv.videourl
                  FROM {interactivevideo} iv
                  JOIN {course_modules} cm ON cm.instance = iv.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'interactivevideo'";
        $rows = $DB->get_records_sql($sql);

        $queued = 0;
        foreach ($rows as $row) {
            $cm = get_coursemodule_from_id('interactivevideo', $row->cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $file = segmenter::source_file($cm);
            if (!$file) {
                continue;
            }

            $stale = segmenter::needs_segmenting($file, (int)$row->instanceid);
            $unprotected = ($row->source !== 'url')
                || (strpos((string)$row->videourl, '/local/videoguard/manifest.php/') === false);

            if ($stale || $unprotected) {
                segment_video::queue((int)$row->cmid);
                $queued++;
            }
        }

        mtrace("videoguard sweep: {$queued} activity(ies) queued out of " . count($rows));
    }
}
