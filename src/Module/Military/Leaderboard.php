<?php
namespace Erpk\Harvester\Module\Military;

use Erpk\Harvester\Client\Client;
use Erpk\Harvester\Module\Module;
use Erpk\Common\Entity;
use Erpk\Harvester\Exception\InvalidArgumentException;

class Leaderboard extends Module
{
    const CITIZEN_DAMAGE = 'damage';
    const CITIZEN_KILLS = 'kills';
    const MU_DAMAGE = 'mudamage';
    const MU_KILLS = 'mukills';
    const COUNTRY_DAMAGE = 'codamage';
    const COUNTRY_KILLS = 'cokills';

    const THISWEEK = 0;
    const LASTWEEK = 1;
    const TWOWEEKSAGO = 2;
    const THREEWEEKSAGO = 3;

    private $militaryunitId;
    private $country;
    private $division;
    private $week;

    public function __construct(Client $client, Entity\Country $country, $division = 1, $militaryunitId = 0, $week = 0)
    {
        parent::__construct($client);
        if (!isset($country)) {
        	throw new InvalidArgumentException('Country is not specified.');
        	break;
        }
        $this->setCountry($country);
        $this->setDivision($division);
        $this->setMilitaryunitId($militaryunitId);
        $this->setWeek($week);
    }

    /**
     * @return the $militaryunitId
     */
    public function getMilitaryunitId()
    {
        return $this->militaryunitId;
    }

    /**
     * @return the $country
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * @return the $division
     */
    public function getDivision()
    {
        return $this->division;
    }

    /**
     * @return the $week
     */
    public function getWeek()
    {
        return $this->week;
    }

    /**
     * @param integer $militaryunitId
     */
    public function setMilitaryunitId($militaryunitId)
    {
        $this->militaryunitId = $militaryunitId;
    }

    /**
     * @param Entity\Country $country
     */
    public function setCountry(Entity\Country $country)
    {
        $this->country = $country;
    }

    /**
     * @param integer(1..4) $division
     */
    public function setDivision($division)
    {
        if ($division < 1) {
            $division = 1;
        }
        if ($division > 4) {
            $division = 4;
        }
        $this->division = $division;
    }

    /**
     * @param integer(0..3) $week
     *        use Leaderboard::THISWEEK, Leaderboard::LASTWEEK,
     *        Leaderboard::TWOWEEKSAGO, Leaderboard::THREEWEEKSAGO
     *        for easier use.
     */
    public function setWeek($week)
    {
        if ($week < 0) {
            $week = 0;
        }
        if ($week > 3) {
            $week = 3;
        }
        $this->week = $week;
    }

    public function citizensDamage($asarray = true)
    {
        $params = $this->getCountry()->getId().'/'.$this->week.'/'.$this->militaryunitId.'/'.$this->division;
        $request = $this->getClient()->get('main/leaderboards-'.self::CITIZEN_DAMAGE.'-rankings/'.$params);
        $response = $request->send();
            if ($asarray) {
            return $response->json();
        } else {
            return $response->getBody(true);
        }
    }

    public function citizensKills($asarray = true)
    {
        $params = $this->getCountry()->getId().'/'.$this->week.'/'.$this->militaryunitId.'/'.$this->division;
        $request = $this->getClient()->get('main/leaderboards-'.self::CITIZEN_KILLS.'-rankings/'.$params);
        $response = $request->send();
            if ($asarray) {
            return $response->json();
        } else {
            return $response->getBody(true);
        }
    }

    public function muDamage($asarray = true)
    {
        $params = $this->getCountry()->getId().'/'.$this->week.'/0/0';
        $request = $this->getClient()->get('main/leaderboards-'.self::MU_DAMAGE.'-rankings/'.$params);
        $response = $request->send();
            if ($asarray) {
            return $response->json();
        } else {
            return $response->getBody(true);
        }
    }

    public function muKills($asarray = true)
    {
        $params = $this->getCountry()->getId().'/'.$this->week.'/0/0';
        $request = $this->getClient()->get('main/leaderboards-'.self::MU_KILLS.'-rankings/'.$params);
        $response = $request->send();
            if ($asarray) {
            return $response->json();
        } else {
            return $response->getBody(true);
        }
    }

    public function countryDamage($asarray = true)
    {
        $params = $this->week;
        $request = $this->getClient()->get('main/leaderboards-'.self::COUNTRY_DAMAGE.'-rankings/'.$params);
        $response = $request->send();
        if ($asarray) {
            return $response->json();
        } else {
            return $response->getBody(true);
        }
    }

    public function countryKills($asarray = true)
    {
        $params = $this->week;
        $request = $this->getClient()->get('main/leaderboards-'.self::COUNTRY_KILLS.'-rankings/'.$params);
        $response = $request->send();
            if ($asarray) {
            return $response->json();
        } else {
            return $response->getBody(true);
        }
    }
}
