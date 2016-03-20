<?php
namespace Erpk\Harvester\Module\Military;

use Erpk\Common\Citizen\Rank;
use Erpk\Common\DateTime;
use Erpk\Common\Entity\Country;
use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use XPathSelector\Node;

class MilitaryModule extends Module
{
    /**
     * Returns list of active campaigns
     * @return ActiveCampaigns
     */
    public function getActiveCampaigns()
    {
        $data = $this->getClient()->get('military/campaigns-new')->send()->json();
        return new ActiveCampaigns($data);
    }

    /**
     * Returns statistics of given campaign
     * @param int $campaignId
     * @return array
     */
    public function getCampaignStats($campaignId)
    {
        return $this->getClient()->get('military/nbp-stats/' . $campaignId . '/1')->send()->json();
    }

    /**
     * Returns information about Military Unit
     * @param int $unitId
     * @throws ScrapeException
     * @return array
     */
    public function getUnit($unitId)
    {
        $xs = $this->getClient()->get('main/group-show/' . $unitId)->send()->xpath();

        $content = $xs->find('//div[@id="content"]');
        $header = $content->find('div[@id="military_group_header"]');

        $countries = $this->getEntityManager()->getRepository(Country::class);
        $country = $header->find('div[@class="header_content"]/div[@class="details"]/a[1]/img/@alt')->extract();
        $country = $countries->findOneByName($country);
        if (!$country) {
            throw new ScrapeException;
        }

        $avatar = $header->find('//img[@id="avatar"]/@src')->extract();
        preg_match('#[0-9]{4}/[0-9]{2}/[0-9]{2}#', $avatar, $created);

        $details = $header->find('div[@class="header_content"]/div[@class="details"]');
        $url = $details->find('a[2]/@href')->extract();

        $regimentsXS = $this->getClient()->get('main/group-list/members/' . $unitId)->send()->xpath();
        $regs = $regimentsXS->findAll('//select[@id="regiments_lists"]/option/@value')->map(function (Node $node) {
            return (int)$node->extract();
        });

        return [
            'id' => $unitId,
            'name' => $header->find('div[@class="header_content"]/h2/span')->extract(),
            'avatar' => $avatar,
            'created_at' => isset($created[0]) ? strtr($created[0], '/', '-') : null,
            'location' => $country,
            'about' => trim($header->find('//p[@id="editable_about"]')->extract()),
            'commander' => [
                'id' => (int)substr($url, strrpos($url, '/') + 1),
                'name' => $header->find('div[@class="header_content"]/div[@class="details"]/a[2]/@title')->extract()
            ],
            'regiments' => $regs
        ];
    }

    /**
     * Returns information about particular regiment
     * @param int $unitId
     * @param int $regimentId
     * @return array
     */
    public function getRegiment($unitId, $regimentId)
    {
        $request = $this->getClient()->get("main/group-list/members/$unitId/$regimentId")->xhr();
        $xs = $request->send()->xpath();

        $countries = $this->getEntityManager()->getRepository(Country::class);
        $xpath = '//table[@regimentid="' . $regimentId . '"][1]/tbody[1]/tr';

        return $xs->findAll($xpath)->map(function (Node $member) use ($countries) {
            $lastFight = $member->findOneOrNull('td[@class="last_fight"]/@sort');
            $fightsYesterday = $member->findOneOrNull('td[@class="strength"]/@sort');

            $avatar = $member->find('td[@class="avatar"]');
            $mrank = $member->find('td[@class="mrank"]');
            $location = $avatar->find('div[@class="current_location"][1]/span[1]/span[1]/@title')->extract();
            $rankPoints = (int)$mrank->find('@sort')->extract();
            return [
                'id' => (int)$member->find('@memberid')->extract(),
                'name' => $avatar->find('@sort')->extract(),
                'status' => $member->find('td[@class="status"][1]/div[1]/strong[1]')->extract(),
                'avatar' => str_replace('_55x55', '', $avatar->find('img[1]/@src')->extract()),
                'location' => $countries->findOneByName($location),
                'rank' => new Rank($rankPoints),
                'last_fight' => $lastFight ? (int)$lastFight->extract() : null,
                'fights_yesterday' => $fightsYesterday ? (int)$fightsYesterday->extract() : null
            ];
        });
    }

    /**
     * @param int $unitId
     * @param DateTime $createdAt
     * @return string
     */
    public static function getUnitAvatar($unitId, DateTime $createdAt)
    {
        return
            'http://erpk.static.avatars.s3.amazonaws.com/avatars/Groups/' .
            $createdAt->format('Y/m/d') . '/' .
            md5($unitId) . '.jpg';
    }

    /**
     * Makes single kill in particular campaign
     * @param int $campaignId
     * @param int $sideCountryId
     * @return array
     */
    public function fight($campaignId, $sideCountryId)
    {
        $request = $this->getClient()->post('military/fight-shooot/' . $campaignId)->csrf()->xhr();
        $request->setRelativeReferer('military/battlefield-new/' . $campaignId);
        $request->addPostFields([
            'battleId' => $campaignId,
            'sideId' => $sideCountryId
        ]);

        return $request->send()->json();
    }

    /**
     * @param int $campaignId
     * @param int $countryId
     */
    public function chooseSide($campaignId, $countryId)
    {
        $this->getClient()->get("military/battlefield-choose-side/$campaignId/$countryId")->send();
    }

    /**
     * @param int $campaignId
     * @return array
     */
    public function showWeapons($campaignId)
    {
        $request = $this->getClient()->get('military/show-weapons')->xhr();
        $query = $request->getQuery();
        $query->add('_token', $this->getSession()->getToken());
        $query->add('battleId', $campaignId);

        return $request->send()->json();
    }

    /**
     * @param int $campaignId
     * @param int $customizationLevel
     * @return array
     */
    public function changeWeapon($campaignId, $customizationLevel)
    {
        $request = $this->getClient()->post('military/change-weapon')->csrf()->xhr();
        $request->addPostFields([
            'battleId' => $campaignId,
            'customizationLevel' => $customizationLevel
        ]);

        return $request->send()->json();
    }

    /**
     * Returns information about daily order completion status
     * @return array
     */
    public function getDailyOrderStatus()
    {
        $request = $this->getClient()->get();
        $response = $request->send();
        $html = $response->getBody(true);
        preg_match('/var mapDailyOrder = (.*);/', $html, $matches);
        preg_match('/groupId\s*:\s*(\d+),/', $html, $groupId);

        $result = json_decode($matches[1], true);
        $result['groupId'] = (int)$groupId[1];
        return $result;
    }

    /**
     * Collects daily order reward if available
     * @param  int $missionId
     * @param  int $unitId
     * @return array
     */
    public function getDailyOrderReward($missionId, $unitId)
    {
        $request = $this->getClient()->post('military/group-missions')->csrf()->xhr();
        $request->setRelativeReferer();
        $request->addPostFields([
            'groupId' => $unitId,
            'missionId' => $missionId,
            'action' => 'check'
        ]);

        return $request->send()->json();
    }
}
