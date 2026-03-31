<?php
/**
 * Created by Ryszard Bielawski
 * Email: ryszard.bielawski@gmail.com
 */
namespace GM\BdsmPl;

use GM\BdsmPl\Exceptions\LoginFailed;
use mysql_xdevapi\Exception;

class Parser {
    /**
     * @var \GM\Browser\SimpleBrowserInterface
     */
    private $browser;
    private $currentUser;

    public function __construct(\GM\Browser\SimpleCurlBrowser $browser)
    {
        $this->browser = $browser;
    }
    public function login($username,$password) {
        $this->browser->navigate("https://bdsm.pl/login.php");
        $this->browser->navigatePost("https://bdsm.pl/login.php",array('nick'=>$username,'pass'=>$password));
        if(!preg_match('/Zalogowany/',$this->browser->getContents())) {
            throw new LoginFailed();
        }
        $this->currentUser=$username;
    }
    public function sendMessage($recipientId,$message) {
        $this->browser->navigate('https://bdsm.pl/user.php?id='.$recipientId);
        if(preg_match('/id=enterlink/',$this->browser->getContents())) {

            return;//skipping
        }
        $this->browser->navigate("https://bdsm.pl/napisz_form.php?id={$recipientId}&link=user.php%3Fid%3D{$recipientId}");
        if(!preg_match('/<form action=(.*) /',$this->browser->getContents(),$matches)) {
            throw new \Exception("Couldn't get action");
        }
        $this->browser->navigatePost("https://bdsm.pl/".$matches[1],array('message'=>$message));
        if(!preg_match('/<h1>Twoja wiadomo/',$this->browser->getContents())) {
            echo $this->browser->getContents();
            throw new \Exception("Message wasn't sent");
        }
    }
    public function getUsersSentTo() {
        $this->browser->navigate('https://bdsm.pl/wiadomosci_wyslane.php?all=1');
        preg_match_all('/user.php\?id=([0-9]+)/',$this->browser->getContents(),$matches);
        $matches[1]=array_unique($matches[1]);
        return $matches[1];

    }
}