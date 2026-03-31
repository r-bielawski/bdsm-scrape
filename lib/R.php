<?php
/**
 * Created by PhpStorm.
 * User: gemini
 * Date: 2015-05-29
 * Time: 12:45
 */

require_once "rb.php";
$config = include("config/config.php");

R::setup("mysql:host={$config['database']['host']};
        dbname={$config['database']['dbname']};charset=utf8mb4",$config['database']['user'],$config['database']['password']);
