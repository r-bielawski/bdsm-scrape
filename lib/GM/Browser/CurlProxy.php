<?php
/**
 * Created by PhpStorm.
 * User: gemini
 * Date: 25.08.14
 * Time: 22:02
 */
namespace GM\Browser;

class CurlProxy {
    protected $proxy;
    protected $parsedProxyArray;
    public function __construct($proxy) {
        $this->proxy=$proxy;
        $this->parseProxy();

    }
    protected function parseProxy() {
        $proxy=trim((string)$this->proxy);
        $this->parsedProxyArray=array();
        if($proxy==='') {
            return;
        }

        // Legacy behavior: raw IPv4 means "bind outgoing interface IP" (CURLOPT_INTERFACE).
        if(filter_var($proxy, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->parsedProxyArray['ip']=$proxy;
            return;
        }

        // Support URI-like formats (e.g. http://user:pass@host:port, socks5://host:port).
        if(strpos($proxy,'://')!==false) {
            $u=@parse_url($proxy);
            if(is_array($u) && !empty($u['host'])) {
                $this->parsedProxyArray['scheme']=isset($u['scheme'])?$u['scheme']:null;
                $this->parsedProxyArray['host']=$u['host'];
                if(isset($u['port'])) {
                    $this->parsedProxyArray['port']=$u['port'];
                }
                if(isset($u['user'])) {
                    $this->parsedProxyArray['login']=$u['user'];
                }
                if(isset($u['pass'])) {
                    $this->parsedProxyArray['password']=$u['pass'];
                }
                return;
            }
        }

        // Support: user:pass@host:port OR host:port
        $auth=null;
        $hostPort=$proxy;
        if(strpos($proxy,'@')!==false) {
            $atpos=strrpos($proxy,'@');
            $auth=substr($proxy,0,$atpos);
            $hostPort=substr($proxy,$atpos+1);
        }

        if(is_string($auth) && $auth!=='') {
            $ap=explode(':',$auth,2);
            if(count($ap)===2) {
                $this->parsedProxyArray['login']=$ap[0];
                $this->parsedProxyArray['password']=$ap[1];
            }
        }

        if(strpos($hostPort,':')!==false) {
            $hp=explode(':',$hostPort,2);
            if(count($hp)===2 && $hp[0]!=='' && $hp[1]!=='' ) {
                $this->parsedProxyArray['host']=$hp[0];
                $this->parsedProxyArray['port']=$hp[1];
            }
        } else {
            $this->parsedProxyArray['host']=$hostPort;
        }

    }
    public function setCurl($ch) {
        if(isset($this->parsedProxyArray['ip'])) {
            curl_setopt($ch,CURLOPT_INTERFACE,$this->parsedProxyArray['ip']);
        } elseif(isset($this->parsedProxyArray['host'])) {
            $proxy=$this->parsedProxyArray['host'];
            if(!empty($this->parsedProxyArray['scheme'])) {
                $proxy=$this->parsedProxyArray['scheme'].'://'.$proxy;
                $this->applyProxyTypeIfPossible($ch,(string)$this->parsedProxyArray['scheme']);
            }
            curl_setopt($ch,CURLOPT_PROXY,$proxy);
            if(isset($this->parsedProxyArray['port'])) {
                curl_setopt($ch,CURLOPT_PROXYPORT,$this->parsedProxyArray['port']);
            }
            if(isset($this->parsedProxyArray['login']) && isset($this->parsedProxyArray['password'])) {
                curl_setopt($ch,CURLOPT_PROXYUSERPWD,$this->parsedProxyArray['login'].':'.$this->parsedProxyArray['password']);
            }
        }
    }
    public function setSimpleCurlBrowser(SimpleCurlBrowser $curlBrowser) {
        if(isset($this->parsedProxyArray['ip'])) {
            $curlBrowser->setCurlOption(CURLOPT_INTERFACE,$this->parsedProxyArray['ip']);

        } elseif(isset($this->parsedProxyArray['host'])) {
            $proxy=$this->parsedProxyArray['host'];
            if(!empty($this->parsedProxyArray['scheme'])) {
                $proxy=$this->parsedProxyArray['scheme'].'://'.$proxy;
                $this->applyProxyTypeIfPossible($curlBrowser,(string)$this->parsedProxyArray['scheme']);
            }
            $curlBrowser->setCurlOption(CURLOPT_PROXY,$proxy);
            if(isset($this->parsedProxyArray['port'])) {
                $curlBrowser->setCurlOption(CURLOPT_PROXYPORT,$this->parsedProxyArray['port']);
            }
            if(isset($this->parsedProxyArray['login']) && isset($this->parsedProxyArray['password'])) {
                $curlBrowser->setCurlOption(CURLOPT_PROXYUSERPWD,$this->parsedProxyArray['login'].':'.$this->parsedProxyArray['password']);
            }
        }
    }

    private function applyProxyTypeIfPossible($target,$scheme) {
        $scheme=strtolower((string)$scheme);
        $map=array(
            'http'=>'CURLPROXY_HTTP',
            'https'=>'CURLPROXY_HTTPS',
            'socks5'=>'CURLPROXY_SOCKS5',
            'socks5h'=>'CURLPROXY_SOCKS5_HOSTNAME',
            'socks4'=>'CURLPROXY_SOCKS4',
            'socks4a'=>'CURLPROXY_SOCKS4A',
        );
        if(!isset($map[$scheme])) {
            return;
        }
        $const=$map[$scheme];
        if(!defined($const)) {
            return;
        }
        // $target may be curl handle or SimpleCurlBrowser.
        if($target instanceof SimpleCurlBrowser) {
            $target->setCurlOption(CURLOPT_PROXYTYPE, constant($const));
        } else {
            @curl_setopt($target, CURLOPT_PROXYTYPE, constant($const));
        }
    }

    public function __toString() {
        return $this->proxy;
    }
    public function getIp() {
        return $this->parsedProxyArray['host'];
    }
}


