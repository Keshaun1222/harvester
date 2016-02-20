<?php
namespace Erpk\Harvester\Module\Politics;

use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Exception\NotFoundException;
use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Filter;
use Erpk\Harvester\Module\Module;

class PoliticsModule extends Module
{
    public function getParty($id)
    {
        $id = Filter::id($id);
        $this->getClient()->checkLogin();

        $response = $this->getClient()->get('party/'.$id)->send();

        if ($response->isRedirect()) {
            if (strpos($response->getLocation(), '/party/') != false) {
                $response = $this->getClient()->get($response->getLocation())->send();
            } else {
                throw new NotFoundException("Political party with ID $id does not exist.");
            }
        }

        $hxs = $response->xpath();
        
        $result = ['id' => $id];
        $profileholder = $hxs->find('//div[@id="profileholder"][1]');
        $url = $profileholder->find('a[2]/@href')->extract();
        $about = $hxs->findOneOrNull('//div[@class="about_message party_section"][1]/p[1]');
        $info = $hxs->find('//div[@class="infoholder"][1]');
        $congress = $hxs->find('//a[@name="congress"][1]/..');
        $countries = $this->getEntityManager()->getRepository('Erpk\Common\Entity\Country');
        
        $result['name']         = $profileholder->find('h1[1]')->extract();
        $result['about']        = $about ? trim($about->extract()) : null;
        $result['members']      = (int)$info->find('p[1]/span[2]')->extract();
        $result['orientation']  = $info->find('p[2]/span[2]')->extract();
        $result['country']      = $countries->findOneByCode($profileholder->find('a[3]/img/@alt')->extract());
        
        if (!$result['country']) {
            throw new ScrapeException();
        }
        
        $result['president'] = [
            'id'        =>  (int)substr($url, 1+strrpos($url, '/')),
            'name'      =>  $profileholder->find('a[2]')->extract()
        ];
        
        $result['congress']  = [
            'members' => (int)trim($congress->find('div[1]/div[1]/div[1]/p[1]/span[1]')->extract()),
            'share' => ((float)rtrim(trim($congress->find('div[1]/div[1]/div[1]/p[1]/span[1]')->extract()), '%'))/100
        ];
        
        return $result;
    }
}
