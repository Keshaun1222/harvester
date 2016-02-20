<?php
namespace Erpk\Harvester\Module\Management;

class MessagesThread
{
    public $threadId;
    public $lastResponderId;
    public $lastResponderName;
    public $subject;
    public $lastResponseBrief;
    public $totalMessages;
    public $unreadMessages;
    public $lastResponseTime;
    public $unread;
    public $specialMsg;
    public $replied;

    public function toArray()
    {
        return [
            'threadId' => $this->threadId,
            'lastResponderId' => $this->lastResponderId,
            'lastResponderName' => $this->lastResponderName,
            'subject' => $this->subject,
            'lastResponseBrief' => $this->lastResponseBrief,
            'totalMessages' => $this->totalMessages,
            'unreadMessages' => $this->unreadMessages,
            'lastResponseTime' => $this->lastResponseTime,
            'unread' => $this->unread,
            'specialMsg' => $this->specialMsg,
            'replied' => $this->replied
        ];
    }
}
