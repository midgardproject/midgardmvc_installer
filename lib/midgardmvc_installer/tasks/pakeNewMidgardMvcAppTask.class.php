<?php

pake_import('pear', false);

class pakeNewMidgardMvcAppTask
{
    public static function import_default_tasks()
    {
        pake_desc('Set up Midgard MVC with Midgard2. Usage: pake init_mvc path/to/application.yml target/dir/path');
        pake_task(__CLASS__.'::init_mvc');

        pake_task(__CLASS__.'::_init_database'); // helper

        pake_desc('Create fresh database for existing application');
        pake_task(__CLASS__.'::reinit_db');
    }


    // public tasks
    public static function run_init_mvc($task, $args)
    {
        if (count($args) != 2) {
            throw new pakeException('usage: mvc_install '.$task->get_name().' path/to/application.yml target/dir/path');
        }

        pake_echo_comment('reading application definition');
        $application = pakeYaml::loadFile($args[0]);

        self::create_env_fs($args[1]);
        $dir = realpath($args[1]);

        pake_echo_comment('fetching MidgardMVC components');
        pakeMidgardMvcComponent::install_mvc_components($application['components'], $dir);

        pake_echo_comment('checking, if recent AiP is installed');
        pakePearTask::install_pear_package('AppServer', 'pear.indeyets.pp.ru');

        pake_echo_comment('installing configuration files');
        self::create_config($dir);
        self::create_ini_file($dir);
        self::create_runner_script($dir);
        pakeYaml::emitFile($application, "{$dir}/application.yml");

        self::init_mvc_stage2($dir);

        pake_echo_comment("Midgard MVC installed. Run your application with ".
                            // "'php -c {$dir}/php.ini {$dir}/midgardmvc_core/httpd/midgardmvc-root-appserv.php' ".
                            "'{$dir}/run' and go to http://localhost:8001/");
    }

    public static function run_reinit_db($task, $args)
    {
        $dir = $args[0];

        if (!is_dir($dir))
            throw new pakeException('"'.$dir.'" is not a directory');

        $dir = realpath($dir);

        if (!file_exists($dir.'/application.yml'))
            throw new pakeException('"'.$dir.'" does not look like MidgardMVC application. (can not find application.yml file)');

        $dbfile = $dir.'/midgard2.db';

        if (file_exists($dbfile)) {
            if (file_exists($dbfile.'.bak')) {
                pake_remove($dbfile.'.bak', '/');
            }
            pake_rename($dbfile, $dbfile.'.bak');
        } else {
            pake_echo_error('Can not find old database file. Got nothing to backup');
        }

        self::init_mvc_stage2($dir);
    }


    // hidden tasks
    public static function run__init_database($task, $args)
    {
        pakeMidgard::connect($args[0].'/midgard2.conf');
        pakeMidgard::create_blobdir();
        pakeMidgard::init_database();
    }


    // HELPERS
    private static function init_mvc_stage2($dir)
    {
        $php = pake_which('php');
        putenv('MIDGARD_ENV_GLOBAL_SHAREDIR='.$dir.'/share');
        putenv('PHP_COMMAND='.$php.' -c '.$dir.' -d midgard.http=Off');

        $installer = pake_which('mvc_install');
        $force_tty = pakeApp::isTTY() ? ' --force-tty' : '';

        pake_sh(
            escapeshellarg($installer).$force_tty.
            ' _init_database '.escapeshellarg($dir),
            true
        );
    }


    private static function create_env_fs($dir)
    {
        pake_echo_comment('creating directory-structure');

        if (file_exists($dir)) {
            throw new pakeException("Directory {$dir} already exists");
        }

        pake_mkdirs($dir);
        $dir = realpath($dir);

        pake_mkdirs($dir.'/share/schema');
        pake_mkdirs($dir.'/share/views');
        pake_mkdirs($dir.'/blobs');
        pake_mkdirs($dir.'/var');
        pake_mkdirs($dir.'/cache');

        // looking for core xml-files
        $xmls = pakeFinder::type('file')->name('*.xml')->maxdepth(0);

        $xml_dir = self::get_midgard_core_prefix().'/share/midgard2';

        pake_mirror($xmls, $xml_dir, $dir.'/share');
    }

    private static function create_ini_file($dir)
    {
        $php_config = '';

        // EXTENSIONS
        // on debian/ubuntu, php extensions are inherited. on normal systems they are not
        if (!file_exists('/etc/debian_version') or !extension_loaded('midgard2')) {
            $php_config .= "extension=midgard2.so\n";
            $php_config .= "extension=gettext.so\n";

            if (extension_loaded('yaml')) {
                $php_config .= "extension=yaml.so\n";
            }

            if (extension_loaded('httpparser')) {
                $php_config .= "extension=httpparser.so\n";
            }

            $php_config .= "\n";
        }

        // CONFIGURATION SETTINGS
        $php_config .= "include_path=" . ini_get('include_path') . "\n";
        $php_config .= "date.timezone=" . ini_get('date.timezone') . "\n";

        $php_config .= "midgard.engine = On\n";
        $php_config .= "midgard.http = On\n";
        $php_config .= "midgard.memory_debug = Off\n"
        $php_config .= "midgard.configuration_file = {$dir}/midgard2.conf\n";
        $php_config .= "midgardmvc.application_config = {$dir}/application.yml\n";

        // WRITING FILE
        pake_write_file($dir.'/php.ini', $php_config);
    }

    private static function get_midgard_core_prefix()
    {
        $pkgconfig = pake_which('pkg-config');

        if ($pkgconfig) {
            try {
                return trim(pake_sh(escapeshellarg($pkgconfig).' --variable=prefix midgard2'));
            } catch (pakeException $e)
            {
            }
        }

        if (is_dir('/usr/share/midgard2')) {
            return '/usr';
        }

        $path = pake_input("Please enter your midgard-prefix");

        if (!is_dir($path))
            throw new pakeException('Wrong path: "'.$path.'"');

        return $path;
    }

    private static function create_config($prefix)
    {
        pake_write_file(
            $prefix.'/midgard2.conf',
            "[MidgardDatabase]\n".
                "Type=SQLite\n".
                "Name=midgard2\n".
                "DatabaseDir={$prefix}\n".
                "Logfile={$prefix}/midgard2.log\n".
                "Loglevel=warning\n".
                "TableCreate=true\n".
                "TableUpdate=true\n".
                "TestUnit=false\n".
                "\n".
                "[MidgardDir]\n".
                "BlobDir={$prefix}/blobs\n".
                "ShareDir={$prefix}/share\n".
                "VarDir={$prefix}/var\n".
                "CacheDir={$prefix}/cache\n"
        );
    }

    private static function create_runner_script($prefix)
    {
        $contents =  '#!/bin/sh'."\n\n";
        $contents .= escapeshellarg(pake_which('php')).' -c '.escapeshellarg($prefix.'/php.ini').' '
                    .escapeshellarg(pake_which('aip')).' app '.escapeshellarg($prefix.'/midgardmvc_core/httpd');

        pake_write_file($prefix.'/run', $contents);
        pake_chmod('run', $prefix, 0755);
    }
}
