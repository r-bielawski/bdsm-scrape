<?php
/**
 * Created by PhpStorm.
 * User: gemini
 */
namespace GM\Browser;

class SimpleCurlBrowser implements SimpleBrowserInterface {
    protected $curlInstance='';
    protected $autoRefferrer=false;
	protected $currentCookieFile=false;
    public $dbg;
    public $sleep=1;

    /**
     * @param boolean $autoRefferrer
     */
    public function setAutoRefferrer($autoRefferrer)
    {
        $this->autoRefferrer = $autoRefferrer;
    }

    /**
     * @return boolean
     */
    public function getAutoRefferrer()
    {
        return $this->autoRefferrer;
    }

    public function __construct($cookieDir=false) {
        $this->curlInstance=curl_init();
        $this->setCurlOption(CURLOPT_USERAGENT,"Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1985.143 Safari/537.36");
        $this->setCurlOption(CURLOPT_FOLLOWLOCATION,1);
        $this->setCurlOption(CURLOPT_RETURNTRANSFER,1);
        if($cookieDir) {
            $this->setCookiesDir($cookieDir);
        }
    }
    protected $CoookieDir;
    public function setCookiesDir($CookieDir) {
        if(is_writable($CookieDir)) {
            $cookieFile=$CookieDir.DIRECTORY_SEPARATOR.time().".cookie";
	        $this->setCurlOption(CURLOPT_COOKIEFILE,$cookieFile);
	        $this->setCurlOption(CURLOPT_COOKIEJAR,$cookieFile);
	        $this->currentCookieFile=$cookieFile;
        } else {
            throw new \Exception("Cookies dir not writeable");
        }
    }
    public function setCookieFileInsideCookieDir($FilenameInsideCookieDir) {
        $cookieFile=$this->CoookieDir.DIRECTORY_SEPARATOR.$FilenameInsideCookieDir;
        if(is_writable($cookieFile)) {
	        $this->setCurlOption(CURLOPT_COOKIEFILE,$cookieFile);
	        $this->setCurlOption(CURLOPT_COOKIEJAR,$cookieFile);
	        $this->currentCookieFile=$cookieFile;

        } else {
            throw new Exception("Cookies dir not writeable");
        }
    }
    public function setCookieFileSpecific($cookieFile,$reset=false) {



            if(!file_exists($cookieFile)||$reset) {
                if(false===file_put_contents($cookieFile,'')) {
                    throw new \RuntimeException("Cannot create file");
                }

            } else {
                if(!is_writable($cookieFile)) {
                    throw new \RuntimeException("File not writeable");
                }
            }

            $this->setCurlOption(CURLOPT_COOKIEFILE,$cookieFile);
            $this->setCurlOption(CURLOPT_COOKIEJAR,$cookieFile);
	    $this->currentCookieFile=$cookieFile;


    }
	public function getCurrentCookieFilePath() {
		return $this->currentCookieFile;

	}
    protected $options=array();
    public function setCurlOption($optionName,$optionValue) {
        $this->options[$optionName]=$optionValue;
        curl_setopt($this->curlInstance,$optionName,$optionValue);
    }
    public function __destruct() {
        curl_close($this->curlInstance);
    }
    public function getFinalUrl() {
        return curl_getinfo($this->curlInstance, CURLINFO_EFFECTIVE_URL);
    }




    /**
     * @var
     */
    protected $contents;
    public $maxRetry=5;

    /**
     * @param $url
     * @return $this
     * @throws \Exception
     */
    public function navigate($url) {

        $this->setCurlOption(CURLOPT_POST,0);
        $this->setCurlOption(CURLOPT_URL,$url);
        $this->setCurlOption(CURLOPT_RETURNTRANSFER,1);
        $this->setCurlOption(CURLOPT_SSL_VERIFYHOST,0);
        $this->setCurlOption(CURLOPT_SSL_VERIFYPEER,0);
        $this->contents=curl_exec($this->curlInstance);
        curl_error($this->curlInstance);
        $this->autoSetRefferer($url);
        sleep($this->sleep);

        if(curl_errno($this->curlInstance)) {
            throw new \Exception("Curl Error exception ".curl_errno($this->curlInstance)."-".curl_error($this->curlInstance));
        }
        if($this->dbg) {
            echo "Navigating $url\r\n";
        }
        return $this;

    }

    /**
     * @param $url
     * @param $postData string|array
     */
    public function navigatePost($url,$postData) {
        $this->setCurlOption(CURLOPT_URL,$url);
        $this->setCurlOption(CURLOPT_POST,1);

        $this->setCurlOption(CURLOPT_POSTFIELDS,$postData);
        $this->contents=curl_exec($this->curlInstance);
        $this->autoSetRefferer($url);
        sleep($this->sleep);
        if($this->dbg) {
            echo "Navigating $url\r\n";
        }
        if(curl_errno($this->curlInstance)) {
            throw new \RuntimeException("Curl error".curl_errno($this->curlInstance).curl_error($this->curlInstance));
        }


    }
    public function recreateCurl() {
       curl_close($this->curlInstance);
        $this->curlInstance=curl_init();
        $options=$this->options;
        foreach($options as $optionName=>$optionValue) {
            $this->setCurlOption($optionName,$optionValue);
        }
    }
    public function postArrayToString($postArray) {
        $str="";
        foreach($postArray as $key=>$value) {
            $value=urlencode($value);
            $str.="$key=$value&";
        }
        return $str;
    }
    public function postArrayToStringRecursive($postArray,$parentKey='') {
        $str="";
        foreach($postArray as $key=>$value) {
            if(is_array($value)) {
                if($parentKey=='') {
                    $parentKeyCurrent=$key;
                } else {
                    $parentKeyCurrent="{$parentKey}[$key]";
                }
                $str.=$this->postArrayToStringRecursive($value,$parentKeyCurrent)."&";
            } elseif($parentKey!='') {
                $value=urlencode($value);
                $str.="{$parentKey}[$key]=$value&";
            } else {
                if(stripos($value,"@")!==0) {
                    $value=urlencode($value);
                }
                $str.="$key=$value&";
            }

        }


        return $str;
    }

    /**
     * @return string
     */
    public function getContents() {

        return $this->contents;
    }

    /**
     * @param $url
     */
    protected function autoSetRefferer($url)
    {
        if ($this->getAutoRefferrer()) {
            $this->setCurlOption(CURLOPT_REFERER, $url);
        }
    }
    public function setCurlProxy(CurlProxy $curlProxy) {
        $curlProxy->setSimpleCurlBrowser($this);

    }
    public function validateProxy($expectedIp,$validationUrl) {
        $this->navigate($validationUrl);
        $IpFromRemoteHost=$this->getContents();
        $IpFromRemoteHost=trim($IpFromRemoteHost);
        $this->navigate("http://google.com");
        if($expectedIp==$IpFromRemoteHost) {
            return true;
        } else {
            throw new Exception("NOT EXPECTED IP. Ip was: $IpFromRemoteHost,while should be $expectedIp");
        }
    }
    public function getContentsParam($regex,$resultNum=1,$required=false) {
        if(preg_match($regex,$this->getContents(),$matches)) {
            return $matches[$resultNum];
        } elseif($required) {
            throw new \RuntimeException("Parameter not found for $regex");
        } else {
            return false;
        }
    }
    public function __clone() {
        $this->curlInstance=curl_copy_handle($this->curlInstance);

    }

    /**
     * @param int $option
     * @return string|array
     */
    public function getCurlInfo($option=0) {
        return curl_getinfo($this->curlInstance,$option);
    }

}