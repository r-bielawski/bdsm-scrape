<?php
/**
 * Created by Ryszard Bielawski
 * Email: ryszard.bielawski@gmail.com
 */
namespace GM\BdsmPl;

use GM\BdsmPl\Exceptions\LoginFailed;

class Parser {
    /**
     * @var \GM\Browser\SimpleBrowserInterface
     */
    private $browser;
    private PortalClient $portalClient;

    public function __construct(\GM\Browser\SimpleCurlBrowser $browser)
    {
        $this->browser = $browser;
        $this->portalClient = new PortalClient($browser);
    }
    public function login($username,$password) {
        $this->portalClient->login((string) $username, (string) $password);
    }
    public function sendMessage($recipientId,$message) {
        $this->portalClient->sendMessage((int) $recipientId, (string) $message);
    }
    public function getUsersSentTo() {
        return $this->portalClient->getUsersSentTo();

    }
}
