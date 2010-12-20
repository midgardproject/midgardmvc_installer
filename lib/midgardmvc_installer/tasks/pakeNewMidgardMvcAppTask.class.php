<?php

pake_import('pear', false);

class pakeNewMidgardMvcAppTask
{
    public static function import_default_tasks()
    {
        pake_desc('Set up Midgard MVC with Midgard2. Usage: midgardmvc install path/to/application.yml target/dir/path');
        pake_task(__CLASS__.'::install');
        pake_alias('init_mvc', __CLASS__.'::install'); // backward compatibility

        pake_desc('Create fresh database for existing application. Usage: midgardmvc reinit_db app/dir/path');
        pake_task(__CLASS__.'::reinit_db');

        // helper tasks (hidden)
        pake_task(__CLASS__.'::_init_database');
        pake_task(__CLASS__.'::_init_mvc_nodes');
    }


    // public tasks
    public static function run_install($task, $args, $parameters)
    {
        if (count($args) != 2) {
            throw new pakeException('usage: midgardmvc '.$task->get_name().' path/to/application.yml target/dir/path');
        }

        $_db_type = isset($parameters['db']) ? $parameters['db'] : 'sqlite';

        pake_echo_comment('checking, if recent AiP is installed');
        if (pakePearTask::isInstalled('AppServer', 'pear.indeyets.pp.ru')) {
            pake_superuser_sh('pear clear-cache');
            pake_superuser_sh('pear channel-update indeyets');
            pake_superuser_sh('pear upgrade indeyets/AppServer');
        } else {
            pakePearTask::install_pear_package('AppServer', 'pear.indeyets.pp.ru');
        }

        pake_echo_comment('reading application definition');
        $application = pakeYaml::loadFile($args[0]);
        if (!is_array($application)) {
            throw new pakeException("This doesn't look like valid application definition: ".$args[0]);
        }

        self::create_env_fs($args[1]);
        $dir = realpath($args[1]);

        pake_echo_comment('installing configuration files');
        self::create_config($dir, $_db_type);
        self::create_ini_file($dir);
        self::create_runner_script($dir);
        pakeYaml::emitFile($application, "{$dir}/application.yml");

        pake_echo_comment('fetching MidgardMVC components');
        pakeMidgardMvcComponent::install_mvc_components($application['components'], $dir);

        self::init_mvc_stage2($dir);

        pake_echo_comment("Midgard MVC installed. Run your application with ".
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

        $ini = parse_ini_file($dir.'/midgard2.conf', true);

        if ($ini['MidgardDatabase']['Type'] == 'SQLite') {
            $_db_path = $ini['MidgardDatabase']['DatabaseDir'].'/'.$ini['MidgardDatabase']['Name'].'.db';
            self::clean_sqlite_db($_db_path);
        } elseif ($ini['MidgardDatabase']['Type'] == 'MySQL') {
            self::clean_mysql_db($ini['MidgardDatabase']);
        }

        self::init_mvc_stage2($dir);
    }


    // hidden tasks
    public static function run__init_database($task, $args)
    {
        pake_echo_comment('Initialising database…');

        pakeMidgard::connect($args[0].'/midgard2.conf');
        pakeMidgard::create_blobdir();
        pakeMidgard::init_database();
    }

    public static function run__init_mvc_nodes($task, $args)
    {
        pake_echo_comment('Preparing Midgard MVC nodes…');

        $path = $args[0];

        // load config
        $config = pakeYaml::loadFile($path.'/application.yml');

        // no nodes to insert
        if (!isset($config['nodes']) or empty($config['nodes']))
            return true;

        // connect
        pakeMidgard::connect($path.'/midgard2.conf');

        // init MVC
        require_once $path.'/midgardmvc_core/framework.php';
        $midgardmvc = midgardmvc_core::get_instance($config);

        // insert nodes
        call_user_func(
            array('midgardmvc_core_providers_hierarchy_'.$config['providers_hierarchy'], 'prepare_nodes'),
            $config['nodes'], false
        );
    }


    // HELPERS
    private static function init_mvc_stage2($dir)
    {
        $php = pake_which('php');
        putenv('MIDGARD_ENV_GLOBAL_SHAREDIR='.$dir.'/share');
        putenv('PHP_COMMAND='.$php.' -c '.$dir.' -d midgard.http=Off');

        $installer = pake_which('midgardmvc');
        $force_tty = pakeApp::isTTY() ? ' --force-tty' : '';

        $cmd = escapeshellarg($installer).$force_tty;

        // init database
        pake_sh($cmd.' _init_database '.escapeshellarg($dir), true);

        // insert nodes
        pake_sh($cmd.' _init_mvc_nodes '.escapeshellarg($dir), true);
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
        $php_config .= "magic_quotes_gpc = Off\n";
        $php_config .= "magic_quotes_runtime = Off\n";
        $php_config .= "magic_quotes_sybase = Off\n";

        $php_config .= "midgard.engine = On\n";
        $php_config .= "midgard.http = On\n";
        $php_config .= "midgard.memory_debug = Off\n";
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

    private static function create_config($prefix, $db_type)
    {
        if ($db_type == 'sqlite') {
            $db_config = "Type=SQLite\n".
                         "Name=midgard2\n".
                         "DatabaseDir={$prefix}\n";
        } elseif ($db_type == 'mysql') {
            $db_host     = pake_input('Hostname of MySQL server?', 'localhost');
            $db_port     = pake_input('Port of MySQL server?', '3306');
            $db_name     = pake_input('Name of MySQL database?', 'midgard');
            $db_login    = pake_input('MySQL login to be used?', 'midgard');
            $db_password = pake_input('MySQL password to be used?', 'midgard');

            $db_config = "Type=MySQL\n".
                         "Host={$db_host}\n".
                         "Port={$db_port}\n".
                         "Name={$db_name}\n".
                         "Username={$db_login}\n".
                         "Password={$db_password}\n";
        } else {
            throw new pakeException("This dbtype is not supported by installer: ".$dbtype);
        }

        $config_file =
            "[MidgardDatabase]\n".
                $db_config.
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
                "CacheDir={$prefix}/cache\n";

        pake_write_file($prefix.'/midgard2.conf', $config_file);
    }

    private static function create_runner_script($prefix)
    {
        $php = escapeshellarg(pake_which('php'));
        $php_ini = escapeshellarg($prefix.'/php.ini');
        $aip = escapeshellarg(pake_which('aip'));
        $app_path = escapeshellarg($prefix.'/midgardmvc_core/httpd');

        $debug_runner = escapeshellarg($prefix.'/midgardmvc_core/httpd/midgardmvc-root-appserv.php');

        $production = $php.' -c '.$php_ini.' '.$aip.' app '.$app_path;
        $debug =      $php.' -c '.$php_ini.' '.$debug_runner;

        try {
            $gdb = escapeshellarg(pake_which('gdb'));
            $debug = $gdb.' --args '.$debug;
        } catch (pakeException $e) {
        }

        $contents  = '#!/bin/sh'."\n\n";
        $contents .= 'if [ $# -eq 0 ] ; then'."\n";
        $contents .= '    '.$production."\n";
        $contents .= 'else'."\n";
        $contents .= '    if [ $1 = "debug" ] ; then'."\n";
        $contents .= '        '.$debug."\n";
        $contents .= '    else'."\n";
        $contents .= '        echo "Unknown mode requested"'."\n";
        $contents .= '    fi'."\n";
        $contents .= 'fi'."\n";

        pake_write_file($prefix.'/run', $contents);
        pake_chmod('run', $prefix, 0755);
    }


    private static function clean_sqlite_db($dbfile)
    {
        if (file_exists($dbfile)) {
            if (file_exists($dbfile.'.bak')) {
                pake_remove($dbfile.'.bak', '/');
            }
            pake_rename($dbfile, $dbfile.'.bak');
        } else {
            pake_echo_error('Can not find old database file. Got nothing to backup');
        }
    }

    private static function clean_mysql_db(array $config)
    {
        $db = new pakeMySQL($config['Username'], $config['Password'], $config['Host'], $config['Port']);
        $db->dropDatabase($config['Name']);
        $db->createDatabase($config['Name']);
    }
}
