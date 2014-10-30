<?php
namespace Erpk\Harvester\Module\Citizen;

use Erpk\Harvester\Module\Module;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Filter;
use Erpk\Common\Citizen\Rank;
use Erpk\Common\Citizen\Helpers;
use Erpk\Common\DateTime;
use Erpk\Common\EntityManager;

class CitizenModule extends Module
{
    /**
     * Returns information on given citizen
     * @param  int   $id  Citizen ID
     * @return array      Citizen information
     */
    public function getProfile($id)
    {
        $id = Filter::id($id);

        $request = $this->getClient()->get('citizen/profile/'.$id);
        $request->getParams()->set('cookies.disable', true);

        try {
            $response = $request->send();
            $result = self::parseProfile($response->getBody(true));
            $result['id'] = $id;
            return $result;
        } catch (ClientErrorResponseException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new Exception\CitizenNotFoundException('Citizen '.$id.' not found.');
            } else {
                throw $e;
            }
        }
    }
    
    /**
     * Parses citizen's profile HTML page and returns useful information
     * @param  string $html HTML content of citizen's profile page
     * @return array        Information about citizen
     */
    public static function parseProfile($html)
    {
        $em = EntityManager::getInstance();
        $countries = $em->getRepository('Erpk\Common\Entity\Country');
        $regions = $em->getRepository('Erpk\Common\Entity\Region');
        
        $parseStat = function ($string) {
            $string = trim($string);
            $string = substr($string, 0, strpos($string, '/'));
            $string = str_ireplace(',', '', $string);
            return (int)$string;
        };
        
        $xs = Selector\XPath::loadHTML($html);
        $result = array();
        
        $content  = $xs->select('//div[@id="container"][1]/div[@id="content"][1]');
        $sidebar  = $content->select('//div[@class="citizen_sidebar"][1]');
        $second   = $content->select('//div[@class="citizen_second"]');
        $state    = $content->select('//div[@class="citizen_state"]');
        $military = $content->select('//div[@class="citizen_military"]');
        
        /**
         * BASIC DATA
         */
        $result['id'] = null;
        $viewFriends = $content->select('//a[@class="view_friends"][1]/@href');
        if ($viewFriends->hasResults()) {
            preg_match('@^/[^/]+/main/citizen-friends/([0-9]+)$@', $viewFriends->extract(), $matches);
            $result['id'] = (int)$matches[1];
        }
        
        $result['name'] = $content->select('//img[@class="citizen_avatar"]/@alt')->extract();
        $birth = new DateTime(trim($second->select('p[2]')->extract()));
        $result['birth'] = $birth->format('Y-m-d');
        
        $avatar = $content->select('//img[@class="citizen_avatar"][1]/@style')->extract();
        $avatar = Selector\RegEx::find($avatar, '/background-image\: url\(([^)]+)\);/i');
        $result['avatar'] = Selector\Filter::normalizeAvatar($avatar->group(0));
        
        $result['online'] = $content->select('//span[@class="citizen_presence"][1]/img[1]/@alt')->extract() == 'online';
        
        /**
         * BAN/DEAD
         */
        $ban = $state->select(
            'div/span/img[contains(@src, "perm_banned")]/../..'
        );
        if ($ban->hasResults()) {
            $result['ban'] = array(
                'type' => trim($ban->select('span')->extract()),
                'reason' => $ban->select('@title')->extract()
            );
        } else {
            $result['ban'] = null;
        }
        $dead = $state->select(
            'div/span/img[contains(@src, "dead_citizen")]/../..'
        );
        $result['alive'] = $dead->hasResults() === false;
        
        $exp = $content->select('//div[@class="citizen_experience"]');
        $result['level'] = (int)$exp->select('strong[@class="citizen_level"]')->extract();
        $result['division'] = Helpers::getDivision($result['level']);
        $result['experience'] = $parseStat($exp->select('div/p')->extract());
        $result['military']['strength'] = (float)str_ireplace(',', '', trim($military->select('h4')->extract()));
        $item1 = $military->item(1);
        if (!$item1) {
            throw new ScrapeException;
        }
        $result['elite_citizen'] = $content->select('//span[@title="eRepublik Elite Citizen"][1]')->hasResults();
        $result['national_rank'] = (int)$second->select('small[3]/strong')->extract();
        
        $result['military']['rank'] = new Rank($parseStat($item1->select('div/small[2]/strong')->extract()));
        $result['military']['base_hit'] = Helpers::getHit(
            $result['military']['strength'],
            $result['military']['rank']->getLevel(),
            0,
            $result['elite_citizen']
        );

        $guerrilla = function () use ($content) {
            $div = $content->select('//div[@class="guerilla_fights_history"][1]');
            if ($div->hasResults()) {
                return [
                    'won' => (int)$div->select('div[@title="Guerrilla matches won"][1]/span[1]')->extract(),
                    'lost' => (int)$div->select('div[@title="Guerrilla matches lost"][1]/span[1]')->extract(),
                ];
            } else {
                return ['won' => null, 'lost' => null];
            }
        };

        $result['military']['guerrilla'] = $guerrilla();

        $result['military']['mass_destruction'] = [
            'small_bombs' => 0,
            'big_bombs'   => 0,
        ];

        $massDestruction = $content->select('//div[@class="citizen_mass_destruction"][1]');
        if ($massDestruction->hasResults()) {
            $result['military']['mass_destruction'] = [
                'small_bombs' => (int)$massDestruction->select('strong/img[@title="Small Bombs used"]/../b[1]')->extract(),
                'big_bombs' => (int)$massDestruction->select('strong/img[@title="Big Bombs used"]/../b[1]')->extract(),
            ];
        }
        
        $info = $sidebar->select('div[1]');
        $result['citizenship'] = $countries->findOneByName((string)$info->select('a[3]/img[1]/@title')->extract());
        $result['residence'] = array(
            'country' => $countries->findOneByName($info->select('a[1]/@title')->extract()),
            'region'  => $regions->findOneByName($info->select('a[2]/@title')->extract()),
        );
        
        if (!isset($result['residence']['country'], $result['residence']['region'], $result['citizenship'])) {
            throw new ScrapeException;
        }
        
        $about = $content->select('//div[@class="about_message profile_section"]/p');
        if ($about->hasResults()) {
            $result['about'] = strip_tags($about->extract());
        } else {
            $result['about'] = null;
        }
        
        $activity = $content->select('//div[@class="citizen_activity"]');
        
        $places = $content->select('//div[@class="citizen_activity"]/div[@class="place"]');
        
        $party = $places->item(0);
        $unit = $places->item(1);
        $newspaper = $places->item(2);
        
        $class = $party->select('h3/@class');
        if (!$class->hasResults() || $class->extract() != 'noactivity') {
            $url = $party->select('div/span/a/@href');
            if (!$url->hasResults()) {
                $result['party'] = null;
            } else {
                $url = $url->extract();
                $start = strrpos($url, '-')+1;
                $length = strrpos($url, '/')-$start;
                $result['party'] = array(
                    'id'    =>  (int)substr($url, $start, $length),
                    'name'  =>  trim($party->select('div[1]/span/a')->extract()),
                    'avatar'=>  Selector\Filter::normalizeAvatar($party->select('div/img/@src')->extract()),
                    'role'  =>  trim($party->select('h3[1]')->extract())
                );
            }
        } else {
            $result['party'] = null;
        }
        
        if ($unit->select('div[1]')->hasResults()) {
            $url = $unit->select('div[1]/a[1]/@href')->extract();
            $avatar = $unit->select('div[1]/a[1]/img[1]/@src')->extract();
            $createdAt = preg_replace('#.*([0-9]{4})/([0-9]{2})/([0-9]{2}).*#', '\1-\2-\3', $avatar);
            $result['military']['unit'] = array(
                'id'         => (int)substr($url, strrpos($url, '/')+1),
                'name'       => $unit->select('div[1]/a[1]/span[1]')->extract(),
                'created_at' => $createdAt,
                'avatar'     => $avatar,
                'role'       => trim($unit->select('h3[1]')->extract())
            );
        } else {
            $result['military']['unit'] = null;
        }
        
        if ($newspaper->select('div[1]')->hasResults()) {
            $url    = $newspaper->select('div[1]/a[1]/@href')->extract();
            $start  = strrpos($url, '-')+1;
            $length = strrpos($url, '/')-$start;
            
            $result['newspaper'] = array(
                'id'   => (int)substr($url, $start, $length),
                'name' => $newspaper->select('div[1]/a/@title')->extract(),
                'avatar'=>  Selector\Filter::normalizeAvatar($newspaper->select('div[1]/a[1]/img[1]/@src')->extract()),
                'role' => trim($newspaper->select('h3[1]')->extract())
            );
        } else {
            $result['newspaper'] = null;
        }
        
        $citizenContent = $content->select('div[@class="citizen_content"][1]');
        
        $topDamage = $citizenContent->select(
            'h3/img[@title="Top damage is only updated at the end of the campaign"]'.
            '/../following-sibling::div[@class="citizen_military"][1]'
        );
        
        if ($topDamage->hasResults()) {
            $damage = (int)str_replace(',', '', trim(str_replace('for', '', $topDamage->select('h4')->extract())));
            $stat = $topDamage->select('div[@class="stat"]/small')->extract();
            if (preg_match('/Achieved while .*? on day ([0-9,]+)/', $stat, $matches)) {
                $dateTime = DateTime::createFromDay((int)str_replace(',', '', $matches[1]));
                $result['top_damage'] = array(
                    'damage'  => $damage,
                    'date'    => $dateTime->format('Y-m-d'),
                    'message' => trim($stat, "\xC2\xA0\n")
                );
            } else {
                throw new ScrapeException;
            }
        } else {
            $result['top_damage'] = null;
        }
        
        $truePatriot = $citizenContent->select(
            'h3[normalize-space(text())="True Patriot"]/following-sibling::div[@class="citizen_military"][1]'
        );
        
        if ($truePatriot->hasResults()) {
            $damage = (int)str_replace(',', '', trim(str_replace('for', '', $truePatriot->select('h4')->extract())));
            $tip = $truePatriot->select('preceding-sibling::h3[1]/img[1]/@title')->extract();
            if (preg_match('/day ([0-9]+)/', $tip, $since)) {
                $dateTime = DateTime::createFromDay($since[1]);
                $result['true_patriot'] = array(
                    'damage' => $damage,
                    'since'  => $dateTime->format('Y-m-d')
                );
            }
        } else {
            $result['true_patriot'] = null;
        }
        
        $medals = $content->select('//ul[@id="achievment"]/li');
        foreach ($medals as $li) {
            $type = $li->select('div[contains(@class,"hinter")]/span/p/strong');
            if (!$type->hasResults()) {
                continue;
            }
            $type = strtr(strtolower($type->extract()), array(' ' => '_'));
            $count=$li->select('div[@class="counter"]');
            if ($count->hasResults()) {
                $count = (int)$count->extract();
            } else {
                $count = 0;
            }
            $result['medals'][$type] = $count;
        }
        ksort($result['medals']);
        
        return $result;
    }
    
    /**
     * Searches for matching citizen
     * @param  string  $query Citizen name
     * @param  integer $page  Page number
     * @return array          List of matching citizens
     */
    public function search($query, $page = 1)
    {
        $page = Filter::page($page);
        $request = $this->getClient()->get('main/search/');
        $request->getParams()->set('cookies.disable', true);
        $request
            ->getQuery()
            ->set('q', $query)
            ->set('page', $page);
        
        $response = $request->send();
        $xs = Selector\XPath::loadHTML($response->getBody(true));
        
        $paginator = new Selector\Paginator($xs);
        if ($paginator->isOutOfRange($page) && $page > 1) {
            return array();
        }
        
        $list = $xs->select('//table[@class="bestof"]/tr');
        
        if (!$list->hasResults()) {
            throw new ScrapeException;
        }
        $result = array();
        foreach ($list as $tr) {
            if ($tr->select('th[1]')->hasResults()) {
                continue;
            }
            $href = $tr->select('td[2]/div[1]/div[2]/a/@href')->extract();
            $result[] = array(
                'id'   => (int)substr($href, strrpos($href, '/') + 1),
                'name' => $tr->select('td[2]/div[1]/div[2]/a')->extract(),
            );
        }
        return $result;
    }
}
