<?php
/**
 * Created by PhpStorm.
 * User: gemini
 * Date: 19.11.13
 * Time: 01:39
 */
namespace GM\Browser;

interface SimpleBrowserInterface {
    public function navigate($url);
    public function getContents();

} 