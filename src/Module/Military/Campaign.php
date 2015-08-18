<?php
namespace Erpk\Harvester\Module\Military;

use Erpk\Common\Entity\Country;
use Erpk\Common\Entity\Region;

class Campaign
{
    /**
     * @var int
     **/
    protected $id;

    /**
     * @var Country
     **/
    protected $attacker;

    /**
     * @var Country
     **/
    protected $defender;

    /**
     * @var Country
     */
    protected $citizenSide;

    /**
     * @var Region
     **/
    protected $region;

    /**
     * @var bool
     **/
    protected $isResistance;

    /**
     * @var bool
     */
    protected $canFight;

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'id'             => $this->getId(),
            'url'            => 'http://www.erepublik.com/en/military/battlefield-new/'.$this->getId(),
            'region'         => $this->getRegion(),
            'is_resistance'  => $this->isResistance(),
            'attacker'       => $this->getAttacker(),
            'defender'       => $this->getDefender()
        ];
    }

    public function setId($id)
    {
        $this->id = (int)$id;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param int $sideConstant MilitaryModule::SIDE_ATTACKER or MilitaryModule::SIDE_DEFENDER
     * @return Country
     */
    public function getSide($sideConstant)
    {
        if ($sideConstant === MilitaryModule::SIDE_ATTACKER) {
            return $this->attacker;
        } else if ($sideConstant === MilitaryModule::SIDE_DEFENDER) {
            return $this->defender;
        } else if ($sideConstant === MilitaryModule::SIDE_AUTO) {
            return $this->citizenSide;
        } else {
            throw new \InvalidArgumentException("Invalid side constant.");
        }
    }

    /**
     * @param Country $country
     */
    public function setAttacker(Country $country)
    {
        $this->attacker = $country;
    }

    /**
     * @return Country
     */
    public function getAttacker()
    {
        return $this->attacker;
    }

    /**
     * @param Country $country
     */
    public function setDefender(Country $country)
    {
        $this->defender = $country;
    }

    /**
     * @return Country
     */
    public function getDefender()
    {
        return $this->defender;
    }

    /**
     * @param Country $country
     */
    public function setChoosenSide(Country $country)
    {
        $this->citizenSide = $country;
    }

    /**
     * @return Country
     */
    public function getChoosenSide()
    {
        return $this->citizenSide;
    }

    /**
     * @param Region $region
     */
    public function setRegion(Region $region)
    {
        $this->region = $region;
    }

    /**
     * @return Region
     */
    public function getRegion()
    {
        return $this->region;
    }

    /**
     * @param bool $res
     */
    public function setResistance($res)
    {
        $this->isResistance = (bool)$res;
    }

    /**
     * @return bool
     */
    public function isResistance()
    {
        return $this->isResistance;
    }

    /**
     * @param bool $bool
     */
    public function setCanFight($bool)
    {
        $this->canFight = (bool)$bool;
    }

    /**
     * @return bool Whether citizen needs to change its location to fight
     */
    public function canFight()
    {
        return $this->canFight;
    }
}
