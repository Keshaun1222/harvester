<?php
namespace Erpk\Harvester\Module\Market;

use Erpk\Common\Entity;
use Erpk\Harvester\Client\Request;
use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Module\Module;

class MarketModule extends Module
{
    /**
     * @param array $post
     * @return Request
     */
    private function createRequest(array $post)
    {
        $url = 'economy/marketplace';
        $request = $this->getClient()->post($url)->csrf();
        $request->setRelativeReferer($url);
        $request->addPostFields($post);
        return $request;
    }

    /**
     * @param int $countryId
     * @param int $industryId
     * @param int $quality
     * @param int $page
     * @param string $orderBy
     * @return array
     */
    public function scan($countryId, $industryId, $quality, $page = 1, $orderBy = 'price_asc')
    {
        return $this->createRequest([
            'countryId' => $countryId,
            'industryId' => $industryId,
            'quality' => $quality,
            'orderBy' => $orderBy,
            'currentPage' => $page,
            'ajaxMarket' => '1'
        ])->send()->json();
    }

    /**
     * @param int $offerId
     * @param int $amount
     * @param int $page
     * @param string $orderBy
     * @return array
     */
    public function buy($offerId, $amount, $page = 1, $orderBy = 'price_asc')
    {
        return $this->createRequest([
            'offerId' => $offerId,
            'amount' => $amount,
            'orderBy' => $orderBy,
            'currentPage' => $page,
            'buyAction' => '1'
        ])->send()->json();
    }
}
