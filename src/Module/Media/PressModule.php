<?php
namespace Erpk\Harvester\Module\Media;

use Erpk\Common\DateTime;
use Erpk\Common\Entity\Country;
use Erpk\Common\EntityManager;
use Erpk\Harvester\Client\Selector\Paginator;
use Erpk\Harvester\Exception\NotFoundException;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use GuzzleHttp\Psr7\Uri;
use XPathSelector\Exception\NodeNotFoundException;
use XPathSelector\Node;

class PressModule extends Module
{
    /**
     * @var string
     */
    private static $articleIdPattern = '@article/(\d+)@';

    /**
     * @param string $articleName
     * @param string $articleBody
     * @param int|Country $articleLocation
     * @param int $articleCategory
     * @return int Article ID
     * @throws ScrapeException
     */
    public function publishArticle($articleName, $articleBody, $articleLocation, $articleCategory)
    {
        if ($articleLocation instanceof Country) {
            $articleLocation = $articleLocation->getId();
        }

        $request = $this->getClient()->post('main/write-article')->csrf();
        $request->setRelativeReferer('main/write-article');
        $request->addPostFields([
            'article_name' => $articleName,
            'article_body' => $articleBody,
            'article_location' => $articleLocation,
            'article_category' => $articleCategory,
        ]);
        $response = $request->send();

        if (preg_match(self::$articleIdPattern, $response->getLocation(), $matches)) {
            return (int)$matches[1];
        } else {
            throw new ScrapeException();
        }
    }

    /**
     * @param int $articleId
     * @param string $articleName
     * @param string $articleBody
     * @param int $articleCategory
     */
    public function editArticle($articleId, $articleName, $articleBody, $articleCategory)
    {
        $url = "main/edit-article/$articleId";
        $request = $this->getClient()->post($url)->csrf();
        $request->setRelativeReferer($url);
        $request->addPostFields([
            'commit' => 'Edit',
            'article_name' => $articleName,
            'article_body' => $articleBody,
            'article_category' => $articleCategory,
        ]);
        $request->send();
    }

    /**
     * @param int $articleId
     */
    public function deleteArticle($articleId)
    {
        $this->getClient()->get("main/delete-article/$articleId/1")->send();
    }

    /**
     * @param string $str
     * @return DateTime
     */
    public static function parseDate($str)
    {
        $matches = [];
        if (preg_match('/Day ([\d,]+), (\d{1,2}):(\d{1,2})/', $str, $matches)) {
            $day = (int)str_replace(',', '', $matches[1]);
            $date = DateTime::createFromDay($day);
            $date->setTime((int)$matches[2], (int)$matches[3], 0);
            return $date;
        } else {
            $str = str_replace('one ', '1 ', $str);
            return new DateTime($str);
        }
    }

