<?php
$publicPath = 'D:\opencode\projectend\laragon\www\project\public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($uri !== '/' && file_exists($publicPath.$uri)) { return false; }
$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
require $publicPath.'/index.php';
