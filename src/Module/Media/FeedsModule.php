<?php
namespace Erpk\Harvester\Module\Media;

use Erpk\Harvester\Exception\InvalidArgumentException;
use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Client\Selector;

class FeedsModule extends Module
{
    // Wall Id enumeration
    const WALL_FRIENDS = 'friends';
    const WALL_PARTY = 'party';
    const WALL_MU = 'mu';
    // Actions descriptor
    const POST_CREATE = 0;
    const POST_DELETE = 1;
    const POST_RETRIEVE = 2;
    const POST_VOTE = 3;
    const COMMENT_CREATE = 4;
    const COMMENT_DELETE = 5;
    const COMMENT_RETRIEVE = 6;
    const COMMENT_VOTE = 7;
    // $likeStatus values
    const UNLIKE = 0;
    const LIKE = 1;

    protected static $urls = [
        // Friend's wall
        self::WALL_FRIENDS => [
            'main/wall-post/create/', // new post
            'main/wall-post/delete/', // delete post
            'main/wall-post/older/', // retrieve posts
            'main/wall-post/vote/', // vote post
            'main/wall-comment/create/', // new comment
            'main/wall-comment/delete/', // delete comment
            'main/wall-comment/retrieve/', // retrieve comments
            'main/wall-comment/vote/'  //vote comment
        ],
        // Party wall
        self::WALL_PARTY => [
            'main/party-post/create/', // new post
            'main/party-post/delete/', // delete post
            'main/party-post/older/', // retrieve posts
            'main/party-post/vote/', // vote post
            'main/party-comment/create/', // new comment
            'main/party-comment/delete/', // delete comment
            'main/party-comment/retrieve/', // retrieve comments
            'main/party-comment/vote/'  //vote comment
        ],
        // Military unit wall
        self::WALL_MU => [
            'main/group-wall/create/post', // new post
            'main/group-wall/delete/post', // delete post
            'main/group-wall/older/retrieve', // retrieve posts
            '', // vote post
            'main/group-wall/create/comment', // new comment
            'main/group-wall/delete/comment', // delete comment
            'main/group-wall/retrieve/comment', // retrieve comments
            ''  //vote comment
        ]
    ];

    protected static function getFeedUrl($wallID, $action)
    {
        return self::$urls[$wallID][$action];
    }

