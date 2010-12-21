<?php

class pakeMidgard
{
    private static $config = null;

    public static function connect($config_file)
    {
        $config = new midgard_config();
        $res = $config->read_file_at_path($config_file);

        if (false === $res) {
            throw new pakeException('Failed to read config');
        }

        self::$config = $config;

        $midgard = midgard_connection::get_instance();
        $res = $midgard->open_config($config);

        if (false === $res) {
            throw new pakeException('Failed to init connection from config "'.$config_file.'"');
        }

        pake_echo_action('midgard', 'connected to database');
    }

    public static function create_blobdir()
    {
        if (null === self::$config) {
            throw new pakeException("You need to connect, at first");
        }

        $res = self::$config->create_blobdir();

        if (false === $res) {
            throw new pakeException('Failed to create BLOB directory');
        }

        pake_echo_action('midgard', 'created BLOB directory');
    }

    public static function init_database()
    {
        if (false === midgard_storage::create_base_storage()) {
            throw new pakeException("Couldn't create base storage: ".midgard_connection::get_instance()->get_error_string());
        }
        pake_echo_action('midgard', 'created base storage');

        $re = new ReflectionExtension('midgard2');
        foreach ($re->getClasses() as $class_ref) {
            $class_mgd_ref = new midgard_reflection_class($class_ref->getName());
            $parent_class = $class_mgd_ref->getParentClass();

            if (!$parent_class) {
                continue;
            }

            if (!in_array($parent_class->getName(), array("midgard_dbobject", "midgard_object", "midgard_view")))
                continue;

            // skip abstract classes
            if (in_array($class_mgd_ref->getName(), array("midgard_dbobject", "midgard_object", "midgard_view")))
                continue;

            $type = $class_mgd_ref->getName();

            if (false === midgard_storage::create_class_storage($type)) {
                throw new pakeException("Couldn't create storage for {$type} class: ".midgard_connection::get_instance()->get_error_string());
            }

            pake_echo_action('midgard', 'created storage for class: '.pakeColor::colorize($type, 'INFO'));
        }
    }

    public static function update_database()
    {
        $re = new ReflectionExtension('midgard2');
        foreach ($re->getClasses() as $class_ref) {
            $class_mgd_ref = new midgard_reflection_class($class_ref->getName());
            $parent_class = $class_mgd_ref->getParentClass();

            if (!$parent_class) {
                continue;
            }

            if (!in_array($parent_class->getName(), array("midgard_dbobject", "midgard_object", "midgard_view")))
                continue;

            // skip abstract classes
            if (in_array($class_mgd_ref->getName(), array("midgard_dbobject", "midgard_object", "midgard_view")))
                continue;

            $type = $class_mgd_ref->getName();

            if (midgard_storage::class_storage_exists($type)) {
                if (false === midgard_storage::update_class_storage($type)) {
                    throw new pakeException("Couldn't update storage for {$type} class: ".midgard_connection::get_instance()->get_error_string());
                }

                pake_echo_action('midgard', 'updated storage for class: '.pakeColor::colorize($type, 'INFO'));
            } else {
                if (false === midgard_storage::create_class_storage($type)) {
                    throw new pakeException("Couldn't create storage for {$type} class: ".midgard_connection::get_instance()->get_error_string());
                }

                pake_echo_action('midgard', 'created storage for class: '.pakeColor::colorize($type, 'INFO'));
            }
        }
    }
}
