<?php
/**
 * Created by PhpStorm.
 * User: gemini
 * Date: 2015-06-01
 * Time: 12:37
 */
$host = getenv('APP_DB_HOST') ?: 'localhost';
$user = getenv('APP_DB_USER') ?: 'gemini_mysql';
$password = getenv('APP_DB_PASSWORD') ?: 'chujwdupe007';
$dbname = getenv('APP_DB_NAME') ?: 'gemini_bdsm';

$config['database']=array(
	'host'=>$host,
	'user'=>$user,
	'password'=>$password,
	'dbname'=>$dbname,
);
return $config;
