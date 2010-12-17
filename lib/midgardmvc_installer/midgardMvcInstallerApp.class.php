<?php
/*
Midgard MVC Installer
Copyright (C) 2010  The Midgard Project

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
*/
class midgardMvcInstallerApp extends pakeApp
{
    const INSTALLER_VERSION = '1.0.DEV';

    protected function __construct()
    {
        parent::__construct();

        self::$EXEC_NAME = 'mvc_install';
        self::$PLUGINDIRS[] = dirname(__FILE__).'/tasks';
        self::$PAKEFILES = array();
    }

    public static function get_instance()
    {
        if (!self::$instance)
            self::$instance = new midgardMvcInstallerApp();

        return self::$instance;
    }

    public function load_pakefile()
    {
        if (file_exists(getcwd().'/manifest.yaml')) {
            pake_import('MidgardMvcApp', true);
        }

        pake_import('NewMidgardMvcApp', true);
    }

    protected function runDefaultTask()
    {
        $this->display_tasks_and_comments();
        return true;
    }

    public function showVersion()
    {
        echo sprintf(
            self::$EXEC_NAME.' version %s'."\n",
            pakeColor::colorize(self::INSTALLER_VERSION, 'INFO')
        );
        parent::showVersion();
    }
}
