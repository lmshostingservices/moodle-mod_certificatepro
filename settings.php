<?php
// This file is part of the Certificate Pro plugin for Moodle - https://lms-labs.com
//
// Certificate Pro is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Certificate Pro is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Certificate Pro.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Administration settings for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_heading(
        'mod_certificatepro/generalheading',
        get_string('settings_general', 'mod_certificatepro'),
        ''
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'mod_certificatepro/brandcolour',
        get_string('settings_brandcolour', 'mod_certificatepro'),
        get_string('settings_brandcolour_desc', 'mod_certificatepro'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'mod_certificatepro/defaultfontpairing',
        get_string('settings_defaultfontpairing', 'mod_certificatepro'),
        get_string('settings_defaultfontpairing_desc', 'mod_certificatepro'),
        'heritage',
        \mod_certificatepro\local\typography::pairing_menu()
    ));


    $settings->add(new admin_setting_heading(
        'mod_certificatepro/logoheading',
        get_string('settings_logos', 'mod_certificatepro'),
        get_string('settings_logosintro', 'mod_certificatepro')
    ));

    for ($slot = 1; $slot <= \mod_certificatepro\local\brand::LOGO_SLOTS; $slot++) {
        $settings->add(new admin_setting_configtext(
            'mod_certificatepro/logo' . $slot . 'name',
            get_string('settings_logoname', 'mod_certificatepro', $slot),
            get_string('settings_logoname_desc', 'mod_certificatepro'),
            get_string('logoslotdefault', 'mod_certificatepro', $slot),
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configstoredfile(
            'mod_certificatepro/logo' . $slot,
            get_string('settings_logofile', 'mod_certificatepro', $slot),
            get_string('settings_logofile_desc', 'mod_certificatepro'),
            \mod_certificatepro\local\brand::LOGO_FILEAREA,
            $slot,
            ['maxfiles' => 1, 'accepted_types' => ['web_image', '.svg']]
        ));
    }

    $settings->add(new admin_setting_heading(
        'mod_certificatepro/mediaheading',
        get_string('settings_media', 'mod_certificatepro'),
        get_string('settings_mediaintro', 'mod_certificatepro')
    ));

    $settings->add(new admin_setting_description(
        'mod_certificatepro/medialink',
        '',
        html_writer::link(
            new moodle_url('/mod/certificatepro/media.php'),
            get_string('settings_managemedia', 'mod_certificatepro'),
            ['class' => 'btn btn-secondary']
        )
    ));

    $settings->add(new admin_setting_heading(
        'mod_certificatepro/fontsheading',
        get_string('settings_fonts', 'mod_certificatepro'),
        get_string('settings_fontsintro', 'mod_certificatepro')
    ));

    if (\mod_certificatepro\local\font_manager::can_install_fonts()) {
        $settings->add(new admin_setting_description(
            'mod_certificatepro/fontswritable',
            '',
            get_string('settings_fontswritable', 'mod_certificatepro')
        ));
    } else {
        $settings->add(new admin_setting_description(
            'mod_certificatepro/fontsnotwritable',
            '',
            get_string('settings_fontsnotwritable', 'mod_certificatepro',
                \mod_certificatepro\local\font_manager::custom_font_directory())
        ));
    }

    $settings->add(new admin_setting_description(
        'mod_certificatepro/fontslink',
        '',
        html_writer::link(
            new moodle_url('/mod/certificatepro/fonts.php'),
            get_string('settings_managefonts', 'mod_certificatepro'),
            ['class' => 'btn btn-secondary']
        )
    ));

    $settings->add(new admin_setting_heading(
        'mod_certificatepro/artworkheading',
        get_string('settings_artwork', 'mod_certificatepro'),
        get_string('settings_artworkstatus', 'mod_certificatepro') . ': '
            . \mod_certificatepro\local\ai\generator::status_summary()
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_certificatepro/usecoreai',
        get_string('settings_usecoreai', 'mod_certificatepro'),
        get_string('settings_usecoreai_desc', 'mod_certificatepro'),
        class_exists('\core_ai\manager') ? 1 : 0
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_certificatepro/aiapikey',
        get_string('settings_aiapikey', 'mod_certificatepro'),
        get_string('settings_aiapikey_desc', 'mod_certificatepro'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_certificatepro/aiendpoint',
        get_string('settings_aiendpoint', 'mod_certificatepro'),
        get_string('settings_aiendpoint_desc', 'mod_certificatepro'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'mod_certificatepro/aimodel',
        get_string('settings_aimodel', 'mod_certificatepro'),
        get_string('settings_aimodel_desc', 'mod_certificatepro'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'mod_certificatepro/signingheading',
        get_string('settings_signing', 'mod_certificatepro'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'mod_certificatepro/signingcertificate',
        get_string('settings_signingcertificate', 'mod_certificatepro'),
        get_string('settings_signingcertificate_desc', 'mod_certificatepro'),
        '',
        PARAM_RAW // pipeline-ignore: PARAM_RAW - PEM block, newlines are significant
    ));

    $settings->add(new admin_setting_configtextarea(
        'mod_certificatepro/signingprivatekey',
        get_string('settings_signingprivatekey', 'mod_certificatepro'),
        get_string('settings_signingprivatekey_desc', 'mod_certificatepro'),
        '',
        PARAM_RAW // pipeline-ignore: PARAM_RAW - PEM block, newlines are significant
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_certificatepro/signingpassword',
        get_string('settings_signingpassword', 'mod_certificatepro'),
        get_string('settings_signingpassword_desc', 'mod_certificatepro'),
        ''
    ));
}
