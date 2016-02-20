<?php
namespace Erpk\Harvester\Module\Market;

use Erpk\Common\Entity\Country;
use Erpk\Common\Entity\Industry;

class Offer
{
    public $id;
    public $amount;
    public $price;
    public $sellerId;
    public $sellerName;

    /**
     * @var Country
     */
    public $country;

    /**
     * @var Industry
     */
    public $industry;
    public $quality;
    
    public function toArray()
    {
        return [
            'id'       =>  $this->id,
            'amount'   =>  $this->amount,
            'price'    =>  $this->price,
            'seller'   =>  [
                'id'     =>  $this->sellerId,
                'name'   =>  $this->sellerName
            ]
        ];
    }
}
