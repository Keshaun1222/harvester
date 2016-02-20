<?php
namespace Erpk\Harvester\Module\Management;

class Message
{
    public $threadId;
    public $subject;
    public $senderId;
    public $senderName;
    public $recieverIds;
    public $recieverNames;
    public $time;
    public $unread;
    public $body;

    public function toArray()
    {
        return [
            'threadId' => $this->threadId,
            'senderId' => $this->senderId,
            'senderName' => $this->senderName,
            'recieverIds' => $this->recieverIds,
            'recieverNames' => $this->recieverNames,
            'time' => $this->time,
            'unread' => $this->unread,
            'body' => $this->body,
        ];
    }
}
