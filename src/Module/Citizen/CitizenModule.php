<?php
namespace Erpk\Harvester\Module\Citizen;

use Erpk\Harvester\Module\Module;
use GuzzleHttp\Exception\ClientException;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Client\Selector as OldSelector;
use XPathSelector\Exception\NodeNotFoundException;
use XPathSelector\Selector;
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
        $request->disableCookies();

        try {
            $response = $request->send();
            $result = self::parseProfile($response->getBody(true));
            $result['id'] = $id;
            return $result;
        } catch (ClientException $e) {
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
     * @throws ScrapeException
     */
    public static function parseProfile($html)
    {
        $em = EntityManager::getInstance();
        $countries = $em->getRepository('Erpk\Common\Entity\Country');
        $regions = $em->getRepository('Erpk\Common\Entity\Region');
        
        $parseStat = function ($string, $float = false) {
            $string = trim($string);
            $string = substr($string, 0, strpos($string, '/'));
            $string = str_ireplace(',', '', $string);
            return $float ? (float)$string : (int)$string;
        };

        $xs = Selector::loadHTML($html);
        $result = [];

        $content  = $xs->find('//div[@id="content"][1]');
        $sidebar  = $content->find('//div[@class="citizen_sidebar"][1]');
        $second   = $content->find('//div[@class="citizen_second"]');
        $state    = $content->find('//div[@class="citizen_state"]');
        
        /**
         * BASIC DATA
         */
        try {
            $viewFriends = $content->find('//a[@class="view_friends"][1]/@href');
            preg_match('@^/[^/]+/main/citizen-friends/([0-9]+)$@', $viewFriends->extract(), $matches);
            $result['id'] = (float)$matches[1];
        } catch (NodeNotFoundException $e) {
            $result['id'] = null;
        }
        
        $result['name'] = $content->find('//img[@class="citizen_avatar"]/@alt')->extract();

        $birth = new DateTime(trim($second->find('p[2]')->extract()));
        $result['birth'] = $birth->format('Y-m-d');
        
        $avatar = $content->find('//img[@class="citizen_avatar"][1]/@style')->extract();
        $avatar = OldSelector\RegEx::find($avatar, '/background-image\: url\(([^)]+)\);/i');
        $result['avatar'] = $avatar->group(0);
        
        $result['online'] = $content->find('//span[@class="citizen_presence"][1]/img[1]/@alt')->extract() == 'online';
        
        /**
         * BAN/DEAD
         */
        try {
            $ban = $state->find(
                'div/span/img[contains(@src, "perm_banned")]/../..'
            );
            $result['ban'] = [
                'type' => trim($ban->find('span')->extract()),
                'reason' => $ban->find('@title')->extract()
            ];
        } catch (NodeNotFoundException $e) {
            $result['ban'] = null;
        }

        $result['alive'] = $state->findOneOrNull('div/span/img[contains(@src, "dead_citizen")]/../..') != null;
        
        $exp = $content->find('//div[@class="citizen_experience"]');
        $result['level'] = (int)$exp->find('strong[@class="citizen_level"]')->extract();
        $result['division'] = Helpers::getDivision($result['level']);
        $result['experience'] = $parseStat($exp->find('div/p')->extract());
        $result['elite_citizen'] = $content->findOneOrNull('//span[@title="eRepublik Elite Citizen"][1]') !== null;
        $result['national_rank'] = (int)$second->find('small[3]/strong')->extract();

        $military = function($eliteCitizen) use ($content, $parseStat) {
            $arr = [];
            $military = $content->findAll('//div[@class="citizen_military"]');
            $arr['strength'] = (float)str_ireplace(',', '', trim($military->item(0)->find('h4')->extract()));
            $item1 = $military->item(1);
            if (!$item1) {
                throw new ScrapeException;
            }
            $arr['rank'] = new Rank($parseStat($item1->find('div/small[2]/strong')->extract(), true));
            $arr['base_hit'] = Helpers::getHit(
                $arr['strength'],
                $arr['rank']->getLevel(),
                0,
                $eliteCitizen
            );
            return $arr;
        };
        $guerrilla = function () use ($content) {
            $div = $content->findOneOrNull('//div[@class="guerilla_fights_history"][1]');
            if ($div) {
                return [
                    'won' => (int)$div->find('div[@title="Guerrilla matches won"][1]/span[1]')->extract(),
                    'lost' => (int)$div->find('div[@title="Guerrilla matches lost"][1]/span[1]')->extract(),
                ];
            } else {
                return ['won' => null, 'lost' => null];
            }
        };
        $bombs = function () use ($content) {
            $massDestruction = $content->findOneOrNull('//div[@class="citizen_mass_destruction"][1]');
            if ($massDestruction) {
                return [
                    'small_bombs' => (int)$massDestruction->find('strong/img[@title="Small Bombs used"]/../b[1]')->extract(),
                    'big_bombs' => (int)$massDestruction->find('strong/img[@title="Big Bombs used"]/../b[1]')->extract()
                ];
            } else {
                return ['small_bombs' => 0, 'big_bombs' => 0];
            }
        };

        $result['military'] = $military($result['elite_citizen']);
        $result['military']['guerrilla'] = $guerrilla(); // Guerilla statistics
        $result['military']['mass_destruction'] = $bombs(); // Bombs statistics

        // Residence and citizenship
        $info = $sidebar->find('div[1]');
        $result['citizenship'] = $countries->findOneByName((string)$info->find('a[3]/img[1]/@title')->extract());
        $result['residence'] = [
            'country' => $countries->findOneByName($info->find('a[1]/@title')->extract()),
            'region'  => $regions->findOneByName($info->find('a[2]/@title')->extract()),
        ];
        
        if (!isset($result['residence']['country'], $result['residence']['region'], $result['citizenship'])) {
            throw new ScrapeException;
        }

        // About me
        try {
            $about = $content->find('//div[@class="about_message profile_section"]/p');
            $result['about'] = strip_tags($about->extract());
        } catch (NodeNotFoundException $e) {
            $result['about'] = null;
        }

        $places = $content->findAll('//div[@class="citizen_activity"]/div[@class="place"]');
        // Political Party
        $party = $places->item(0);
        $class = $party->findOneOrNull('h3/@class');
        if ($class == null || $class->extract() != 'noactivity') {
            $url = $party->findOneOrNull('div/span/a/@href');
            if ($url == null) {
                $result['party'] = null;
            } else {
                $url = $url->extract();
                $start = strrpos($url, '-')+1;
                $length = strrpos($url, '/')-$start;
                $result['party'] = array(
                    'id'    =>  (int)substr($url, $start, $length),
                    'name'  =>  trim($party->find('div[1]/span/a')->extract()),
                    'avatar'=>  $party->find('div/img/@src')->extract(),
                    'role'  =>  trim($party->find('h3[1]')->extract())
                );
            }
        } else {
            $result['party'] = null;
        }

        // Military Unit
        $unit = $places->item(1);
        if ($unit->findOneOrNull('div[1]')) {
            $url = $unit->find('div[1]/a[1]/@href')->extract();
            $avatar = $unit->find('div[1]/a[1]/img[1]/@src')->extract();
            $createdAt = preg_replace('#.*([0-9]{4})/([0-9]{2})/([0-9]{2}).*#', '\1-\2-\3', $avatar);
            $result['military']['unit'] = [
                'id'         => (int)substr($url, strrpos($url, '/')+1),
                'name'       => $unit->find('div[1]/a[1]/span[1]')->extract(),
                'created_at' => $createdAt,
                'avatar'     => $avatar,
                'role'       => trim($unit->find('h3[1]')->extract())
            ];
        } else {
            $result['military']['unit'] = null;
        }

        // Newspaper
        $newspaper = $places->item(2);
        if ($newspaper->findOneOrNull('div[1]')) {
            $url    = $newspaper->find('div[1]/a[1]/@href')->extract();
            $start  = strrpos($url, '-')+1;
            $length = strrpos($url, '/')-$start;
            
            $result['newspaper'] = [
                'id'        => (int)substr($url, $start, $length),
                'name'      => $newspaper->find('div[1]/a/@title')->extract(),
                'avatar'    => $newspaper->find('div[1]/a[1]/img[1]/@src')->extract(),
                'role'      => trim($newspaper->find('h3[1]')->extract())
            ];
        } else {
            $result['newspaper'] = null;
        }
        
        $citizenContent = $content->find('div[@class="citizen_content"][1]');

        // Top Damage
        $topDamage = $citizenContent->findOneOrNull(
            'h3/img[@title="Top damage is only updated at the end of the campaign"]'.
            '/../following-sibling::div[@class="citizen_military"][1]'
        );
        if ($topDamage) {
            $damage = (float)str_replace(',', '', trim(str_replace('for', '', $topDamage->find('h4')->extract())));
            $stat = $topDamage->find('div[@class="stat"]/small')->extract();
            if (preg_match('/Achieved while .*? on day ([0-9,]+)/', $stat, $matches)) {
                $dateTime = DateTime::createFromDay((int)str_replace(',', '', $matches[1]));
                $result['top_damage'] = [
                    'damage'  => $damage,
                    'date'    => $dateTime->format('Y-m-d'),
                    'message' => trim($stat, "\xC2\xA0\n")
                ];
            } else {
                throw new ScrapeException();
            }
        } else {
            $result['top_damage'] = null;
        }

        // True Patriot
        $truePatriot = $citizenContent->findOneOrNull(
            'h3[normalize-space(text())="True Patriot"]/following-sibling::div[@class="citizen_military"][1]'
        );
        if ($truePatriot) {
            $damage = (float)str_replace(',', '', trim(str_replace('for', '', $truePatriot->find('h4')->extract())));
            $tip = $truePatriot->find('preceding-sibling::h3[1]/img[1]/@title')->extract();
            if (preg_match('/day ([0-9]+)/', $tip, $since)) {
                $dateTime = DateTime::createFromDay($since[1]);
                $result['true_patriot'] = [
                    'damage' => $damage,
                    'since'  => $dateTime->format('Y-m-d')
                ];
            } else {
                throw new ScrapeException();
            }
        } else {
            $result['true_patriot'] = null;
        }

        // Medals
        $medals = $content->findAll('//ul[@id="achievment"]/li');
        foreach ($medals as $li) {
            $type = $li->findOneOrNull('div[contains(@class,"hinter")]/span/p/strong');
            if ($type == null) {
                continue;
            }
            $type = strtr(strtolower($type->extract()), [' ' => '_']);
            $count = $li->findOneOrNull('div[@class="counter"]');
            $result['medals'][$type] = $count ? (int)$count->extract() : 0;
        }
        ksort($result['medals']);
        
        return $result;
    }
    
    /**
     * Searches for matching citizen
     * @param  string  $searchQuery Citizen name
     * @param  integer $page  Page number
     * @return array          List of matching citizens
     * @throws ScrapeException
     */
    public function search($searchQuery, $page = 1)
    {
        $page = Filter::page($page);
        $request = $this->getClient()->get('main/search/');
        $request->disableCookies();

        $query = $request->getQuery();
        $query->set('q', $searchQuery);
        $query->set('page', $page);
        
        $response = $request->send();
        $xs = OldSelector\XPath::loadHTML($response->getBody(true));
        
        $paginator = new OldSelector\Paginator($xs);
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
