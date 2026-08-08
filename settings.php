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
 * How segments reach the browser.
 *
 * @package    local_videoguard
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_videoguard',
        get_string('pluginname', 'local_videoguard'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configselect(
        'local_videoguard/delivery',
        get_string('settingdelivery', 'local_videoguard'),
        get_string('settingdelivery_desc', 'local_videoguard'),
        'securelink',
        [
            'securelink' => get_string('deliverysecurelink', 'local_videoguard'),
            'xsendfile'  => get_string('deliveryxsendfile', 'local_videoguard'),
            'readfile'   => get_string('deliveryreadfile', 'local_videoguard'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'local_videoguard/xsendheader',
        get_string('settingxsendheader', 'local_videoguard'),
        get_string('settingxsendheader_desc', 'local_videoguard'),
        'X-Sendfile',
        [
            'X-Sendfile'        => 'X-Sendfile (Apache, mod_xsendfile)',
            'X-Accel-Redirect'  => 'X-Accel-Redirect (nginx)',
        ]
    ));
}
