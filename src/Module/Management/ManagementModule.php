<?php
namespace Erpk\Harvester\Module\Management;

use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use XPathSelector\Node;

class ManagementModule extends Module
{
    public function eat()
    {
        $request = $this->getClient()->get('main/eat')->xhr();
        $request->setRelativeReferer();

        $query = $request->getQuery();
        $query->set('format', 'json');
        $query->set('_token', $this->getSession()->getToken());
        $query->set('_', time());

        return $request->send()->json();
    }

    public function getEnergyStatus()
    {
        $request = $this->getClient()->get();
        $hxs = $request->send()->xpath();

        $result = [];
        $current = explode(' / ', $hxs->find('//*[@id="current_health"][1]')->extract());

        $result['energy'] = (int)$current[0];
        $result['max_energy'] = (int)$current[1];

        preg_match('/food_remaining = parseInt\("(\d+)", 10\);/', $hxs->outerHTML(), $matches);
        $result['food_recoverable_energy'] = (int)$matches[1];
        return $result;
    }

    public function sendMessage($citizenId, $subject, $content)
    {
        $url = 'main/messages-compose/' . $citizenId;
        $request = $this->getClient()->post($url)->csrf()->xhr();
        $request->setRelativeReferer($url);
        $request->addPostFields([
            'citizen_name' => $citizenId,
            'citizen_subject' => $subject,
            'citizen_message' => $content
        ]);

        return $request->send()->getBody(true);
    }

    public function getInventory()
    {
        $request = $this->getClient()->get('economy/inventory');
        $request->setRelativeReferer('economy/myCompanies');
        $hxs = $request->send()->xpath();

        $result = [];

        $parseItem = function (Node $item) use (&$result) {
            $ex = explode('_', str_replace('stock_', '', $item->find('strong/@id')->extract()));
            $result['items'][(int)$ex[0]][(int)$ex[1]] = (int)strtr($item->find('strong')->extract(), [',' => '']);
        };

        $items = $hxs->findAll('//*[@class="item_mask"][1]/ul[1]/li');
        foreach ($items as $item) {
            $parseItem($item);
        }

        $items = $hxs->findAll('//*[@class="item_mask"][2]/ul[1]/li');
        foreach ($items as $item) {
            $parseItem($item);
        }

        $storage = trim($hxs->find('//*[@class="area storage"][1]/h4[1]/strong[1]')->extract());
        $storage = strtr($storage, [
            ',' => '',
            ')' => '',
            '(' => ''
        ]);
        $storage = explode('/', $storage);

        $result['storage'] = [
            'current' => (int)$storage[0],
            'maximum' => (int)$storage[1]
        ];

        return $result;
    }

    public function getAccounts()
    {
        $request = $this->getClient()->get('economy/exchange-market/');
        $hxs = $request->send()->xpath();

        return [
            'cc' => (float)$hxs->find('//input[@id="eCash"][1]/@value')->extract(),
            'gold' => (float)$hxs->find('//input[@id="golden"][1]/@value')->extract(),
        ];
    }

    /**
     * @return CompanyCollection
     * @throws ScrapeException
     */
    public function getCompanies()
    {
        $request = $this->getClient()->get('economy/myCompanies');
        $response = $request->send();
        $html = $response->getBody(true);
        preg_match('#var companies\s+=\s+(.+);#', $html, $matches);

        $companies = json_decode($matches[1], true);
        if (!is_array($companies)) {
            throw new ScrapeException();
        }

        foreach ($companies as $n => $company) {
            $companies[$n] = new Company($company);
        }

        return new CompanyCollection($companies);
    }

    /**
     * @return array
     */
    public function getTrainingGrounds()
    {
        $request = $this->getClient()->get('economy/training-grounds');
        $response = $request->send();
        $html = $response->getBody(true);
        preg_match('#var grounds\s+=\s+(.+);#', $html, $matches);
        $result = json_decode($matches[1], true);
        return $result;
    }

    /**
     * @param bool $q1
     * @param bool $q2
     * @param bool $q3
     * @param bool $q4
     * @return array
     */
    public function train($q1 = true, $q2 = false, $q3 = false, $q4 = false)
    {
        $grounds = $this->getTrainingGrounds();

        $toTrain = [];
        foreach ([$q1, $q2, $q3, $q4] as $i => $selection) {
            if ($selection !== true || $grounds[$i]['trained'] === true) {
                continue;
            }

            $toTrain[] = [
                'id' => (int)$grounds[$i]['id'],
                'train' => 1
            ];
        }

        $request = $this->getClient()->post('economy/train')->csrf()->xhr();
        $request->setRelativeReferer('economy/training-grounds');
        $request->addPostFields([
            'grounds' => $toTrain
        ]);

        return $request->send()->json();
    }

    /**
     * @return array
     */
    public function workAsEmployee()
    {
        return $this->work(['action_type' => 'work']);
    }

    /**
     * @param WorkQueue $queue
     * @return array
     */
    public function workAsManager(WorkQueue $queue)
    {
        return $this->work([
            'companies' => $queue->toArray(),
            'action_type' => 'production'
        ]);
    }

    /**
     * @param array $postFields
     * @return array
     */
    protected function work(array $postFields)
    {
        $request = $this->getClient()->post('economy/work')->csrf()->xhr();
        $request->setRelativeReferer('economy/myCompanies');
        $request->addPostFields($postFields);
        return $request->send()->json();
    }

    /**
     * @return array
     */
    public function getDailyTasksReward()
    {
        $request = $this->getClient()->get('main/daily-tasks-reward')->xhr();
        $request->setRelativeReferer();
        return $request->send()->json();
    }
}
