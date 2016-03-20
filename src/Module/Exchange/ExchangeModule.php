<?php
namespace Erpk\Harvester\Module\Exchange;

use Erpk\Harvester\Client\Request;
use Erpk\Harvester\Module\Module;

class ExchangeModule extends Module
{
    const CURRENCY = 1;
    const GOLD = 62;

    /**
     * @param string $action
     * @param array $post
     * @return Request
     */
    private function createRequest($action, array $post)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->post("economy/exchange/$action/")->csrf();
        $request->markXHR();
        $request->setRelativeReferer('economy/exchange-market/');
        $request->addPostFields($post);
        return $request;
    }

    /**
     * @param int $currencyId
     * @param int $page
     * @return ExchangeResponse
     */
    public function scan($currencyId, $page = 1)
    {
        $data = $this->createRequest('retrieve', [
            'currencyId' => $currencyId,
            'page' => $page - 1,
            'personalOffers' => 0,
        ])->send()->json();

        return new ExchangeResponse($data);
    }

    /**
     * @param int|Offer $offerId
     * @param float $amount
     * @return ExchangeResponse
     */
    public function buy($offerId, $amount)
    {
        if ($offerId instanceof Offer) {
            $offerId = $offerId->id;
        }

        $data = $this->createRequest('purchase', [
            'offerId' => $offerId,
            'amount' => $amount,
            'page' => 0,
        ])->send()->json();

        return new ExchangeResponse($data);
    }
}
