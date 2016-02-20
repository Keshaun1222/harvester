<?php
namespace Erpk\Harvester\Module\Login;

use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use XPathSelector\Exception\NodeNotFoundException;

class LoginModule extends Module
{
    public function login()
    {
        $client = $this->getClient();
        $session = $this->getSession();

        $login = $client->post('login');
        $login->followRedirects();
        $login->setRelativeReferer();
        $login->addPostFields([
            '_token'            =>  md5(time()),
            'citizen_email'     =>  $session->getEmail(),
            'citizen_password'  =>  $session->getPassword(),
            'remember'          =>  1
        ]);

        $hxs = $login->send()->xpath();
        $token = null;
        try {
            $token = $hxs->find('//*[@id="_token"][1]/@value')->extract();
        } catch (NodeNotFoundException $e) {
            $scripts = $hxs->findAll('//script[@type="text/javascript"]');
            $tokenPattern = '@csrfToken\s*:\s*\'([a-z0-9]+)\'@';
            foreach ($scripts as $script) {
                if (preg_match($tokenPattern, $script->extract(), $matches)) {
                    $token = $matches[1];
                    break;
                }
            }
        }

        if ($token === null) {
            throw new ScrapeException('CSRF token not found');
        }

        $link = $hxs->find('//a[@class="user_avatar"][1]');
        $this->getClient()->getSession()
            ->setToken($token)
            ->setCitizenId((int)explode('/', $link->find('@href')->extract())[4])
            ->setCitizenName($link->find('@title')->extract())
            ->save();
    }

    public function logout()
    {
        $this->getClient()->post('logout')->send();
    }
}
