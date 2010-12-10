<?php

if (!defined('MVC_INSTALLER_DIR'))
    define('MVC_INSTALLER_DIR', dirname(__FILE__));

function mvc_installer_autoloader($classname)
{
    static $classes = null;

    if (null === $classes) {
        $classes = array(
            'midgardMvcInstallerApp'    => MVC_INSTALLER_DIR.'/midgardMvcInstallerApp.class.php',
            'pakeMidgard'               => MVC_INSTALLER_DIR.'/pakeMidgard.class.php',
            'pakeMidgardMvcComponent'   => MVC_INSTALLER_DIR.'/pakeMidgardMvcComponent.class.php',
        );
    }

    if (isset($classes[$classname]))
        require $classes[$classname];
}
spl_autoload_register('mvc_installer_autoloader');
