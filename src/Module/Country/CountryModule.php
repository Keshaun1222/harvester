<?php
namespace Erpk\Harvester\Module\Country;

use Erpk\Common\Entity\Country;
use Erpk\Common\Entity\Industry;
use Erpk\Common\Entity\Region;
use Erpk\Harvester\Client\Selector\Filter;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use XPathSelector\Node;
use XPathSelector\NodeList;

class CountryModule extends Module
{
    protected function get(Country $country, $type)
    {
        $name = $country->getEncodedName();
        $request = $this->getClient()->get("country/$type/$name");
        $request->disableCookies();
        return $request->send()->xpath();
    }

    public function getSociety(Country $country)
    {
        $xs = $this->get($country, 'society');
        $result = $country->toArray();

        $table = $xs->findAll('//table[@class="citizens largepadded"]/tr[position()>1]');
        foreach ($table as $tr) {
            /**
             * @var Node $tr
             */
            $key = $tr->find('td[2]/span')->extract();
            $key = strtr(strtolower($key), ' ', '_');
            if ($key == 'citizenship_requests') {
                continue;
            }
            $value = $tr->find('td[3]/span')->extract();
            $result[$key] = (int)str_replace(',', '', $value);
        }

        if (preg_match('#Regions \(([0-9]+)\)#', $xs->outerHTML(), $regions)) {
            $result['region_count'] = (int)$regions[1];
        }

        $regions = $this->getEntityManager()->getRepository(Region::class);
        $result['regions'] = $xs->findAll('//table[@class="regions"]/tr[position()>1]')->map(
            function (Node $tr) use ($regions) {
                $regionName = trim($tr->find('td[1]//a[1]')->extract());
                return $regions->findOneByName($regionName);
            }
        );

        return $result;
    }

    public function getEconomy(Country $country)
    {
        $xs = $this->get($country, 'economy');
        $result = $country->toArray();

        $economy = $xs->find('//div[@id="economy"]');

        /* TREASURY */
        $treasury = $economy->findAll('//table[@class="donation_status_table"]/tr');
        foreach ($treasury as $tr) {
            /**
             * @var Node $tr
             */
            $amount = Filter::parseInt($tr->find('td[1]/span')->extract());
            if ($tr->findOneOrNull('td[1]/sup') !== null) {
                $amount += (float)$tr->find('td[1]/sup')->extract();
            }
            $key = strtolower($tr->find('td[2]/span')->extract());
            if ($key != 'gold' && $key != 'energy') {
                $key = 'cc';
            }
            $result['treasury'][$key] = $amount;
        }

        /* RESOURCES AND BONUSES */
        $result['bonuses'] = array_fill_keys(['food', 'frm', 'weapons', 'wrm', 'house', 'hrm'], 0);
        $resources = $economy->findAll('//table[@class="resource_list"]/tr/td[1]');
        foreach ($resources as $td) {
            /**
             * @var Node $td
             */
            $resourceName = $td->find('span[1]')->extract();
            $bonusPercentage = (int)trim($td->find('span[@class="bonus_value"][1]')->extract(), '(+%)');
            $bonusDecimal = $bonusPercentage / 100;

            if (in_array($resourceName, ['Grain', 'Fish', 'Cattle', 'Deer', 'Fruits'])) {
                $result['bonuses']['frm'] += $bonusDecimal;
                $result['bonuses']['food'] += $bonusDecimal;
            } elseif (in_array($resourceName, ['Iron', 'Saltpeter', 'Rubber', 'Aluminum', 'Oil'])) {
                $result['bonuses']['wrm'] += $bonusDecimal;
                $result['bonuses']['weapons'] += $bonusDecimal;
            } elseif (in_array($resourceName, ['Sand', 'Clay', 'Wood', 'Limestone', 'Granite'])) {
                $result['bonuses']['hrm'] += $bonusDecimal;
                $result['bonuses']['house'] += $bonusDecimal;
            }
        }

        /* TAXES */
        $industries = $this->getEntityManager()->getRepository(Industry::class);
        $taxes = $economy->findAll('h2[text()="Taxes" and @class="section"]/following-sibling::div[1]/table/tr[position()>1]');
        foreach ($taxes as $k => $tr) {
            $i = $tr->findAll('td/span');
            /**
             * @var NodeList $i
             */
            if (count($i) != 4) {
                throw new ScrapeException();
            }
            $vat = (float)rtrim($i->item(3)->extract(), '%') / 100;
            if (!preg_match('@industry/(\d+)/@', $tr->find('td[1]/img[1]/@src')->extract(), $industryId)) {
                throw new ScrapeException();
            }

            $industry = $industries->find((int)$industryId[1])->getCode();
            $result['taxes'][$industry] = [
                'income' => (float)rtrim($i->item(1)->extract(), '%') / 100,
                'import' => (float)rtrim($i->item(2)->extract(), '%') / 100,
                'vat' => empty($vat) ? null : $vat,
            ];
        }

        /* SALARY */
        $salary = $economy->findAll(
            'h2[text()="Salary" and @class="section"]/following-sibling::div[1]/table/tr[position()>1]'
        );
        foreach ($salary as $k => $tr) {
            $i = $tr->findAll('td[position()>=1 and position()<=2]/span');
            /**
             * @var NodeList $i
             */
            if (count($i) != 2) {
                throw new ScrapeException();
            }
            $type = $i->item(0)->extract();
            $result['salary'][strtolower($type)] = (float)$i->item(1)->extract();
        }

        /* EMBARGOES */
        $countries = $this->getEntityManager()->getRepository(Country::class);
        $result['embargoes'] = [];
        $embargoes = $economy->findAll(
            'h2[text()="Trade embargoes" and @class="section"]' .
            '/following-sibling::div[1]/table/tr[position()>1]'
        );

        foreach ($embargoes as $tr) {
            if ($tr->findOneOrNull('td[1]/@colspan') !== null) {
                break;
            }
            $result['embargoes'][] = [
                'country' => $countries->findOneByName($tr->find('td[1]/span/a/@title')->extract()),
                'expires' => str_replace('Expires in ', '', trim($tr->find('td[2]')->extract()))
            ];
        }
        return $result;
    }

    public function getOnlineCitizens(Country $country, $page = 1)
    {
        $this->getClient()->checkLogin();

        $xs = $this->getClient()->get(
            'main/online-users/' . $country->getEncodedName() . '/all/' . $page
        )->send()->xpath();

        return $xs->findAll('//div[@class="citizen"]')->map(function (Node $node) {
            $url = $node->find('div[@class="nameholder"]/a[1]/@href')->extract();
            return [
                'id' => (int)substr($url, strrpos($url, '/') + 1),
                'name' => trim($node->find('div[@class="nameholder"]/a[1]')->extract()),
                'avatar' => $node->find('div[@class="avatarholder"]/a[1]/img[1]/@src')->extract()
            ];
        });
    }
}
