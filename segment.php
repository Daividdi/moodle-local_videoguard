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
 * Serves one HLS segment after checking the Moodle session.
 *
 * This is the alternative to the signed-URL delivery, and where it is available it
 * is the stronger of the two. secure_link exists because nginx cannot read a Moodle
 * session, so the signature stands in for one - which means a signed URL keeps
 * working, without any session, until it expires. Here the session IS the check:
 * enrolment and availability are re-evaluated on every segment, and a copied URL is
 * worthless to anyone not logged in.
 *
 * The cost is a Moodle bootstrap per segment. With X-Sendfile the worker is released
 * the moment the header is written, so it is a bootstrap - not a held connection for
 * the length of the download.
 *
 * @package    local_videoguard
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_videoguard\local\segmenter;

$relativepath = get_file_argument();
if (empty($relativepath)) {
    throw new moodle_exception('invalidargument');
}

$args = explode('/', trim($relativepath, '/'));
$cmid = (int)array_shift($args);
$segment = (string)array_shift($args);

if ($cmid <= 0) {
    throw new moodle_exception('invalidargument');
}

// Strict whitelist, not a sanitiser. The name is used to build a filesystem path,
// so anything other than exactly the shape ffmpeg produces is refused outright -
// no traversal sequence, no dotfile and no alternative extension can survive this.
if (!preg_match('/^seg_\d{5}\.ts$/', $segment)) {
    throw new moodle_exception('invalidargument');
}

$cm = get_coursemodule_from_id('interactivevideo', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);

// Enrolment, groups and availability, re-checked for every segment.
require_login($course, false, $cm);

$path = segmenter::dir_for((int)$cm->instance) . '/' . $segment;
if (!is_readable($path)) {
    throw new moodle_exception('notprocessed', 'local_videoguard');
}

$mode = get_config('local_videoguard', 'delivery') ?: 'securelink';
$header = get_config('local_videoguard', 'xsendheader') ?: 'X-Sendfile';

header('Content-Type: video/mp2t');
header('X-Content-Type-Options: nosniff');
// Segments are immutable once written, and the response is identical for every
// user who is allowed to see it - so it may be cached by the browser, but never
// by a shared cache, because entitlement is per user.
header('Cache-Control: private, max-age=600');

if ($mode === 'xsendfile') {
    // The web server streams the file and this process ends here.
    header($header . ': ' . $path);
    exit;
}

// Fallback: PHP streams it. Correct, but the worker stays busy for the whole
// download, so it is the option of last resort rather than a default.
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
