<?php
namespace Erpk\Harvester\Client\Selector;

use Erpk\Harvester\Exception\InvalidArgumentException;

class Filter
{
    public static function validRequestedPage(XPath $hxs, $requestedPage)
    {
        $pager = $hxs->select('(//ul[@class="pager"]/li/a)[last()]');
        if ($pager->hasResults()) {
            $href = $pager->select('@href')->extract();
            $currentPage = (int)substr($href, strrpos($href, '/')+1);
            if ($requestedPage > $currentPage) {
                return false;
            } else {
                return true;
            }
        } elseif ($requestedPage > 1) {
            return false;
        } else {
            return true;
        }
    }
    
    public static function extractPartyIdFromUrl($url)
    {
        preg_match('@-([0-9]+)$@', $url, $partyId);
        return (int)$partyId[1];
    }
    
    public static function extractCitizenIdFromUrl($url)
    {
        preg_match('@citizen/profile/([0-9]+)$@', $url, $citizenId);
        return (int)$citizenId[1];
    }
    
    public static function normalizeAvatar($url)
    {
        return preg_replace('#(.+?)([a-z0-9]{32})(.+)#', '\1\2_100x100.jpg', $url);
    }

    public static function parseInt($n)
    {
        $n = (float)trim(str_replace(',', '', $n));
        return $n < PHP_INT_MAX ? (int)$n : $n;
    }
}
