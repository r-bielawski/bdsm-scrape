<?php
/**
 * Created by PhpStorm.
 * User: gemini
 * Date: 2015-05-11
 * Time: 13:44
 */
ini_set('xdebug.max_nesting_level','10000');
function autoload($classname)
{
    $filename = dirname(__FILE__).DIRECTORY_SEPARATOR.str_replace("\\",DIRECTORY_SEPARATOR,$classname).'.php';

    if (is_readable($filename)) {
        require $filename;
    }
}
    if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
        spl_autoload_register('autoload', true, true);
    } else {
        spl_autoload_register('autoload');
    }

$config=require_once "config/config.php";
require_once "common_functions.php";
