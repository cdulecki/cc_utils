<?php

$cu_config = array(
    'zendLib' => '/home/cdulecki/lib/Zend/library'
);


// setup zend autoloader
set_include_path($cu_config['zendLib'] . ':' . get_include_path());
require $cu_config['zendLib'] . '/Zend/Loader/Autoloader.php';
$autoloader = Zend_Loader_Autoloader::getInstance();