    /**
     * Posts new shout
     * @param  string  $message Content of message
     * @param  string  $wallId  Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @param  integer $groupId Military Unit ID in case which wall is WALL_MU
     * @return array   Server result
     */
    public function createShout($message, $wallId = self::WALL_FRIENDS, $groupId = 0)
    {
        $url = self::getFeedUrl($wallId, self::POST_CREATE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'post_message' => $message,
            'groupId' => $groupId,
        ]);
        return $request->send()->json();
    }

    /**
     * Posts new comment
     * @param  integer $postId  Shout ID
     * @param  string  $message Content of message
     * @param  string  $wallId  Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @param  integer $groupId Military Unit ID in the case which wall is WALL_MU
     * @return array   Server result
     */
    public function createComment($postId, $message, $wallId = self::WALL_FRIENDS, $groupId = 0)
    {
        $url = self::getFeedUrl($wallId, self::COMMENT_CREATE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'comment_message' => $message,
            'postId' => $postId,
            'groupId' => $groupId,
        ]);
        return $request->send()->json();
    }

    /**
     * Deletes a post by ID
     * @param  integer $postId  Shout ID
     * @param  string  $wallId  Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @param  integer $groupId Military Unit ID in the case which wall is WALL_MU
     * @return array   Server result
     */
    public function deleteShout($postId, $wallId = self::WALL_FRIENDS, $groupId = 0)
    {
        $url = self::getFeedUrl($wallId, self::POST_DELETE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'postId' => $postId,
            'groupId' => $groupId,
        ]);
        return $request->send()->json();
    }

    /**
     * Deletes a comment by ID
     * @param  integer $commentId Comment ID
     * @param  integer $postId    Shout ID
     * @param  string  $wallId    Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @param  integer $groupId   Military Unit ID in the case which wall is WALL_MU
     * @return array   Server result
     */
    public function deleteComment($commentId, $postId, $wallId = self::WALL_FRIENDS, $groupId = 0)
    {
        $url = self::getFeedUrl($wallId, self::COMMENT_DELETE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'commentId' => $commentId,
            'postId' => $postId,
            'groupId' => $groupId,
        ]);
        return $request->send()->json();
    }

    public function getPostsFeed($wallId = self::WALL_FRIENDS, $page = 0, $groupId = 0, $postId = 0)
    {
        $url = self::getFeedUrl($wallId, self::POST_RETRIEVE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'groupId' => $groupId,
            'page' => $page,
            'view' => $postId,
        ]);
        $response = $request->send();

        return $response->getBody(true);
    }

    public function getCommentsFeed($wallId = self::WALL_FRIENDS, $postId = 0, $groupId = 0)
    {
        $url = self::getFeedUrl($wallId, self::COMMENT_RETRIEVE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'postId' => $postId,
            'groupId' => $groupId
        ]);
        $response = $request->send();

        return $response->getBody(true);
    }

    protected function parsePostsFeed($html)
    {
        $hxs = Selector\XPath::loadHTML($html);
        $postsItems = $hxs->select('//li[@class="wall_post"]');

        if (!$postsItems->hasResults()) {
            return [];
        }

        $posts = [];
        foreach ($postsItems as $postItem) {
            $postId = $postItem->select('@id')->extract();
            $postId = substr($postId, strripos($postId, '_') + 1);
            $profileId = $postItem->select('a/@href')->extract();
            $profileId = substr($profileId, strripos($profileId, '/profile/') + 10);
            $profileName = $postItem->select('div[@class="post_content"]/h6/a')->extract();
            $reportRef = $postItem->select('//a[@class="report"]/@href')->extract();
            $time = $postItem->select('div[@class="post_content"]/h6/em')->extract();
            $message = $postItem->select('div[@class="post_content"]/p')->extract();
            $message = str_replace(PHP_EOL, '<br />', $message);

            $post = new Post();
            $post->postId = (int) $postId;
            $post->profileId = (int) $profileId;
            $post->profileName = $profileName;
            $post->reportRef = $reportRef;
            $post->time = trim(substr($time, strpos($time, ' ')+1));
            $post->message = trim($message);

            $posts[] = $post;
        }

        return $posts;
    }

    protected function parseCommentsFeed($html, $postId)
    {
        $hxs = Selector\XPath::loadHTML($html);
        $commentItems = $hxs->select('//li');

        if (!$commentItems->hasResults()) {
            return array();
        }

        $comments = [];
        foreach ($commentItems as $commentItem) {
            $commentId = substr($postId, strripos($postId, '_') + 1);
            $profileId = $commentItem->select('div[@class="post_reply"]/p/strong/a/@href')->extract();
            $profileId = substr($profileId, strripos($profileId, '/profile/') + 10);
            $profileName = $commentItem->select('div[@class="post_reply"]/p/strong/a/@title')->extract();
            $reportRef = $commentItem->select('//a[@class="report"]/@href')->extract();
            $time = $commentItem->select('div[@class="post_reply"]/b/text()')->extract();
            $message = $commentItem->select('div[@class="post_reply"]/p')->extract();
            $message = str_replace(PHP_EOL, '<br />', $message);

            $comment = new Comment;
            $comment->postId = (int) $postId;
            $comment->commentId = (int) $commentId;
            $comment->profileId = (int) $profileId;
            $comment->profileName = $profileName;
            $comment->reportRef = $reportRef;
            $comment->time = $time;
            $comment->message = trim($message);

            $comments[] = $comment;
        }

        return $comments;
    }

    /**
     * Get 10 shouts which is paginated
     * @param  string  $wallId  Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @param  integer $page    Page Number start from 0 which is default
     * @param  integer $groupId Military Unit ID in the case which wall is WALL_MU
     * @return array   An array contains 10 posts sorted by time
     */
    public function getPosts($wallId = self::WALL_FRIENDS, $page = 0, $groupId = 0)
    {
        return $this->parsePostsFeed($this->getPostsFeed($wallId, $page, $groupId, 0));
    }

    /**
     * Get a shout by Id
     * @param  integer $postId  Shout ID
     * @param  string  $wallId  Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @return array   An array contains 10 posts sorted by time
     */
    public function getPostById($postId, $wallId = self::WALL_FRIENDS)
    {
        $request = $this->getClient()->get();
        switch ($wallId) {
            case self::WALL_FRIENDS:
                $request->getQuery()->set('viewPost', $postId);
                break;
            case self::WALL_PARTY:
                $request->getQuery()->set('viewPartyPost', $postId);
                break;
            case self::WALL_MU:
                $request->getQuery()->set('unitPost', $postId);
                break;
        }
        $html = $request->send()->getBody(true);

        return $this->parsePostsFeed($html)[0];
    }

    /**
     * Get all comments of a shout by ID
     * @param  string  $postId  Shout ID
     * @param  string  $wallId  Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @param  integer $groupId Military Unit ID in the case which wall is WALL_MU
     * @return array   An array contains comments
     */
    public function getComments($postId, $wallId = self::WALL_FRIENDS, $groupId = 0)
    {
        $response = json_decode($this->getCommentsFeed($wallId, $postId, $groupId), true);
        if ($response['message'] == 1) {
            return $this->parseCommentsFeed($response['success_message'], $postId);
        } else {
            return $response['error_message'];
        }
    }

    /**
     * Votes a shout by ID
     * @param  integer $postId     Shout ID
     * @param  integer $likeStatus determines vote(1 or FeedsModule::LIKE is default) or unvote (0 or FeedsModule::UNLIKE)
     * @param  string  $wallId     Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @throws InvalidArgumentException
     * @return array   Server result
     */
    public function voteShout($postId, $likeStatus, $wallId = self::WALL_FRIENDS)
    {
        if ($wallId == self::WALL_MU) {
            throw new InvalidArgumentException("Military Unit's wall does not support votes.");
        }

        $url = self::getFeedUrl($wallId, self::POST_VOTE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'postId' => $postId,
            'likeStatus' => $likeStatus,
        ]);
        return $request->send()->json();
    }

    /**
     * Votes a shout by ID
     * @param  integer $commentId  Comment ID
     * @param  integer $postId     Shout ID
     * @param  integer $likeStatus determines vote (1 or FeedsModule::LIKE is default) or unvote (0 or FeedsModule::UNLIKE)
     * @param  string  $wallId     Wall to post (FeedsModule::WALL_FRIENDS is default, FeedsModule::WALL_PARTY, FeedsModule::WALL_MU)
     * @throws InvalidArgumentException
     * @return array   Server result
     */
    public function voteComment($commentId, $postId, $likeStatus = self::LIKE, $wallId = self::WALL_FRIENDS)
    {
        if ($wallId == self::WALL_MU) {
            throw new InvalidArgumentException("Military Unit's wall does not support votes.");
        }

        $url = self::getFeedUrl($wallId, self::POST_VOTE);
        $request = $this->getClient()->post($url)->csrf();
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            'postId' => $postId,
            'commentId' => $commentId,
            'likeStatus' => $likeStatus,
        ]);
        return $request->send()->json();
    }
}
