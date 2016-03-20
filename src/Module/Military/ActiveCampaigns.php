<?php
namespace Erpk\Harvester\Module\Military;

class ActiveCampaigns extends \ArrayObject
{
    /**
     * ActiveCampaigns constructor.
     * @param array $data
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * @param int $countryId
     * @return array|null
     */
    public function findCampaignOfTheDay($countryId)
    {
        $cotd = &$this['countries'][$countryId]['cotd'];
        return $cotd > 0 ? $this['battles'][$cotd] : null;
    }

    /**
     * @param int $countryId
     * @return array
     */
    public function findCountryCampaigns($countryId)
    {
        $result = [];
        foreach ($this['battles'] as $battleId => $battle) {
            if ($battle['inv']['id'] != $countryId && $battle['def']['id'] != $countryId) continue;
            $result[$battleId] = $battle;
        }

        ksort($result);
        return $result;
    }

    /**
     * @param int $countryId
     * @return array
     */
    public function findAlliesCampaigns($countryId)
    {
        $result = [];
        foreach ($this['countries'][$countryId]['allies'] as $allyId) {
            foreach ($this['battles'] as $battleId => $battle) {
                if ($battle['is_rw'] || $battle['is_lib'] || $battle['is_dict']) continue;

                $battle['inv']['isMyAlly'] = $battle['inv']['id'] == $allyId;
                $battle['def']['isMyAlly'] = $battle['def']['id'] == $allyId;

                if (!$battle['inv']['isMyAlly'] && !$battle['def']['isMyAlly']) continue;
                $result[$battleId] = $battle;
            }
        }

        ksort($result);
        return $result;
    }
}