    /**
     * @param Node $xs
     * @return array
     */
    public static function parseArticleComments(Node $xs)
    {
        $comments = $xs->findAll('div[contains(concat(" ", normalize-space(@class), " "), " comment-holder ")]');
        $list = $comments->map(function (Node $node) use ($xs) {
            $class = $node->find('@class')->extract();
            if (preg_match('/indent-level-(\d+)/', $class, $level) > 0) {
                $level = (int)$level[1];
            } else {
                throw new ScrapeException();
            }

            $left = $node->find('div[1]');
            $right = $node->find('div[2]/div[1]');

            if ($level > 0) {
                $nameholder = $right->find('span[1]/a[1]');
                $avatarholder = $right->find('div[1]');
            } else {
                $nameholder = $left->find('a[1]');
                $avatarholder = $left->find('div[1]');
            }

            $id = (int)str_replace('comment', '', $node->find('@id')->extract());
            try {
                $votes = (int)trim($right->find('ul[1]/li/a[@id="nr_vote_' . $id . '"]')->extract());
                $deleted = false;
                if ($level > 0) {
                    $date = $right->find('ul[1]/li/span[@class="article_comment_posted_at"]');
                } else {
                    $date = $left->find('span[1]');
                }
                $date = self::parseDate($date->extract());
            } catch (\Exception $e) {
                $deleted = true;
                $votes = null;
                $date = null;
            }

            switch ($level) {
                case 0:
                    $content = $right;
                    $toRemove = [
                        'a[@class="cmnt-report-link"][1]',
                        'div[@class="list_voters"][1]',
                        'ul[@class="reply_links"][1]',
                        'form[1]',
                        'div[@style="clear: both"][1]'
                    ];
                    break;
                default:
                    $toRemove = ['a[@class="nameholder"]'];
                    $content = $right->find('span[@class="comment-text"]');
                    break;
            }

            $contentRaw = $content->getDOMNode();
            foreach ($toRemove as $path) {
                foreach ($content->findAll($path) as $result) {
                    $contentRaw->removeChild($result->getDOMNode());
                }
            }

            return [
                'id' => $id,
                'level' => $level,
                'parent_id' => null,
                'deleted' => $deleted,
                'date' => $date,
                'votes' => $votes,
                'author' => [
                    'id' => (int)explode('/', $nameholder->find('@href')->extract())[4],
                    'name' => $nameholder->find('@title')->extract(),
                    'avatar' => $avatarholder->find('a[1]/img[1]/@src')->extract()
                ],
                'content_html' => trim($content->innerHTML())
            ];
        });

        // determine parent_ids for every comment
        $levels = [];
        $before = null;
        foreach ($list as &$after) {
            if ($before === null) {
                $before = $after;
                continue;
            }
            //
            if ($after['level'] > $before['level']) {
                $levels[] = $before['id'];
            } elseif ($after['level'] < $before['level']) {
                for ($i = $after['level']; $i > $before['level']; $i--) {
                    array_pop($levels);
                }
            }

            if (!empty($levels)) {
                $after['parent_id'] = end($levels);
            }
            //
            $before = $after;
        }

        return $list;
    }

    /**
     * @param int $id
     * @return array
     * @throws NotFoundException
     */
    public function getArticle($id)
    {
        $url = $this->getClient()->get("article/$id/1/20")->send()->getLocation();

        if (stripos($url, 'article') === false) {
            throw new NotFoundException("Article ID:$id does not exist.");
        }

        $xs = $this->getClient()->get($url)->send()->xpath();

        $head = $xs->find('//div[@class="newspaper_head"]');
        $date = $xs->find('//div[@class="post_details"]/em[@class="date"]');

        $em = EntityManager::getInstance();
        $countries = $em->getRepository(Country::class);

        try {
            $subscribers = (int)$xs->find('//em[@class="subscribers"]')->extract();
        } catch (NodeNotFoundException $e) {
            $subscribers = (int)trim($head->find('div[@class="actions"][1]/p[1]/em[1]')->extract());
        }

        $article = [
            'id' => $id,
            'url' => Uri::resolve($this->getClient()->getBaseUri(), $url),
            'date' => self::parseDate($date->extract()),
            'title' => $xs->find('//div[@class="post_content"][1]/h2[1]/a[1]')->extract(),
            'votes' => (int)trim($xs->find('//strong[@class="numberOfVotes_' . $id . '"][1]')->extract()),
            'category' => null,
            'newspaper' => [
                'id' => (int)$xs->find('//input[@id="newspaper_id"]/@value')->extract(),
                'owner' => [
                    'id' => (int)explode('/', $head->find('div[2]/ul[1]/li[1]/a[1]/@href')->extract())[4],
                    'name' => $head->find('div[2]/ul[1]/li[1]/a[1]/@title')->extract()
                ],
                'name' => $head->find('div[1]/a[1]/@title')->extract(),
                'country' => $countries->findOneByName($xs->find('//a[@class="newspaper_country"][1]/img/@title')->extract()),
                'avatar' => $head->find('div[1]/a[1]/img[@class="avatar"]/@src')->extract(),
                'subscribers' => $subscribers
            ],
            'content_html' => trim($xs->find('//div[@class="full_content"]')->innerHTML()),
            'comments' => []
        ];

        try {
            $article['comments'] = self::parseArticleComments($xs->find('//div[@id="loadMoreComments"]'));
        } catch (\Exception $e) {
        }

        try {
            $article['category'] = $xs->find('//a[@class="category_name"][1]/@title')->extract();
        } catch (\Exception $e) {
        }

        $pagesTotal = 1;
        try {
            $moreComments = $xs->find('//a[@class="load-more-comments"]/@onclick');
            if (preg_match('/commentCurrentPage >= (\d+)/', $moreComments, $pages) > 0) {
                $pagesTotal = (int)$pages[1];
            }
        } catch (\Exception $e) {
        }

        unset($xs);

        for ($p = 2; $p <= $pagesTotal; $p++) {
            $request = $this->getClient()->post('main/article-comment/loadMoreComments/')->csrf();
            $request->addPostFields([
                'articleId' => $id,
                'page' => $p,
            ]);
            $xs = $request->send()->xpath();

            try {
                foreach (self::parseArticleComments($xs->find('//body[1]')) as $comment) {
                    $article['comments'][] = $comment;
                }
            } catch (NodeNotFoundException $e) {
                // no comments were found on this page
            }
        }
        return $article;
    }

