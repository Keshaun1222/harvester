<?php
namespace Erpk\Harvester\Module\JobMarket;

use Erpk\Harvester\Module\Module;
use XPathSelector\Node;

class JobMarketModule extends Module
{
    /**
     * @param int $countryId
     * @param int $page
     * @return array
     */
    public function scan($countryId, $page = 1)
    {
        $request = $this->getClient()->get("economy/job-market/$countryId/$page");
        $xs = $request->send()->xpath();

        return $xs->findAll('//*[@class="salary_sorted"]/tr')->map(function (Node $row) {
            $url = $row->find('td/a/@href')->extract();
            return [
                'employer' => [
                    'id' => (int)substr($url, strrpos($url, '/') + 1),
                    'name' => $row->find('td/a/@title')->extract()
                ],
                'salary' => (int)$row->find('td[4]/strong')->extract() +
                    (float)substr($row->find('td[4]/sup')->extract(), 1) / 100
            ];
        });
    }
}
