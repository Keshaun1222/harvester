<?php
namespace Erpk\Harvester\Module\Military;

use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use XPathSelector\Node;

class DailyOrderModule extends Module
{
    /**
     * @return int
     * @throws ScrapeException
     */
    public function getChangesLeft()
    {
        $request = $this->getClient()->get('main/group-home/military');
        $request->followRedirects();
        $html = $request->send()->getBody();

        $matches = [];
        if (!preg_match('/\( (\d+) changes left for today \)/', $html, $matches)) {
            throw new ScrapeException();
        }
        return (int)$matches[1];
    }

    /**
     * @param $campaignId
     * @param int|null $choosenSideCountryId
     * @return array
     */
    public function setDailyOrder($campaignId, $choosenSideCountryId = null)
    {
        $unitId = $this->getMilitaryUnitId();
        $request = $this->getClient()->post('military/group-missions')->csrf()->xhr();
        $request->setRelativeReferer("main/group-show/$unitId?page=1");
        $request->addPostFields([
            'groupId' => $unitId,
            'action' => 'set',
            'typeId' => 1,
            'referenceId' => $campaignId
        ]);

        if ($choosenSideCountryId !== null) {
            $request->addPostFields(['sideId' => $choosenSideCountryId]);
        }

        return $request->send()->json();
    }

    /**
     * @return int[]
     */
    public function getAvailableCampaigns()
    {
        $request = $this->getClient()->get('main/group-home/military');
        $request->followRedirects();
        $xs = $request->send()->xpath();

        $xpath = '//div[@class="mission pusher"]/div[@class="sublist"]/a';
        return $xs->findAll($xpath)->map(function (Node $node) {
            return (int)$node->find('@reference_id')->extract();
        });
    }

    /**
     * @return int
     * @throws ScrapeException
     */
    protected function getMilitaryUnitId()
    {
        $response = $this->getClient()->get('main/group-home/military')->send();

        $matches = [];
        if (!preg_match('/group-show\/(\d+)/', $response->getLocation(), $matches)) {
            throw new ScrapeException('Military Unit ID cannot be found.');
        }

        return (int)$matches[1];
    }
}
