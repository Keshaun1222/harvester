<?php
namespace Erpk\Harvester\Module\JobMarket;

use Erpk\Harvester\Module\Module;
use XPathSelector\Selector;
use Erpk\Harvester\Filter;
use Erpk\Common\Entity;

class JobMarketModule extends Module
{
    public function scan(Entity\Country $country, $page = 1)
    {
        $page = Filter::page($page);
        $this->getClient()->checkLogin();

        $request  = $this->getClient()->get('economy/job-market/'.$country->getId().'/'.$page);
        $response = $request->send();
        
        return $this->parseOffers($response->getBody(true));
    }
    
    public static function parseOffers($html)
    {
        $offers = [];

        $hxs = Selector::loadHTML($html);
        foreach ($hxs->findAll('//*[@class="salary_sorted"]/tr') as $row) {
            $url = $row->find('td/a/@href')->extract();
            $offers[] = [
                'employer' => [
                    'id' => (int)substr($url, strrpos($url, '/')+1),
                    'name' => $row->find('td/a/@title')->extract()
                ],
                'salary' => (int)$row->find('td[4]/strong')->extract()+
                (float)substr($row->find('td[4]/sup')->extract(), 1)/100
            ];
        }
        return $offers;
    }
}
