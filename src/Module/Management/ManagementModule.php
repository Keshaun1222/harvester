<?php
namespace Erpk\Harvester\Module\Management;

use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Exception\NotFoundException;
use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Filter;
use Erpk\Harvester\Module\Module;

class ManagementModule extends Module
{
    public function eat()
    {
        $this->getClient()->checkLogin();
        
        $request = $this->getClient()->get('main/eat');
        $request->getHeaders()
            ->set('Referer', $this->getClient()->getBaseUrl())
            ->set('X-Requested-With', 'XMLHttpRequest');
        $query = $request->getQuery();
        $query
            ->set('format', 'json')
            ->set('_token', $this->getSession()->getToken())
            ->set('_', time());
            
        $response = $request->send()->json();
        return $response;
    }

    public function getEnergyStatus()
    {
        $this->getClient()->checkLogin();
        
        $request = $this->getClient()->get();
        $html = $request->send()->getBody(true);
        $hxs = Selector\XPath::loadHTML($html);

        $result = array();

        $current = explode(' / ', $hxs->select('//*[@id="current_health"][1]')->extract());

        $result['energy'] = (int)$current[0];
        $result['max_energy'] = (int)$current[1];

        preg_match('/food_remaining = parseInt\("(\d+)", 10\);/', $html, $matches);
        $result['food_recoverable_energy'] = (int)$matches[1];
        return $result;
    }

    public function sendMessage($citizenId, $subject, $content)
    {
        $this->getClient()->checkLogin();

        $request = $this->getClient()->post('main/messages-compose/'.$citizenId);
        $request->getHeaders()
            ->set('X-Requested-With', 'XMLHttpRequest')
            ->set('Referer', $this->getClient()->getBaseUrl().'/main/messages-compose/'.$citizenId);

        $request->addPostFields([
            '_token'          => $this->getSession()->getToken(),
            'citizen_name'    => $citizenId,
            'citizen_subject' => $subject,
            'citizen_message' => $content
        ]);

        $response = $request->send();
        return $response->getBody(true);
    }
    
    public function getInventory()
    {
        $this->getClient()->checkLogin();
        
        $request = $this->getClient()->get('economy/inventory');
        $request->getHeaders()->set('Referer', 'http://www.erepublik.com/en/economy/myCompanies');
        
        $response = $request->send();
        $hxs = Selector\XPath::loadHTML($response->getBody(true));
        
        $result = array();
        
        $parseItem = function ($item) use (&$result) {
            $ex = explode('_', str_replace('stock_', '', $item->select('strong/@id')->extract()));
            $result['items'][(int)$ex[0]][(int)$ex[1]] = (int)strtr($item->select('strong')->extract(), array(','=>''));
        };
        
        $items = $hxs->select('//*[@class="item_mask"][1]/ul[1]/li');
        foreach ($items as $item) {
            $parseItem($item);
        }
        
        $items = $hxs->select('//*[@class="item_mask"][2]/ul[1]/li');
        foreach ($items as $item) {
            $parseItem($item);
        }
        
        $storage = trim($hxs->select('//*[@class="area storage"][1]/h4[1]/strong[1]')->extract());
        $storage = strtr(
            $storage,
            array(
                ',' => '',
                ')' => '',
                '(' => ''
            )
        );
        $storage = explode('/', $storage);
        
        $result['storage'] = array(
            'current' => (int)$storage[0],
            'maximum' => (int)$storage[1]
        );
        
        return $result;
    }
    
    public function getCompanies()
    {
        $this->getClient()->checkLogin();
        
        $request = $this->getClient()->get('economy/myCompanies');
        $response = $request->send();
        $html = $response->getBody(true);
        preg_match('#var companies\s+=\s+(.+);#', $html, $matches);

        $companies = json_decode($matches[1], true);
        if (!is_array($companies)) {
            throw new ScrapeException;
        }
        
        foreach ($companies as $n => $company) {
            $companies[$n] = new Company($company);
        }

        return new CompanyCollection($companies);
    }
    
    public function getTrainingGrounds()
    {
        $this->getClient()->checkLogin();
        
        $request = $this->getClient()->get('economy/training-grounds');
        $response = $request->send();
        $html = $response->getBody(true);
        preg_match('#var grounds\s+=\s+(.+);#', $html, $matches);
        $result = json_decode($matches[1], true);
        return $result;
    }
    
    public function getAccounts()
    {
        $this->getClient()->checkLogin();
        
        $request = $this->getClient()->get('economy/exchange-market/');
        $response = $request->send();
        $hxs = Selector\XPath::loadHTML($response->getBody(true));
        
        return array(
            'cc'   => (float)$hxs->select('//input[@id="eCash"][1]/@value')->extract(),
            'gold' => (float)$hxs->select('//input[@id="golden"][1]/@value')->extract(),
        );
    }

    public function train($q1 = true, $q2 = false, $q3 = false, $q4 = false)
    {
        $this->getClient()->checkLogin();
        $grounds = $this->getTrainingGrounds();

        $toTrain = array();
        for ($i = 0; $i <= 3; $i++) {
            if (${'q'.($i+1)} === true && $grounds[$i]['trained'] === false) {
                $toTrain[] = array(
                    'id' => (int)$grounds[$i]['id'],
                    'train' => 1
                );
            }
        }

        $request = $this->getClient()->post('economy/train');
        $request->getHeaders()
            ->set('X-Requested-With', 'XMLHttpRequest')
            ->set('Referer', $this->getClient()->getBaseUrl().'/economy/training-grounds');
        $request->addPostFields(
            array(
                '_token'  => $this->getSession()->getToken(),
                'grounds' => $toTrain
            )
        );

        $response = $request->send()->json();
        return $response;
    }

    protected function work($postFields)
    {
        $this->getClient()->checkLogin();
        $request = $this->getClient()->post('economy/work');
        $request->getHeaders()
            ->set('X-Requested-With', 'XMLHttpRequest')
            ->set('Referer', $this->getClient()->getBaseUrl().'/economy/myCompanies');

        $postFields = array_merge(
            $postFields,
            array(
                '_token' => $this->getSession()->getToken()
            )
        );

        $request->addPostFields($postFields);

        $response = $request->send()->json();
        return $response;
    }

    public function workAsEmployee()
    {
        return $this->work(
            array('action_type' => 'work')
        );
    }

    public function workAsManager(WorkQueue $queue)
    {
        return $this->work(
            array(
                'companies'   => $queue->toArray(),
                'action_type' => 'production'
            )
        );
    }
    
    public function getDailyTasksReward()
    {
        $this->getClient()->checkLogin();
        $request = $this->getClient()->get('main/daily-tasks-reward');
        $request->getHeaders()
            ->set('X-Requested-With', 'XMLHttpRequest')
            ->set('Referer', $this->getClient()->getBaseUrl());

        $response = $request->send()->json();
        return $response;
    }
}
