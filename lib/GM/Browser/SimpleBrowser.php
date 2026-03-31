<?php

namespace GM\Browser;

class SimpleBrowser implements SimpleBrowserInterface {
    /**
     * @var string
     */
    protected $contents;
    /**
     * @var int
     */
    protected $sleep=1;

    /**
     * @param $url
     */
    public function navigate($url) {
        $this->contents=file_get_contents($url);
        sleep($this->sleep);
    }

    /**
     * @return mixed
     */
    public function getContents() {
        return $this->contents;
    }
    public function __construct() {
        ini_set('user_agent', 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9'); //some header
    }


}