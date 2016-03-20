<?php
namespace Erpk\Harvester\Module\JobMarket;

use Erpk\Common\Entity;
use Erpk\Harvester\Module\Module;
use XPathSelector\Node;
use XPathSelector\Selector;

class JobMarketModule extends Module
{
    public function scan(Entity\Country $country, $page = 1)
    {
        $request  = $this->getClient()->get('economy/job-market/'.$country->getId().'/'.$page);
        $response = $request->send();
        
        return $this->parseOffers($response->getBody(true));
    }
    
    public static function parseOffers($html)
    {
        $hxs = Selector::loadHTML($html);
        return $hxs->findAll('//*[@class="salary_sorted"]/tr')->map(function (Node $row) {
            $url = $row->find('td/a/@href')->extract();
            return [
                'employer' => [
                    'id' => (int)substr($url, strrpos($url, '/')+1),
                    'name' => $row->find('td/a/@title')->extract()
                ],
                'salary' => (int)$row->find('td[4]/strong')->extract()+
                    (float)substr($row->find('td[4]/sup')->extract(), 1)/100
            ];
        });
    }
}
