<?php

// set classpath
if (getenv('PHP_CLASSPATH')) {
  set_include_path(dirname(__FILE__).'/../lib'.PATH_SEPARATOR.getenv('PHP_CLASSPATH').PATH_SEPARATOR.get_include_path());
} else {
  set_include_path(dirname(__FILE__).'/../lib'.PATH_SEPARATOR.get_include_path());
}

require 'pake/init.php';
require 'midgardmvc_installer/init.php';

pake_require_version('1.5.0');

if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $pake = midgardMvcInstallerApp::get_instance();
    $pake->run();
}
