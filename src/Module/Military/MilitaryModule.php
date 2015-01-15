<?php
namespace Erpk\Harvester\Module\Military;

use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Module\Military\Exception\CampaignNotFoundException;
use Erpk\Harvester\Module\Military\Exception\UnitNotFoundException;
use Erpk\Harvester\Module\Military\Exception\RegimentNotFoundException;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Client\Selector;
use Erpk\Common\Citizen\Rank;
use Erpk\Common\Entity\Campaign;
use Erpk\Common\Entity\Country;
use Erpk\Common\DateTime;
use GuzzleHttp\Exception\ClientException;
use XPathSelector\Node;

class MilitaryModule extends Module
{
    const SIDE_AUTO = 0;
    const SIDE_ATTACKER = 1;
    const SIDE_DEFENDER = 2;

    /**
     * Returns list of active campaigns
     * @return array List of active campaings
     */
    public function getActiveCampaigns()
    {
        $this->getClient()->checkLogin();
        
        $response = $this->getClient()->get('military/campaigns')->send();
        $hxs = $response->xpath();
        
        $listing = $hxs->find('//div[@id="battle_listing"]');
        $ul = [
            'all'      => '//ul[@class="all_battles"]',
            'cotd'     => '//ul[@class="bod_listing"]',
            'country'  => '//ul[@class="country_battles"]',
            'allies'   => '//ul[@class="allies_battles"]'
        ];
        $result = [];
        
        foreach ($ul as $type => $xpath) {
            $campaigns = $listing->findAll($xpath.'/li');
            $result[$type] = [];
            if ($campaigns->count() == 0) {
                continue;
            }
            
            foreach ($campaigns as $li) {
                $id = $li->find('@id')->extract();
                $id = (int)substr($id, strpos($id, '-')+1);
                $result[$type][] = $id;
                $result['all'][] = $id;
            }
            sort($result[$type]);
        }
        $result['all'] = array_unique($result['all']);
        sort($result['all']);
        return $result;
    }
    
    protected function parseBattleField(Node $xs, $id)
    {
        $count = preg_match_all(
            '/var SERVER_DATA\s*=\s*({[^;]*)/i',
            $xs->outerHTML(),
            $serverDataRaw
        );

        if ($count == 0) {
            throw new ScrapeException;
        }

        $serverDataRaw = $serverDataRaw[1][$count-1];

        $match = function ($key, $val) use ($serverDataRaw) {
            if (!preg_match('/'.$key.'\s*:\s*('.$val.')/i', $serverDataRaw, $match)) {
                throw new ScrapeException;
            } else {
                return $match;
            }
        };

        $mustInvert = $match('mustInvert', '[a-z]+')[1] == 'true';
        $countryId = (int)($match('countryId', '\d+')[1]);
        $invaderId = (int)($match('invaderId', '\d+')[1]);
        $defenderId = (int)($match('defenderId', '\d+')[1]);
        $isResistance = $match('isResistance', '\d+')[1] == 1;

        $regions = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Region');
        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        
        $campaign = new Campaign();
        $campaign->setId($id);
        $campaign->setAttacker($countries->find($mustInvert ? $defenderId : $invaderId));
        $campaign->setDefender($countries->find($mustInvert ? $invaderId : $defenderId));

        $regionName = $xs->find('//a[@id="region_name_link"][1]')->extract();
        $region = $regions->findOneByName($regionName);

        $campaign->setRegion($region);
        $campaign->setResistance($isResistance);
        $campaign->_citizenCountry = $countries->find($countryId);
        
        return $campaign;
    }
    
    /**
     * Returns static information about given campaign
     * @param  int $id Campaign ID
     * @return Campaign An entity with basic information about the campaign
     */
    public function getCampaign($id)
    {
        $this->filter($id, 'id');
        
        $this->getClient()->checkLogin();
        $request = $this->getClient()->get('military/battlefield-new/'.$id);

        try {
            $response = $request->send();
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new CampaignNotFoundException();
            } else {
                throw $e;
            }
        }

        /**
         * Resistance wars detection
         */
        if ($response->isRedirect() &&
            preg_match('#/wars/show/([0-9]+)$#', $response->getLocation())
        ) {
            $war = $this->getClient()->get($response->getLocation())->send();
            preg_match(
                '#military/battlefield-choose-side/\d+/\d+#',
                $war->getBody(true),
                $links
            );
            
            $response = $this->getClient()->get($links[0])->send();
            if ($response->isRedirect()) {
                $response = $this->getClient()->get($response->getLocation())->send();
            }
        }