    /**
     * @param int $id
     * @param int|null $pageLimit
     * @return array
     * @throws NotFoundException
     * @throws ScrapeException
     */
    public function getNewspaper($id, $pageLimit = null)
    {
        $response = $this->getClient()->get("newspaper/$id")->send();
        if (!$response->isRedirect()) {
            throw new ScrapeException();
        }

        $location = $response->getLocation();
        if ($location == '/en') {
            throw new NotFoundException("Newspaper ID:$id does not exist.");
        }

        $xs = $this->getClient()->get($location)->send()->xpath();
        $paginator = new Paginator($xs);

        $info = $xs->find('//div[@class="newspaper_head"]');
        $avatar = $info->find('//img[@class="avatar"]/@src')->extract();
        $url = explode('/', $info->find('div[@class="info"]/h1/a[1]/@href')->extract())[3];
        $director = $info->find('div[2]/ul[1]/li[1]/a[1]');


        $desc = $xs->find('//meta[@name="description"]/@content')->extract();
        if (!preg_match('/has (\d+) articles/', $desc, $articlesCount)) {
            throw new ScrapeException();
        }

        $em = EntityManager::getInstance();
        $countries = $em->getRepository(Country::class);

        $result = [
            'director' => [
                'id' => (int)explode('/', $director->find('@href')->extract())[4],
                'name' => $director->find('@title')->extract()
            ],
            'name' => $info->find('//h1/a/@title')->extract(),
            'url' => Uri::resolve($this->getClient()->getBaseUri(), $location),
            'avatar' => str_replace('55x55', '100x100', $avatar),
            'country' => $countries->findOneByName($info->find('div[1]/a[1]/img[2]/@title')->extract()),
            'subscribers' => (int)$info->find('div[@class="actions"]')->extract(),
            'article_count' => (int)$articlesCount[1],
            'articles' => []
        ];

        $pages = $paginator->getLastPage();
        if ($pageLimit !== null && $pages > $pageLimit) {
            $pages = $pageLimit;
        }

        for ($page = 1; $page <= $pages; $page++) {
            $xs = $this->getClient()->get('newspaper/' . $url . '/' . $page)->send()->xpath();
            foreach ($xs->findAll('//div[@class="post"]') as $art) {
                $title = $art->find('div[2]/h2/a')->extract();
                $artUrl = 'http://www.erepublik.com' . $art->find('div[2]/h2/a/@href')->extract();
                $votes = $art->find('div[1]/div[1]/strong')->extract();
                $comments = $art->find('div[2]/div[1]/a[1]')->extract();
                $date = $art->find('div[2]/div[1]/em')->extract();
                try {
                    $category = trim($art->find('div[2]/div[1]/a[3]')->extract());
                } catch (NodeNotFoundException $e) {
                    $category = null;
                }

                $result['articles'][] = [
                    'title' => $title,
                    'url' => $artUrl,
                    'votes' => (int)$votes,
                    'comments' => (int)$comments,
                    'date' => self::parseDate($date),
                    'category' => $category
                ];
            }
        }

        return $result;
    }
}
