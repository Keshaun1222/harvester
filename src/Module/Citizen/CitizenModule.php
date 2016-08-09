<?php
namespace Erpk\Harvester\Module\Citizen;

use Erpk\Common\Citizen\Helpers;
use Erpk\Common\Citizen\Rank;
use Erpk\Common\Citizen\AirRank;
use Erpk\Common\DateTime;
use Erpk\Common\Entity\Country;
use Erpk\Common\Entity\Region;
use Erpk\Common\EntityManager;
use Erpk\Harvester\Client\Selector as OldSelector;
use Erpk\Harvester\Exception\NotFoundException;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use GuzzleHttp\Exception\ClientException;
use XPathSelector\Exception\NodeNotFoundException;
use XPathSelector\Node;
use XPathSelector\Selector;

class CitizenModule extends Module
{
    /**
     * Returns information on given citizen
     * @param int $citizenId
     * @return array
     * @throws NotFoundException
     */
    public function getProfile($citizenId)
    {
        $request = $this->getClient()->get('citizen/profile/' . $citizenId);
        $request->disableCookies();

        try {
            $response = $request->send();
            $result = self::parseProfile($response->getBody(true));
            $result['id'] = $citizenId;
            return $result;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new NotFoundException("Citizen ID:$citizenId does not exist.");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Parses citizen profile HTML page and returns useful information
     * @param string $html HTML source of citizen profile page
     * @return array
     * @throws ScrapeException
     */
    public static function parseProfile($html)
    {
        $em = EntityManager::getInstance();
        $countries = $em->getRepository(Country::class);
        $regions = $em->getRepository(Region::class);

        $parseStat = function ($string, $float = false) {
            $string = trim($string);
            $string = substr($string, 0, strpos($string, '/'));
            $string = str_ireplace(',', '', $string);
            return $float ? (float)$string : (int)$string;
        };

        $xs = Selector::loadHTML($html);
        $result = [];

        $content = $xs->find('//div[@id="content"][1]');
        $sidebar = $content->find('//div[@class="citizen_sidebar"][1]');
        $second = $content->find('//div[@class="citizen_second"]');
        $state = $content->find('//div[@class="citizen_state"]');

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

        $result['online'] = $content->findOneOrNull('//span[@class="online_status on"][1]') != null;

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

        $result['alive'] = $state->findOneOrNull('div/span/img[contains(@src, "dead_citizen")]/../..') == null;

        $exp = $content->find('//strong[@class="citizen_level"][1]');
        $result['level'] = (int)trim($exp->extract());
        $result['experience'] = $parseStat(
            str_replace(
                '<strong>Experience Level</strong><br />',
                '',
                $exp->find('@title')->extract()
            )
        );
        $result['division'] = Helpers::getDivision($result['level']);
        $result['elite_citizen'] = $content->findOneOrNull('//span[@title="eRepublik Elite Citizen"][1]') !== null;
        $result['national_rank'] = (int)$second->find('small[3]/strong')->extract();

        $military = function ($eliteCitizen) use ($content, $parseStat) {
            $arr = [];
            $str = $content->find('//div[@class="citizen_military_box"][2]/span[2]')->extract();
            $perc = $content->find('//div[@class="citizen_military_box"][4]/span[2]')->extract();
            $arr['strength'] = (float)str_ireplace(',', '', trim($str));
            $arr['rank'] = new Rank($parseStat($content->find('//span[@class="rank_numbers"]')->extract(), true));
            $arr['base_hit'] = Helpers::getHit(
                $arr['strength'],
                $arr['rank']->getLevel(),
                0,
                $eliteCitizen
            );
            $arr['perception'] = (float)str_ireplace(',', '', trim($perc));
            $arr['air_rank'] = new AirRank($parseStat($content->find('//div[@class="citizen_military_box_wide"][2]/span[2]/span[@class="rank_numbers"]')->extract(), true));
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
            'region' => $regions->findOneByName($info->find('a[2]/@title')->extract()),
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
                $start = strrpos($url, '-') + 1;
                $length = strrpos($url, '/') - $start;
                $result['party'] = array(
                    'id' => (int)substr($url, $start, $length),
                    'name' => trim($party->find('div[1]/span/a')->extract()),
                    'avatar' => $party->find('div/img/@src')->extract(),
                    'role' => trim($party->find('h3[1]')->extract())
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
                'id' => (int)substr($url, strrpos($url, '/') + 1),
                'name' => $unit->find('div[1]/a[1]/span[1]')->extract(),
                'created_at' => $createdAt,
                'avatar' => $avatar,
                'role' => trim($unit->find('h3[1]')->extract())
            ];
        } else {
            $result['military']['unit'] = null;
        }

        // Newspaper
        $newspaper = $places->item(2);
        if ($newspaper->findOneOrNull('div[1]')) {
            $url = $newspaper->find('div[1]/a[1]/@href')->extract();
            $start = strrpos($url, '-') + 1;
            $length = strrpos($url, '/') - $start;

            $result['newspaper'] = [
                'id' => (int)substr($url, $start, $length),
                'name' => $newspaper->find('div[1]/a/@title')->extract(),
                'avatar' => $newspaper->find('div[1]/a[1]/img[1]/@src')->extract(),
                'role' => trim($newspaper->find('h3[1]')->extract())
            ];
        } else {
            $result['newspaper'] = null;
        }

        $citizenContent = $content->find('div[@class="citizen_content"][1]');

        // Top Damage
        $topDamage = $citizenContent->findOneOrNull(
            'h3/img[@title="Top damage is only updated at the end of the campaign"]' .
            '/../following-sibling::div[@class="citizen_military"][1]'
        );
        if ($topDamage) {
            $damage = (float)str_replace(',', '', trim(str_replace('for', '', $topDamage->find('h4')->extract())));
            $stat = $topDamage->find('div[@class="stat"]/small')->extract();
            if (preg_match('/Achieved while .*? on day ([0-9,]+)/', $stat, $matches)) {
                $dateTime = DateTime::createFromDay((int)str_replace(',', '', $matches[1]));
                $result['top_damage'] = [
                    'damage' => $damage,
                    'date' => $dateTime->format('Y-m-d'),
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
                    'since' => $dateTime->format('Y-m-d')
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
            /**
             * @var Node $li
             */
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
     * Searches for matching citizens
     * @param string $searchQuery
     * @param int $page
     * @return array
     */
    public function search($searchQuery, $page = 1)
    {
        $request = $this->getClient()->get('main/search/');
        $request->disableCookies();

        $query = $request->getQuery();
        $query->set('q', $searchQuery);
        $query->set('page', $page);

        $xs = $request->send()->xpath();

        $result = [];
        $paginator = new OldSelector\Paginator($xs);
        if ($paginator->isOutOfRange($page) && $page > 1) {
            return $result;
        }

        $rows = $xs->find('//table[@class="bestof"]')->findAll('tr[position()>1]');
        return $rows->map(function (Node $tr) {
            $href = $tr->find('td[2]/div[1]/div[2]/a/@href')->extract();
            return [
                'id' => (int)substr($href, strrpos($href, '/') + 1),
                'name' => $tr->find('td[2]/div[1]/div[2]/a')->extract(),
            ];
        });
    }
}