        return $this->parseBattleField($response->xpath(), $id);
    }

    /**
     * Returns statistics of given campaign
     * @param  Campaign $campaign Campaign to get statistics of
     * @throws ScrapeException
     * @return array Statistics on given campaign
     */
    public function getCampaignStats(Campaign $campaign)
    {
        $this->getClient()->checkLogin();

        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        $request = $this->getClient()->get('military/battle-stats/'.$campaign->getId().'/1');
        $stats = $request->send()->json();

        $finished = $stats['division'][$campaign->getAttacker()->getId()]['total'] >= 94 ||
                    $stats['division'][$campaign->getDefender()->getId()]['total'] >= 94;
        
        if (!$finished) {
            $fightersData = $stats['fightersData'];
            $current = array_shift($stats['stats']['current']);
        }
        
        $result = [
            'attacker' => [],
            'defender' => []
        ];

        foreach ($result as $side => $info) {
            if ($side === 'attacker') {
                $sideId = $campaign->getAttacker()->getId();
            } else {
                $sideId = $campaign->getDefender()->getId();
            }
            
            $result[$side]['points']    = (int)$stats['division'][$sideId]['total'];
            $result[$side]['divisions'] = [];
            
            for ($n = 1; $n <= 4; $n++) {
                $tf = [];
                if (isset($current[$n][$sideId])) {
                    foreach ($current[$n][$sideId] as $fighter) {
                        $id = (int)$fighter['citizen_id'];
                        $data = $fightersData[$id];
                        $country = $countries->find($data['residence_country_id']);
                        if (!$country) {
                            throw new ScrapeException;
                        }
                        
                        $tf[] = [
                            'id'        => $id,
                            'name'      => $data['name'],
                            'avatar'    => Selector\Filter::normalizeAvatar($data['avatar']),
                            'birth'     => substr($data['created_at'], 0, 10),
                            'country'   => $country,
                            'damage'    => (int)$fighter['damage'],
                            'kills'     => (int)$fighter['kills']
                        ];
                    }
                }
                
                $bar = $stats['division']['domination'][$n];
                if ($side == 'attacker') {
                    $bar = 100-$bar;
                }
                
                $result[$side]['divisions'][(int)$n] = [
                    'points'       => $stats['division'][$sideId][$n]['points'],
                    'bar'          => $bar,
                    'domination'   => (int)$stats['division'][$sideId][$n]['domination'],
                    'won'          => $stats['division'][$sideId][$n]['won']==1,
                    'top_fighters' => $tf
                ];
            }
        }
        
        $result['is_finished'] = $finished;
        return $result;
    }
    
    /**
     * Returns information about Military Unit
     * @param  int $id Military Unit ID
     * @return array Military Unit's information
     */
    public function getUnit($id)
    {
        $this->getClient()->checkLogin();
        $this->filter($id, 'id');
        $request = $this->getClient()->get('main/group-list/members/'.$id);
        
        try {
            $response = $request->send();
        } catch (ClientErrorResponseException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new UnitNotFoundException('Military Unit '.$id.' not found.');
            } else {
                throw $e;
            }
        }
        
        $hxs = $response->xpath();
        $content = $hxs->find('//div[@id="content"]');
        $header = $content->find('div[@id="military_group_header"]');
        
        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        $country = $header->find('div[@class="header_content"]/div[@class="details"]/a[1]/img/@alt')->extract();
        $country = $countries->findOneByName($country);
        if (!$country) {
            throw new ScrapeException;
        }
        
        $avatar = $header->find('//img[@id="avatar"]/@src')->extract();
        preg_match('#[0-9]{4}/[0-9]{2}/[0-9]{2}#', $avatar, $created);
        
        $details = $header->find('div[@class="header_content"]/div[@class="details"]');
        $url = $details->find('a[2]/@href')->extract();
        
        $regs = [];
        $regiments = $content->findAll('//select[@id="regiments_lists"]/option/@value');
        foreach ($regiments as $regiment) {
            $regs[] = (int)$regiment->extract();
        }
        $regs = array_unique($regs);
        
        $result = [
            'id'         => $id,
            'name'       => $header->find('div[@class="header_content"]/h2/span')->extract(),
            'avatar'     => $avatar,
            'created_at' => isset($created[0]) ? strtr($created[0], '/', '-') : null,
            'location'   => $country,
            'about'      => trim($header->find('//*[@id="editable_about"]')->extract()),
            'commander'  =>  [
                'id'         =>  (int)substr($url, strrpos($url, '/')+1),
                'name'       =>  $header->find(
                    'div[@class="header_content"]/div[@class="details"]/a[2]/@title'
                )->extract()
            ],
            'regiments'  => $regs
        ];
        
        return $result;
    }
    
    public static function getUnitAvatar($unitId, DateTime $createdAt, $size = null)
    {
        return
            'http://erpk.static.avatars.s3.amazonaws.com/avatars/Groups/'.
            $createdAt->format('Y/m/d').'/'.
            md5($unitId).'.jpg';
    }
    
    /**
     * Returns information about particular regiment
     * @param  int    $unitId      ID of Military Unit
     * @param  int    $regimentId  Absolute ID of regiment
     * @return array               Information about regiment
     */
    public function getRegiment($unitId, $regimentId)
    {
        $this->filter($unitId, 'id');
        $this->filter($regimentId, 'id');
        $this->getClient()->checkLogin();

        $request = $this->getClient()->get('main/group-list/members/'.$unitId.'/'.$regimentId);
        $request->markXHR();
        
        try {
            $response = $request->send();
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new RegimentNotFoundException('Regiment '.$regimentId.' not found.');
            } else {
                throw $e;
            }
        }
        
        $result = [];
        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        
        $hxs = $response->xpath();
        
        if ($hxs->findAll('//table[@class="info_message"][1]/tr[1]/td[1]')->count() > 0) {
            return [];
        }
        
        $members = $hxs->findAll('//table[@regimentid="'.$regimentId.'"][1]/tbody[1]/tr');
        
        if ($members->count() == 0) {
            return [];
        } else {
            foreach ($members as $member) {
                $avatar = $member->find('td[@class="avatar"]');
                $mrank  = $member->find('td[@class="mrank"]');
                $location = $avatar->find('div[@class="current_location"][1]/span[1]/span[1]/@title')->extract();
                $rankPoints = (int)$mrank->find('@sort')->extract();
                $result[] = [
                    'id'        =>  (int)$member->find('@memberid')->extract(),
                    'name'      =>  $avatar->find('@sort')->extract(),
                    'status'    =>  $member->find('td[@class="status"][1]/div[1]/strong[1]')->extract(),
                    'avatar'    =>  str_replace('_55x55', '', $avatar->find('img[1]/@src')->extract()),
                    'location'  =>  $countries->findOneByName($location),
                    'rank'      =>  new Rank($rankPoints)
                ];
            }
        }
        return $result;
    }

    /**
     * Makes single kill in particular campaign
     * @param  Campaign  $campaign  Campaign entity
     * @param  int  $side        One of the constants:
     *                               MilitaryModule::SIDE_ATTACKER or
     *                               MilitaryModule::SIDE_DEFENDER or
     *                               other value to choose automatically
     * @return array             Result information about effect
     */
    public function fight(Campaign $campaign, $side = MilitaryModule::SIDE_AUTO)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->post('military/fight-shooot/'.$campaign->getId());
        $request->markXHR();
        $request->setRelativeReferer('military/battlefield/'.$campaign->getId());

        switch ($side) {
            case self::SIDE_ATTACKER:
                $sideCountry = $campaign->getAttacker();
                break;
            case self::SIDE_DEFENDER:
                $sideCountry = $campaign->getDefender();
                break;
            default:
                $sideCountry = $campaign->_citizenCountry;
                break;
        }

        if ($sideCountry->getId() != $campaign->_citizenCountry->getId()) {
            $this->chooseSide($campaign, $sideCountry);
            $campaign->_citizenCountry = $sideCountry;
        }

        $request->addPostFields([
            '_token'   => $this->getSession()->getToken(),
            'battleId' => $campaign->getId(),
            'sideId'   => $sideCountry->getId()
        ]);

        return $request->send()->json();
    }

    /**
     * Changes the side country in resistance war
     * @param  Campaign $campaign
     * @param  Country  $country
     */
    protected function chooseSide(Campaign $campaign, Country $country)
    {
        if ($campaign->isResistance()) {
            $this->getClient()->get(
                'military/battlefield-choose-side/'.$campaign->getId().'/'.$country->getId()
            )->send();
        } else {
            throw new \Exception('Cannot choose side in ordinary campaign (without changing location).');
        }
    }

    /**
     * Returns list of weapons available
     * @param  Campaign $campaign
     * @return array
     */
    public function showWeapons(Campaign $campaign)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->get('military/show-weapons');
        $request->markXHR();
        $query = $request->getQuery();
        $query->add('_token', $this->getSession()->getToken());
        $query->add('battleId', $campaign->getId());

        return $request->send()->json();
    }

    /**
     * Changes weapon in specified to desired quality
     * @param  Campaign  $campaign    ID of campaign
     * @param  int       $customizationLevel Desired weapon quality (10 stands for bazooka)
     * @return bool      TRUE if successfuly changed weapon, FALSE if weapon not found
     */
    public function changeWeapon(Campaign $campaign, $customizationLevel = 7)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->post('military/change-weapon');
        $request->markXHR();
        $request->addPostFields([
            '_token'   => $this->getSession()->getToken(),
            'battleId' => $campaign->getId(),
            'customizationLevel' => $customizationLevel
        ]);

        return $request->send()->json();
    }

    /**
     * Returns information about Daily Order completion status
     * @return array Information about Daily Order completion status
     */
    public function getDailyOrderStatus()
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->get();
        $response = $request->send();
        $html = $response->getBody(true);

        $hxs = $response->xpath();
        $groupId = (int)$hxs->find('//input[@type="hidden"][@id="groupId"]/@value')->extract();

        preg_match('/var mapDailyOrder = (.*);/', $html, $matches);

        $result = json_decode($matches[1], true);
        $result['groupId'] = $groupId;
        return $result;
    }

    /**
     * Gets Daily Order reward if completed
     * @param  int    $missionId  Mission ID (can be obtained via getDailyOrderStatus() method)
     * @param  int    $unitId     Military Unit ID
     * @return array              Result information
     */
    public function getDailyOrderReward($missionId, $unitId)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->post('military/group-missions');
        $request->markXHR();
        $request->setRelativeReferer();
        $request->addPostFields([
            '_token'    => $this->getSession()->getToken(),
            'groupId'   => $unitId,
            'missionId' => $missionId,
            'action'    => 'check'
        ]);

        return $request->send()->json();
    }
}
