<?php
namespace Erpk\Harvester\Module\Media;

use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Filter;
use Erpk\Harvester\Client\Selector\Paginator;
use Erpk\Common\DateTime;
use Erpk\Common\EntityManager;
use XPathSelector\Exception\NodeNotFoundException;

class PressModule extends Module
{
    public function publishArticle($articleName, $articleBody, Entity\Country $articleLocation, $articleCategory)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->post('main/write-article');
        $request->getHeaders()
            ->set('Referer', $this->getClient()->getBaseUrl().'/main/write-article');
        $request->addPostFields([
            'article_name' => $articleName,
            'article_body' => $articleBody,
            'article_location' => $articleLocation->getId(),
            'article_category' => $articleCategory,
            '_token'  => $this->getSession()->getToken()
        ]);
        $response = $request->send();

        if ($response->isRedirect()) {
            return Article::createFromUrl($response->getLocation());
        } else {
            throw new ScrapeException();
        }
    }
    
    public function editArticle(Article $article, $articleName, $articleBody, $articleCategory)
    {
        $this->getClient()->checkLogin();
        $request = $this->getClient()->post('main/edit-article/'.$article->getId());
        $request
            ->getHeaders()
            ->set('Referer', $this->getClient()->getBaseUrl().'/main/edit-article/'.$article->getId());

        $request->addPostFields([
            'commit' => 'Edit',
            'article_name' => $articleName,
            'article_body' => $articleBody,
            'article_category' => $articleCategory,
            '_token' => $this->getSession()->getToken()
        ]);
        $response = $request->send();
        return $response->getBody(true);
    }

    public function deleteArticle(Article $article)
    {
        $this->getClient()->checkLogin();
        $request = $this->getClient()->get('delete-article/'.$article->getId().'/1');
        $request->send();
    }

    public static function parseDate($string)
    {
        $date = str_replace(',', '', trim($string));
        $date = explode(' ', $date);
        if ($date[2] == 'ago') {
            return new DateTime(implode(' ', $date));
        }
        $time = explode(':', $date[2]);
        $date = DateTime::createFromDay($date[1]);
        $date->setTime((int)$time[0], (int)$time[1], 0);
        return $date;
    }

    public static function parseArticleComments($xs)
    {
        $comments = $xs->findAll('div[contains(concat(" ", normalize-space(@class), " "), " comment-holder ")]');
        $list = $comments->map(function ($node) use ($xs) {
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
                $votes   = (int)trim($right->find('ul[1]/li/a[@id="nr_vote_'.$id.'"]')->extract());
                $deleted = false;
                if ($level > 0) {
                    $date = $right->find('ul[1]/li/span[@class="article_comment_posted_at"]');
                } else {
                    $date = $left->find('span[1]');
                }
                $date = self::parseDate($date->extract());
            } catch (\Exception $e) {
                $deleted = true;
                $votes   = null;
                $date    = null;
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
            } else if ($after['level'] < $before['level']) {
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

    public function getArticle($id)
    {
        $this->getClient()->checkLogin();
        $location = $this->getClient()->get('article/'.$id.'/1/20')->send()->getLocation();
        if ($location === '/en') {
            throw new Exception\ArticleNotFoundException('Article with ID '.$id.' has not been found.');
        }
        $xs = $this->getClient()->get($location)->send()->xpath();

        $head = $xs->find('//div[@class="newspaper_head"]');
        $date = $xs->find('//div[@class="post_details"]/em[@class="date"]');

        $em = EntityManager::getInstance();
        $countries = $em->getRepository('Erpk\Common\Entity\Country');

        try {
            $subscribers = (int)$xs->find('//em[@class="subscribers"]')->extract();
        } catch (NodeNotFoundException $e) {
            $subscribers = (int)trim($head->find('div[@class="actions"][1]/p[1]/em[1]')->extract());
        }

        $article = [
            'id'  => $id,
            'url' => 'http://www.erepublik.com'.$location,
            'date' => self::parseDate($date->extract()),
            'title' => $xs->find('//div[@class="post_content"][1]/h2[1]/a[1]')->extract(),
            'votes' => (int)trim($xs->find('//strong[@class="numberOfVotes_'.$id.'"][1]')->extract()),
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

        $requests = [];
        for ($p = 2; $p <= $pagesTotal; $p++) {
            $request = $this->getClient()->post('main/article-comment/loadMoreComments/');
            $request->addPostFields([
                'articleId' => $id,
                'page'      => $p,
                '_token'    => $this->getSession()->getToken()
            ]);
            $requests[] = $request;
        }

        $chunks = array_chunk($requests, 4);
        foreach ($chunks as $chunk) {
            $responses = $this->getClient()->send($chunk);
            foreach ($responses as $response) {
                $xs = $response->xpath();
                try {
                    foreach (self::parseArticleComments($xs->find('//body[1]')) as $comment) {
                        $article['comments'][] = $comment;
                    }
                } catch (NodeNotFoundException $e) {
                    // no comments were found on this page
                }
            }
        }
        
        return $article;
    }

    public function getNewspaper($id, $pageLimit = null)
    {
        $id = Filter::id($id);
        $this->getClient()->checkLogin();

        $response = $this->getClient()->get('newspaper/'.$id)->send();
        if (!$response->isRedirect()) {
            throw new ScrapeException();
        }

        $location = $response->getLocation();
        if ($location == '/en') {
            throw new Exception\NewspaperNotFoundException('Newspaper with ID '.$id.' does not exist');
        }

        $xs = $this->getClient()->get($location)->send()->xpath();
        $paginator = new Paginator($xs);

        $info   = $xs->find('//div[@class="newspaper_head"]');
        $avatar = $info->find('//img[@class="avatar"]/@src')->extract();
        $url    = explode('/', $info->find('div[@class="info"]/h1/a[1]/@href')->extract())[3];
        $director = $info->find('div[2]/ul[1]/li[1]/a[1]');
        

        $desc = $xs->find('//meta[@name="description"]/@content')->extract();
        if (!preg_match('/has (\d+) articles/', $desc, $articlesCount)) {
            throw new ScrapeException();
        }
        
        $em = EntityManager::getInstance();
        $countries = $em->getRepository('Erpk\Common\Entity\Country');

        $result = [
            'director' => [
                'id'   => (int)explode('/', $director->find('@href')->extract())[4],
                'name' => $director->find('@title')->extract()
            ],
            'name'          => $info->find('//h1/a/@title')->extract(),
            'avatar'        => str_replace('55x55', '100x100', $avatar),
            'country'       => $countries->findOneByName($info->find('div[1]/a[1]/img[2]/@title')->extract()),
            'subscribers'   => (int)$info->find('div[@class="actions"]')->extract(),
            'article_count' => (int)$articlesCount[1],
            'articles'      => []
        ];

        $pages  = $paginator->getLastPage();
        if ($pageLimit !== null && $pages > $pageLimit) {
            $pages = $pageLimit;
        }

        for ($page = 1; $page <= $pages; $page++) {
            $xs = $this->getClient()->get('newspaper/'.$url.'/'.$page)->send()->xpath();
            foreach ($xs->findAll('//div[@class="post"]') as $art) {
                $title    = $art->find('div[2]/h2/a')->extract();
                $artUrl   = 'http://www.erepublik.com'.$art->find('div[2]/h2/a/@href')->extract();
                $votes    = $art->find('div[1]/div[1]/strong')->extract();
                $comments = $art->find('div[2]/div[1]/a[1]')->extract();
                $date     = $art->find('div[2]/div[1]/em')->extract();
                try {
                    $category = trim($art->find('div[2]/div[1]/a[3]')->extract());
                } catch (NodeNotFoundException $e) {
                    $category = null;
                }
                
                $result['articles'][] = array(
                    'title'    => $title,
                    'url'      => $artUrl,
                    'votes'    => (int)$votes,
                    'comments' => (int)$comments,
                    'date'     => self::parseDate($date),
                    'category' => $category
                );
            }
        }
        
        return $result;
    }
}
