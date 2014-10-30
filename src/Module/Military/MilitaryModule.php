<?php
namespace Erpk\Harvester\Module\Military;

use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Module\Military\Exception\CampaignNotFoundException;
use Erpk\Harvester\Module\Military\Exception\UnitNotFoundException;
use Erpk\Harvester\Module\Military\Exception\RegimentNotFoundException;
use Erpk\Harvester\Exception\ScrapeException;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Erpk\Harvester\Client\Selector;
use Erpk\Common\Citizen\Rank;
use Erpk\Common\Entity\Campaign;

class MilitaryModule extends Module
{
    const SIDE_ATTACKER = 0;
    const SIDE_DEFENDER = 1;

    /**
     * Returns list of active campaigns
     * @return array List of active campaings
     */
    public function getActiveCampaigns()
    {
        $this->getClient()->checkLogin();
        
        $response = $this->getClient()->get('military/campaigns')->send();
        $hxs = Selector\XPath::loadHTML($response->getBody(true));
        
        $listing = $hxs->select('//div[@id="battle_listing"]');
        $ul = array(
            'all'      => '//ul[@class="all_battles"]',
            'cotd'     => '//ul[@class="bod_listing"]',
            'country'  => '//ul[@class="country_battles"]',
            'allies'   => '//ul[@class="allies_battles"]'
        );
        $result = array();
        
        foreach ($ul as $type => $xpath) {
            $campaigns = $listing->select($xpath.'/li');
            $result[$type] = array();
            if (!$campaigns->hasResults()) {
                continue;
            }
            
            foreach ($campaigns as $li) {
                $id = $li->select('@id')->extract();
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
    
    protected function parseBattleField($html)
    {
        $count = preg_match_all(
            '/var SERVER_DATA\s*=\s*({[^;]*)/i',
            $html,
            $serverDataRaw
        );

        if ($count == 0) {
            throw new ScrapeException;
        }

        $serverDataRaw = $serverDataRaw[1][$count-1];

        if (!preg_match('/battleId\s*:\s*([0-9]+)/i', $serverDataRaw, $id)) {
            throw new ScrapeException;
        } else {
            $id = (int)$id[1];
        }
        
        if (!preg_match('/mustInvert\s*:\s*([a-z]+)/i', $serverDataRaw, $mustInvert)) {
            throw new ScrapeException;
        } else {
            $mustInvert = $mustInvert[1] == 'true';
        }

        if (!preg_match('/countryId\s*:\s*([0-9]+)/i', $serverDataRaw, $countryId)) {
            throw new ScrapeException;
        } else {
            $countryId = (int)$countryId[1];
        }
        
        if (!preg_match('/invaderId\s*:\s*([0-9]+)/i', $serverDataRaw, $invaderId)) {
            throw new ScrapeException;
        } else {
            $invaderId = (int)$invaderId[1];
        }
        
        if (!preg_match('/defenderId\s*:\s*([0-9]+)/i', $serverDataRaw, $defenderId)) {
            throw new ScrapeException;
        } else {
            $defenderId = (int)$defenderId[1];
        }
        
        if (!preg_match('/isResistance\s*:\s*([0-9]+)/i', $serverDataRaw, $isResistance)) {
            throw new ScrapeException;
        } else {
            $isResistance = $isResistance[1] == 1;
        }
        
        $regions = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Region');
        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        
        $hxs = Selector\XPath::loadHTML($html);
        $regionName = $hxs->select('//div[@id="pvp_header"][1]/h2[1]')->extract();
        $region = $regions->findOneByName($regionName);
        
        $campaign = new Campaign;
        $campaign->setId($id);
        $campaign->setAttacker($countries->find($mustInvert ? $defenderId : $invaderId));
        $campaign->setDefender($countries->find($mustInvert ? $invaderId : $defenderId));
        $campaign->setRegion($region);
        $campaign->setResistance($isResistance);
        $campaign->_citizenCountry = $countries->find($countryId);
        
        return $campaign;
    }
    
    /**
     * Returns static information about given campaign
     * @param  int    $id Campaign ID
     * @return array  Array with basic information about battle
     */
    public function getCampaign($id)
    {
        $this->filter($id, 'id');
        
        $this->getClient()->checkLogin();
        $request = $this->getClient()->get('military/battlefield/'.$id);

        try {
            $response = $request->send();
        } catch (ClientErrorResponseException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new CampaignNotFoundException;
            } else {
                throw $e;
            }
        }
        /**
         * Resistance wars FIX
         */
        if ($response->isRedirect() &&
            preg_match('#^'.$this->getClient()->getBaseUrl().'/wars/show/([0-9]+)$#', $response->getLocation())
        ) {
            $war = $this->getClient()->get($response->getLocation())->send();
            preg_match(
                '#'.$this->getClient()->getBaseUrl().'/military/battlefield-choose-side/[0-9]+/[0-9]+#',
                $war->getBody(true),
                $links
            );
            
            $response = $this->getClient()->get($links[0])->send();
            if ($response->isRedirect()) {
                $response = $this->getClient()->get($response->getLocation())->send();
            }
        }
        
        $campaign = $this->parseBattleField($response->getBody(true));

        return $campaign;
    }

    /**
     * Returns "dynamic" statistics about given campaign
     * @param  Campaign $campaign Campaign to find
     * @return array             Statistics on given campaign
     */
    public function getCampaignStats(Campaign $campaign)
    {
        $this->getClient()->checkLogin();

        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        $request = $this->getClient()->get('military/battle-stats/'.$campaign->getId().'/1');
        $stats = $request->send()->json();

        $finished = $stats['division'][$campaign->getAttacker()->getId()]['total'] >= 83 ||
                    $stats['division'][$campaign->getDefender()->getId()]['total'] >= 83;
        
        if (!$finished) {
            $fightersData = $stats['fightersData'];
            $current = array_shift($stats['stats']['current']);
        }
        
        $result = array(
            'attacker' => array(),
            'defender' => array()
        );

        foreach ($result as $side => $info) {
            if ($side === 'attacker') {
                $sideId = $campaign->getAttacker()->getId();
            } else {
                $sideId = $campaign->getDefender()->getId();
            }
            
            $result[$side]['points']    = (int)$stats['division'][$sideId]['total'];
            $result[$side]['divisions'] = array();
            
            for ($n = 1; $n <= 4; $n++) {
                $tf = array();
                if (isset($current[$n][$sideId])) {
                    foreach ($current[$n][$sideId] as $fighter) {
                        $id = (int)$fighter['citizen_id'];
                        $data = $fightersData[$id];
                        $country = $countries->find($data['residence_country_id']);
                        if (!$country) {
                            throw new ScrapeException;
                        }
                        
                        $tf[] = array(
                            'id'        => $id,
                            'name'      => $data['name'],
                            'avatar'    => Selector\Filter::normalizeAvatar($data['avatar']),
                            'birth'     => substr($data['created_at'], 0, 10),
                            'country'   => $country,
                            'damage'    => (int)$fighter['damage'],
                            'kills'     => (int)$fighter['kills']
                        );
                    }
                }
                
                $bar = $stats['division']['domination'][$n];
                if ($side == 'attacker') {
                    $bar = 100-$bar;
                }
                
                $result[$side]['divisions'][(int)$n] = array(
                    'points'       => $stats['division'][$sideId][$n]['points'],
                    'bar'          => $bar,
                    'domination'   => (int)$stats['division'][$sideId][$n]['domination'],
                    'won'          => $stats['division'][$sideId][$n]['won']==1,
                    'top_fighters' => $tf
                );
            }
        }
        
        $result['is_finished'] = $finished;
        return $result;
    }
    
    /**
     * Returns information about Military Unit
     * @param  int $id Military Unit ID
     * @return array     Military Unit's information
     */
    public function getUnit($id)
    {
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
        
        $hxs = Selector\XPath::loadHTML($response->getBody(true));
        $content = $hxs->select('//div[@id="content"]');
        if (!$content->hasResults()) {
            throw new ScrapeException;
        }
        
        $header = $content->select('div[@id="military_group_header"]');
        if (!$header->hasResults()) {
            throw new ScrapeException;
        }
        
        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        $country = $header->select('div[@class="header_content"]/div[@class="details"]/a[1]/img/@alt')->extract();
        $country = $countries->findOneByName($country);
        if (!$country) {
            throw new ScrapeException;
        }
        
        $avatar = $header->select('//img[@id="avatar"]/@src')->extract();
        preg_match('#[0-9]{4}/[0-9]{2}/[0-9]{2}#', $avatar, $created);
        
        $details = $header->select('div[@class="header_content"]/div[@class="details"]');
        $url = $details->select('a[2]/@href')->extract();
        
        $regs = array();
        $regiments = $content->select('//select[@id="regiments_lists"]/option/@value');
        foreach ($regiments as $regiment) {
            $regs[] = (int)$regiment->extract();
        }
        $regs = array_unique($regs);
        
        $result = array(
            'id'         => $id,
            'name'       => $header->select('div[@class="header_content"]/h2/span')->extract(),
            'avatar'     => $avatar,
            'created_at' => isset($created[0]) ? strtr($created[0], '/', '-') : null,
            'location'   => $country,
            'about'      => trim($header->select('//*[@id="editable_about"]')->extract()),
            'commander'  =>  array(
                'id'         =>  (int)substr($url, strrpos($url, '/')+1),
                'name'       =>  $header->select(
                    'div[@class="header_content"]/div[@class="details"]/a[2]/@title'
                )->extract()
            ),
            'regiments'  => $regs
        );
        
        return $result;
    }
    
    public static function getUnitAvatar($unitId, DateTime $createdAt, $size = 'medium')
    {
        return
            'http://static.erepublik.com/uploads/avatars/Groups/'.
            $createdAt->format('Y/m/d').'/'.
            md5($unitId).'_'.$size.'.jpg';
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
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        
        try {
            $response = $request->send();
        } catch (ClientErrorResponseException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new RegimentNotFoundException('Regiment '.$regimentId.' not found.');
            } else {
                throw $e;
            }
        }
        
        $result = array();
        
        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        
        $hxs = Selector\XPath::loadHTML($response->getBody(true));
        
        if ($hxs->select('//table[@class="info_message"][1]/tr[1]/td[1]')->hasResults()) {
            return array();
        }
        
        $members = $hxs->select('//table[@regimentid="'.$regimentId.'"][1]/tbody[1]/tr');
        
        if (!$members->hasResults()) {
            return array();
        } else {
            foreach ($members as $member) {
                $avatar = $member->select('td[@class="avatar"]');
                $mrank  = $member->select('td[@class="mrank"]');
                try {
                    $location = $avatar->select('div[@class="current_location"][1]/span[1]/span[1]/@title')->extract();
                } catch (NotFoundException $e) {
                    throw new ScrapeException;
                }
                $rankPoints = (int)$mrank->select('@sort')->extract();
                $result[] = array(
                    'id'        =>  (int)$member->select('@memberid')->extract(),
                    'name'      =>  $avatar->select('@sort')->extract(),
                    'status'    =>  $member->select('td[@class="status"][1]/div[1]/strong[1]')->extract(),
                    'avatar'    =>  str_replace('_55x55', '', $avatar->select('img[1]/@src')->extract()),
                    'location'  =>  $countries->findOneByName($location),
                    'rank'      =>  new Rank($rankPoints)
                );
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
     *                               null when to choose automatically
     * @return array             Result information about effect
     */
    public function fight(Campaign $campaign, $side = null)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->post('military/fight-shooot/'.$campaign->getId());
        $request->getHeaders()
            ->set('X-Requested-With', 'XMLHttpRequest')
            ->set('Referer', $this->getClient()->getBaseUrl().'/military/battlefield/'.$campaign->getId());

        if ($side === self::SIDE_ATTACKER) {
            $country = $campaign->getAttacker();
        } else if ($side === self::SIDE_DEFENDER) {
            $country = $campaign->getDefender();
        } else {
            $country = $campaign->_citizenCountry;
        }

        $request->addPostFields(
            array(
                '_token'   => $this->getSession()->getToken(),
                'battleId' => $campaign->getId(),
                'sideId'   => $country->getId()
            )
        );

        $response = $request->send()->json();
        return $response;
    }

    /**
     * Changes weapon in specified to desired quality
     * @param  int     $campaignId    ID of campaign
     * @param  int     $weaponQuality Desired weapon quality (10 stands for bazooka)
     * @return bool    TRUE if successfuly changed weapon, FALSE if weapon not found
     */
    public function changeWeapon($campaignId, $weaponQuality = 7)
    {
        $this->getClient()->checkLogin();

        $n = 0;
        do {
            $n++;
            $request = $this->getClient()->post('military/change-weapon');
            $request->getHeaders()
                ->set('X-Requested-With', 'XMLHttpRequest')
                ->set('Referer', $this->getClient()->getBaseUrl().'/military/battlefield/'.$campaignId);
            $request->addPostFields(
                array(
                    '_token'   => $this->getSession()->getToken(),
                    'battleId' => $campaignId
                )
            );
            $data = $request->send()->json();
        } while (isset($data['countWeapons'])
              && isset($data['weaponId'])
              && $n < $data['countWeapons']
              && $data['weaponId'] != $weaponQuality
        );

        return isset($data['weaponId']) && $data['weaponId'] == $weaponQuality;
    }

    /**
     * Returns information about Daily Order completion status
     * @return array Information about Daily Order completion status
     */
    public function getDailyOrderStatus()
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->get();
        $html = $request->send()->getBody(true);

        $hxs = Selector\XPath::loadHTML($html);
        $groupId = (int)$hxs->select('//input[@type="hidden"][@id="groupId"]/@value')->extract();

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
        $request->getHeaders()
            ->set('X-Requested-With', 'XMLHttpRequest')
            ->set('Referer', $this->getClient()->getBaseUrl());
        $request->addPostFields(
            array(
                '_token'    => $this->getSession()->getToken(),
                'groupId'   => $unitId,
                'missionId' => $missionId,
                'action'    => 'check'
            )
        );
        return $request->send()->json();
    }
}
