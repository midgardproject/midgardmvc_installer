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


    public static function run_init_mvc($task, $args)
    {
        if (count($args) != 2)
        {
            throw new pakeException('usage: mvc_install '.$task->get_name().' path/to/application.yml target/dir/path');
        }

        pake_echo_comment('reading application definition');
        $application_yml = $args[0];
        $application = self::read_remote_yaml($application_yml);

        self::create_env_fs($args[1]);
        $dir = realpath($args[1]);

        pake_echo_comment('fetching MidgardMVC components');
        self::get_mvc_components($application['components'], $dir);

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

    public static function run__init_database($task, $args)
    {
        self::init_database($args[0], 'midgard2');
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
            pake_rename($dbfile, $dbfile.'.bak');
        } else {
            pake_echo_error('Can not find old database file. Got nothing to backup');
        }

        init_mvc_stage2($dir);
    }


    // HELPERS
    private static function read_remote_yaml($url)
    {
        $application = @file_get_contents($url);
        if (empty($application))
        {
            throw new pakeException("Failed to read application definition from {$url}");
        }

        $application = pakeYaml::loadString($application);
        if (!is_array($application))
        {
            throw new pakeException("Failed to parse application definition from {$url}");
        }

        return $application;
    }

    private static function get_mvc_components(array $components, $target_dir)
    {
        foreach ($components as $component => $sources) {
            self::get_mvc_component($component, $sources, $target_dir);
        }
    }

    private static function get_mvc_component($component, $sources, $target_dir)
    {
        $component_dir = $target_dir.'/'.$component;

        if (!file_exists($component_dir)) {
            if (!is_array($sources)) {
                throw new pakeException("Cannot install {$component}, source repository not provided");
            }

            // support for single-source components
            if (!isset($sources[0])) {
                $sources = array($sources);
            }

            foreach ($sources as $source) {
                if (!isset($source['type'])) {
                    var_dump($source);
                    pake_echo_error('source does not have "type" defined. skipping');
                    continue;
                }

                try {
                    switch ($source['type']) {
                        case 'git':
                            self::get_mvc_component_from_git($source['url'], $source['branch'], $component_dir);
                        break;

                        case 'github':
                            $is_private = isset($source['private']) ? $source['private'] : false;
                            self::get_mvc_component_from_github($source['user'], $source['repository'], $source['branch'], $is_private, $component_dir);
                        break;

                        case 'subversion':
                            self::get_mvc_component_from_subversion($source['url'], $component_dir);
                        break;

                        default:
                            pake_echo_error('source is of unknown type. skipping');
                        break;
                    }

                    // there wasn't exception, so, probably, we're ok
                    break;
                } catch (pakeException $e) {
                    pake_echo_error('there was an error fetching from source: '.$e->getMessage().'. skipping');
                    if (file_exists($component_dir)) {
                        pake_echo_comment("Cleanup…");
                        pake_remove_dir($component_dir);
                        pake_echo_comment("<- Cleanup is done");
                    }
                }
            }
        }

        // Checking validity
        $manifest_path = "{$component_dir}/manifest.yml";
        $manifest = pakeYaml::loadFile($manifest_path);
        if (!is_array($manifest)) {
            throw new pakeException("Component {$component} manifest is invalid");
        }

        // Link schemas
        $schema_files = pakeFinder::type('file')->name('*.xml')->maxdepth(0)->in("{$component_dir}/models/");
        foreach ($schema_files as $schema_file) {
            pake_copy($schema_file, "{$target_dir}/share/schema/{$component}_" . basename($schema_file));
        }

        $view_files = pakeFinder::type('file')->name('*.xml')->in("{$component_dir}/models/views/");
        foreach ($view_files as $view_file) {
            pake_copy($view_file, "{$target_dir}/share/views/{$component}_" . basename($view_file));
        }

        // Install pear-dependencies
        if (isset($manifest['requires_pear'])) {
            $pear = escapeshellarg(pake_which('pear'));

            foreach($manifest['requires_pear'] as $name => $fields) {
                if (isset($fields['channel'])) {
                    pakePearTask::install_pear_package($name, $fields['channel']);
                } elseif (isset($fields['url'])) {
                    pakePearTask::install_from_file($fields['url'], $name, 'pear');
                } else {
                    throw new pakeException('Do not know how to install pear-package without channel or url: "'.$name.'"');
                }
            }
        }

        // Install component dependencies too
        if (isset($manifest['requires'])) {
            self::get_mvc_components($manifest['requires'], $target_dir);
        }
    }

    private static function get_mvc_component_from_git($url, $branch, $component_dir)
    {
        // Check out the component from git
        pakeGit::clone_repository($url, $component_dir)->checkout($branch);
    }

    private static function get_mvc_component_from_github($user, $repository, $branch, $is_private, $component_dir)
    {
        // private repository (we can't support HTTP here)
        if ($is_private) {
            self::get_mvc_component_from_git('git@github.com:'.$user.'/'.$repository.'.git', $branch, $component_dir);
            return;
        }

        // public repository
        try {
            // At first, we try "git" protocol
            self::get_mvc_component_from_git('git://github.com/'.$user.'/'.$repository.'.git', $branch, $component_dir);
        } catch (pakeException $e) {
            // Then fallback to http
            self::get_mvc_component_from_git('https://github.com/'.$user.'/'.$repository.'.git', $branch, $component_dir);
        }
    }

    private static function get_mvc_component_from_subversion($url, $component_dir)
    {
        pakeSubversion::checkout($url, $component_dir);
    }

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

    private static function init_database($dir)
    {
        self::_connect($dir, 'midgard2');

        midgard_storage::create_base_storage();
        pake_echo_action('midgard', 'Created base storage');

        $re = new ReflectionExtension('midgard2');
        $classes = $re->getClasses();
        foreach ($classes as $refclass) {
            $parent_class = $refclass->getParentClass();

            if (!$parent_class) {
                continue;
            }

            if ($parent_class->getName() != 'midgard_object') {
                continue;
            }

            $type = $refclass->getName();

            midgard_storage::create_class_storage($type);
            pake_echo_action('midgard', 'Created storage for '.$type.' class');
        }
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
        $php_config .= "midgard.configuration_file = {$dir}/midgard2.conf\n";
        $php_config .= "midgardmvc.application_config = {$dir}/application.yml\n";

        // WRITING FILE
        pake_write_file($dir.'/php.ini', $php_config);
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

        if (!is_dir($xml_dir))
            throw new pakeException("Can't find core xml-files directory");

        pake_mirror($xmls, $xml_dir, $dir.'/share');
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

    private static function _connect($dir)
    {
        $config = new midgard_config();
        $res = $config->read_file_at_path($dir.'/midgard2.conf');

        if (false === $res) {
            throw new pakeException('Failed to read config');
        }

        $config->create_blobdir();

        $midgard = midgard_connection::get_instance();
        $res = $midgard->open_config($config);

        if (false === $res) {
            throw new pakeException('Failed to init connection from config "midgard2"');
        }

        pake_echo_comment('Connected to database');
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
