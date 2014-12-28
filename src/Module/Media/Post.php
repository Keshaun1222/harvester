<?php
namespace Erpk\Harvester\Module\Media;

class Post
{
    public $postId;
    public $profileId;
    public $profileName;
    public $reportRef;
    public $time;
    public $message;

    public function toArray()
    {
        return [
            'postId'      =>  $this->postId,
            'profileId'   =>  $this->profileId,
            'profileName' =>  $this->profileName,
            'reportRef'   =>  $this->reportRef,
            'time'        =>  $this->time,
            'message'     =>  $this->message
        ];
    }
}
