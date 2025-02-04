<?php

/**
 * Roundcube Drive using flysystem for filesystem
 *
 * @version @package_version@
 * @author Thomas Payen <thomas.payen@apitech.fr>
 *
 * This plugin is inspired by kolab_files plugin
 * Use flysystem library : https://github.com/thephpleague/flysystem
 * With flysystem WebDAV adapter : https://github.com/thephpleague/flysystem-webdav
 *
 * Copyright (C) 2015 PNE Annuaire et Messagerie MEDDE/MLETR
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

include_once(__DIR__.'/vendor/autoload.php');

class roundav extends rcube_plugin
{
    public const SESSION_FOLDERS_LIST_ID = 'roundav_folders_list';

    // all task excluding 'login' and 'logout'
    public $task = '?(?!login|logout).*';

    public $rc;
    public $home;
    private $engine;

    public function init()
    {
        $this->rc = rcube::get_instance();

        $this->add_hook('refresh', array($this, 'refresh'));
        $this->add_hook('startup', array($this, 'startup'));
        $this->add_hook('logout', array($this, 'onlogout'));

        $this->register_task('roundav');


        $this->register_action('index', array($this, 'actions'));
        $this->register_action('prefs', array($this, 'actions'));
        $this->register_action('open',  array($this, 'actions'));
        $this->register_action('file_api', array($this, 'actions'));

        $this->register_action('plugin.roundav', array($this, 'actions'));
    }

    /**
     * Creates roundav_engine instance
     */
    private function engine()
    {
        if ($this->engine === null) {
            $this->load_config();

            require_once $this->home . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'roundav_files_engine.php';

            $this->engine = new roundav_files_engine($this);
        }

        return $this->engine;
    }

    /**
     * Startup hook handler, initializes/enables Files UI
     */
    public function startup($args)
    {
        // call this from startup to give a chance to set
        $this->ui();
    }

    /**
     * Adds elements of files API user interface
     */
    private function ui()
    {
        if ($this->rc->output->type != 'html') {
            return;
        }

        if ($engine = $this->engine()) {
            $engine->ui();
        }
    }

    /**
     * Refresh hook handler
     */
    public function refresh($args)
    {
        // Here we are refreshing API session, so when we need it
        // the session will be active
        if ($engine = $this->engine()) {
        }

        return $args;
    }

    /**
     * Logout hook handler
     */
    public function onlogout($args)
    {
        unset($_SESSION[self::SESSION_FOLDERS_LIST_ID]);

        return $args;
    }

    /**
     * Engine actions handler
     */
    public function actions()
    {
        if ($engine = $this->engine()) {
            $engine->actions();
        }
    }

    /**
     * Return attachment filename, handle empty filename case
     *
     * @param rcube_message_part $attachment Message part
     * @param bool               $display    Convert to a description text for "special" types
     *
     * @return string Filename
     */
    public function get_attachment_name($attachment, $display)
    {
        return rcmail_action_mail_index::attachment_name($attachment, $display);
    }
}
