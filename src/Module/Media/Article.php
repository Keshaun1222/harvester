<?php
namespace Erpk\Harvester\Module\Media;

use RuntimeException;

class Article
{
    protected $id;
    protected static $idPattern = '@article/([^/]+)/@';

    public static function createFromUrl($url)
    {
        if (preg_match(self::$idPattern, $url, $matches)) {
            return new Article($matches[1]);
        } else {
            throw new RuntimeException('Wrong article URL');
        }
    }

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getUrl($locale = 'en')
    {
        return 'http://www.erepublik.com/' . $locale . '/article/' . $this->getId() . '/1/20';
    }
}
