<?php
namespace Erpk\Harvester\Module\Military;

use Erpk\Harvester\Module\Module;

class LeaderboardsModule extends Module
{
    /**
     * @param int $countryId
     * @param int $week
     * @param int $unitId
     * @param int $division
     * @return array
     */
    public function citizensDamageUnit($countryId, $week, $unitId, $division)
    {
        return $this->request('damage', $countryId, $week, $unitId, $division);
    }

    /**
     * @param int $countryId
     * @param int $week
     * @param int $division
     * @return array
     */
    public function citizensDamageNational($countryId, $week, $division)
    {
        return $this->request('damage', $countryId, $week, 0, $division);
    }

    /**
     * @param int $countryId
     * @param int $week
     * @param int $unitId
     * @param int $division
     * @return array
     */
    public function citizensKillsUnit($countryId, $week, $unitId, $division)
    {
        return $this->request('kills', $countryId, $week, $unitId, $division);
    }

    /**
     * @param int $countryId
     * @param int $week
     * @param int $division
     * @return array
     */
    public function citizensKillsNational($countryId, $week, $division)
    {
        return $this->request('kills', $countryId, $week, 0, $division);
    }

    /**
     * @param int $countryId
     * @return array
     */
    public function muDamage($countryId)
    {
        return $this->request('mudamage', $countryId);
    }

    /**
     * @param int $countryId
     * @return array
     */
    public function muKills($countryId)
    {
        return $this->request('mukills', $countryId);
    }

    /**
     * @return array
     */
    public function countryDamage()
    {
        return $this->request('codamage');
    }

    /**
     * @return array
     */
    public function countryKills()
    {
        return $this->request('cokills');
    }

    /**
     * @param string $type
     * @param int $countryId
     * @param int $week
     * @param int $unitId
     * @param int $division
     * @return array
     */
    private function request($type, $countryId = 0, $week = 0, $unitId = 0, $division = 0)
    {
        return $this->getClient()->get("main/leaderboards-$type-rankings/$countryId/$week/$unitId/$division")->send()->json();
    }
}
