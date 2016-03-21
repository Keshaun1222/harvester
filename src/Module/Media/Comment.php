<?php
namespace Erpk\Harvester\Module\Media;

class Comment
{
    public $postId;
    public $commentId;
    public $profileId;
    public $profileName;
    public $reportRef;
    public $time;
    public $message;

    public function toArray()
    {
        return [
            'postId' => $this->postId,
            'commentId' => $this->commentId,
            'profileId' => $this->profileId,
            'profileName' => $this->profileName,
            'reportRef' => $this->reportRef,
            'time' => $this->time,
            'message' => $this->message,
        ];
    }
}
