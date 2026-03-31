<?php
/**
 * Created by Ryszard Bielawski
 * Email: ryszard.bielawski@gmail.com
 */

namespace GM\BdsmPl;


use GM\Browser\CurlProxy;
use GM\Browser\SimpleCurlBrowser;
use R;

class MailingAutomator
{
    /**
     * @var Parser
     */
    private $parser;
    /**
     * @var SimpleCurlBrowser
     */
    private $browser;

    public function __construct(Parser $parser,SimpleCurlBrowser $browser)
    {
        $this->parser = $parser;
        $this->browser = $browser;
    }
    public function updateSentMessages() {
        $accounts=R::findAll('account','active=1');
        foreach ($accounts as $account) {
            $this->browser->setCurlProxy(new CurlProxy($account->proxy));
            $this->parser->login($account->login, $account->password);
            $this->updateSentMessagesss($account);
            $this->sendMessages($account);

        }
    }
    public function getRecipientsToSentMessage($account) {
        $condition = "where active=0 and profile_id not in (select recipient_id from sent where account_id=:account_id) order by profile_id desc LIMIT 0,5";
        if($account->query_condition!=null) {
            $condition=$account->query_condition;
        }
        $profilesToSend=R::findAll('profile', "{$condition}",array(':account_id'=>$account->id));
        $out=array();
        foreach($profilesToSend as $profile) {
            $out[]=$profile->profile_id;
        }
        return $out;
    }
    public function isAllowedToPost($account) {
        return $this->isMessageBelowLimit($account->id) && $this->isEnoughTimeAfterError($account);
    }
    public function isMessageBelowLimit($accountId) {
        $items=R::getAll('select count(id) as cnt from sent where account_id=:account_id and date=:date',array(':account_id'=>$accountId,':date'=>date("Y-m-d")));
        var_dump(date("Y-m-d"));
        if($items[0]['cnt']<150) {
            return true;
        }
        else {
            return false;
        }

    }

    /**
     * @param $account
     * @throws Exceptions\LoginFailed
     */
    private function updateSentMessagesss($account)
    {

        $messages = $this->parser->getUsersSentTo();
        $beans = array();
        foreach ($messages as $message) {
            $sent = R::dispense('sent');
            $sent->recipient_id = $message;
            $sent->account_id = $account->id;
            $sent->date = date("Y-m-d");
            try {
                R::store($sent);
            } catch (\Exception $e) {

            }
        }
    }

    private function isEnoughTimeAfterError($account)
    {
        $diff=time()-strtotime($account->last_post_error);
        var_dump($diff);
        if($diff>=3600) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $account
     */
    private function sendMessages($account)
    {

        if ($this->isAllowedToPost($account)) {
            $this->log("Account $account->login is allowed to post");
            $recipients = $this->getRecipientsToSentMessage($account);
            foreach ($recipients as $recipient) {
                try {

                    $this->parser->sendMessage($recipient, mb_convert_encoding($account->message,'iso-8859-2','UTF-8'));

                    $account->error_count = 0;
                    R::store($account);
                    $this->log("Success posting for $account->login");
                } catch (\Exception $e) {
                    $account->last_post_error = date("Y-m-d H:i:s");
                    $account->error_count ++;
                    R::store($account);
                    $this->log("Error posting for $account->login");
                    return; //breaking loop
                } catch(\Exception $e) {
                    echo 'disabled'.get_class($e);
                }
                sleep(10);
            }

        } else {
            $this->log("Account $account->login not allowed to post");
        }
    }
    public function log($msg) {
        file_put_contents("log.log",$msg.PHP_EOL,FILE_APPEND);
        echo $msg.PHP_EOL;
    }

}