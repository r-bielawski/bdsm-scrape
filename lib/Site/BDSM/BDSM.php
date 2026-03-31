<?php
namespace Site\BDSM;
use GM\BdsmPl\PortalClient;
use GM\Browser\SimpleCurlBrowser;

/**
 * Created by Ryszard Bielawski
 * Email: ryszard.bielawski@gmail.com
 */
class BDSM
{
    /**
     * @var SimpleCurlBrowser
     */
    private $browser;
    private PortalClient $portalClient;

    public function __construct(SimpleCurlBrowser $browser)
    {
        $this->browser = $browser;
        $this->portalClient = new PortalClient($browser);
    }
    public function getIdsFromSearch() {
        return $this->portalClient->searchProfileIds([
            'sex' => 'kobieta',
            'orientacja' => 'sub',
            'city' => '',
            'minage' => 18,
            'maxage' => 34,
            'state' => '',
            'sponsoring' => '',
            'stancywilny' => '',
            'pozna' => 'man',
            'minwzrost' => '',
            'maxwzrost' => '',
            'minwaga' => 40,
            'maxwaga' => 60,
            'like' => '',
            'howhard' => '',
            'contact' => '',
        ], 3);
    }
    public function parseIdsFromUrl($url) {
        $this->browser->navigate($url);
        if (preg_match_all('#user.php\?id=([0-9]+)#',$this->browser->getContents(),$matches)) {
            return $matches[1];
        } else {
            return [];
        }

    }
    public function parseProfileById($id) {
        return $this->portalClient->fetchProfile((int) $id);
    }

}
