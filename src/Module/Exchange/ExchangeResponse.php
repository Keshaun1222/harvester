<?php
namespace Erpk\Harvester\Module\Exchange;

use Erpk\Harvester\Client\Selector\Paginator;
use XPathSelector\Node;
use XPathSelector\Selector;

class ExchangeResponse extends \ArrayObject
{
    /**
     * @var Paginator
     */
    protected $paginator;

    /**
     * @var float
     */
    protected $goldAmount;

    /**
     * @var float
     */
    protected $currencyAmount;

    /**
     * @var Offer[]
     */
    protected $offers;

    /**
     * ExchangeResponse constructor.
     * @param array $data
     */
    public function __construct(array $data)
    {
        parent::__construct($data);

        $xs = Selector::loadHTML($data['buy_mode']);
        $this->paginator = new Paginator($xs);
        $this->goldAmount = (float)$data['gold']['value'];
        $this->currencyAmount = (float)$data['ecash']['value'];

        $this->offers = $xs->findAll('//*[@class="exchange_offers"]/tr')->map(function (Node $row) {
            $url = $row->find('td[1]/a/@href')->extract();
            $offer = new Offer();
            $offer->id = (int)substr($row->find('td[3]/strong[2]/@id')->extract(), 14);
            $offer->amount = (float)str_replace(',', '', $row->find('td[2]/strong/span')->extract());
            $offer->rate = (float)$row->find('td[3]/strong[2]/span')->extract();
            $offer->sellerId = (int)substr($url, strripos($url, '/') + 1);
            $offer->sellerName = (string)$row->find('td[1]/a/@title')->extract();
            return $offer;
        });
    }

    /**
     * @return Paginator
     */
    public function getPaginator()
    {
        return $this->paginator;
    }

    /**
     * @return float
     */
    public function getGoldAmount()
    {
        return $this->goldAmount;
    }

    /**
     * @return float
     */
    public function getCurrencyAmount()
    {
        return $this->currencyAmount;
    }

    /**
     * @return Offer[]
     */
    public function getOffers()
    {
        return $this->offers;
    }
}
