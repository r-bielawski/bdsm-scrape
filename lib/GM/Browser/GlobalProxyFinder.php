<?php
/**
 * Created by PhpStorm.
 * User: gemini
 * Date: 2015-05-27
 * Time: 15:03
 */
namespace GM\Browser;

use Exception;

class GlobalProxyFinder {
	protected $proxies;
	protected $fileName;
	public function __construct($fileName) {
		$this->readProxies($fileName);
		$this->fileName = $fileName;
	}
	public function readProxies( $fileName ) {

		if(!file_exists($fileName)) {
			throw new Exception("$fileName doesn't exist");
		}
		$this->proxies = array();
		$this->proxies = file( $fileName );
		foreach ( $this->proxies as &$proxy ) {
			$proxy = trim( $proxy );
		}
	}
	public function getRandomProxy() {
		if(count($this->proxies)>0) {
			return $this->proxies[array_rand($this->proxies,1)];
		} else {
			return false;
		}
	}
	public function getRandomProxyCurlObject() {
		if($proxy=$this->getRandomProxy()) {
			$cp=new CurlProxy($proxy);
			return $cp;
		} else {
			throw new Exception("No proxy available");
		}
	}
	public function refresh() {
		$this->readProxies($this->fileName);
	}
}
