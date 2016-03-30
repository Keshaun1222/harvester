What is that?
-------------

**Harvester** is eRepublik web scraping utility. Originally, Harvester was used by [api.erpk.org](http://api.erpk.org), but I decided to release it open source. It allows you easily get useful information directly from game.
It's written in PHP and based mainly on DOMXPath library. It requires PHP 5.4+.

Isn't your application written in PHP?
--------------------------------------

If your application isn't written in PHP, you may be looking for **standalone API webserver** - [erpk/harsever](https://github.com/erpk/harserver).

Installation
------------
Recommended method to install library is getting it through [Composer](http://getcomposer.org/).
Create `composer.json` file in your application directory:
```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
      "erpk/harvester": "dev-master"
    }
}
```

Then download latest [composer.phar](http://getcomposer.org/composer.phar) and run following command:
```
php composer.phar install
```
That command will install Harvester along with all its dependencies.
Now, in order to use libraries, you have to include autoloader, which is located in `vendor/autoload.php`.

```php
<?php
require __DIR__.'/vendor/autoload.php';
```

Client
------

Client is an object required in every Harvester module. How to create it?
```php
<?php
require __DIR__.'/vendor/autoload.php';

use Erpk\Harvester\Client\ClientBuilder;

$builder = new ClientBuilder();
$builder->setEmail('your_erepublik@email.com');
$builder->setPassword('your_erepublik_password');

$client = $builder->getClient();
```

Proxy
-----
Sometimes you need use Harvester with proxy. Here is easy solution to do that.
```php
use Erpk\Harvester\Client\Proxy\HttpProxy;
// Create new HttpProxy object
$proxy = new HttpProxy('59.47.43.90', 8080);
// Make client using that proxy
$builder->setProxy($proxy);

$client = $builder->getClient();
```

3rd party modules
----------------
* [scyzoryck/reaper](https://github.com/scyzoryck/reaper)

Original modules
-------
Following examples assume you have already set up your Client and included autoloader.

###Citizen
```php
use Erpk\Harvester\Module\Citizen\CitizenModule;
// assumes you have your Client object already set up
$module = new CitizenModule($client);

// Get citizen profile
$citizen = $module->getProfile(2020512);
echo $citizen['name']; // Romper

// Search for citizens by name
$results = $module->search('Romp', 1); // page 1
print_r($results);
```
###Military
```php
use Erpk\Harvester\Module\Military\MilitaryModule;
$module = new MilitaryModule($client);

// Get list of active campaigns
$active = $module->getActiveCampaigns();
$countryId = 35;
$cotdCampaign = $active->findCampaignOfTheDay($countryId);
$countryCampaigns = $active->findCountryCampaigns($countryId);
$alliesCampaigns = $active->findAlliesCampaigns($countryId);

// Get information about Military Unit
$unit = $module->getUnit(5);
// Get information about regiment in Military Unit
$regiment = $module->getRegiment(5, 1);

// Choose weapon Q7 in particular campaign
$module->changeWeapon($campaignId, 7);
// Make single kill in campaign at selected side
$module->fight($campaignId, $sideCountryId);

// Check Daily Order status
$doStatus = $module->getDailyOrderStatus();
// ...then get reward if completed
if ($doStatus['do_reward_on'] == true) {
    $module->getDailyOrderReward($doStatus['do_mission_id'], $doStatus['groupId']);
}
```

###Exchange
```php
use Erpk\Harvester\Module\Exchange\ExchangeModule;
$module = new ExchangeModule($client);

// Purchase currenc, sell gold, page 1
$response = $module->scan(ExchangeModule::CURRENCY, 1);
// Purchase gold, sell currency, page 20
$response = $module->scan(ExchangeModule::GOLD, 20);

// Access citizen gold and currency amounts
$gold = $response->getGoldAmount();
$cc = $response->getCurrencyAmount();

// Get paginator
$paginator = $offers->getPaginator();
echo $paginator->getCurrentPage(); // Display current page number
echo $paginator->getLastPage(); // Display last page number

// Buy offer
$offer = $response->getOffers()[0];
$amountToBuy = 0.05;
$response = $module->buy($offer, $amountToBuy);
```

###JobMarket
```php
use Erpk\Harvester\Module\JobMarket\JobMarketModule;
$module = new JobMarketModule($client);

// Job offers in Poland, page 1
$countryId = 35;
$page = 1;
$offers = $module->scan($countryId, $page);
```

###Market
```php
use Erpk\Harvester\Module\Market\MarketModule;

$countryId = 35; // Poland
$industryId = 2; // Weapons
$quality = 7; // Q7

$module = new MarketModule($client);
$result = $module->scan($countryId, $industryId, $quality);

// Buy 15 weapons of Q7 quality.
$response = $module->buy($result['offers'][0]['id'], 15);
```

###Country
```php
use Erpk\Harvester\Module\Country\CountryModule;
$module = new CountryModule($client);

// Get Country entity instance
use Erpk\Common\EntityManager;
$em = EntityManager::getInstance();
$countries = $em->getRepository(Country::class);
$poland = $countries->findOneByCode('PL');

// Get country's society data
$society = $module->getSociety($poland);

// Get country's economic data
$eco = $module->getEconomy($poland);

// Get list of online citizens (page 3)
$onlineCitizens = $module->getOnlineCitizens($poland, 3);
```

###Management
```php
use Erpk\Harvester\Module\Management\ManagementModule;
$module = new ManagementModule($client);

// Refill energy
$module->eat();
// Get items in inventory
$module->getInventory();
// Train in all (four) training grounds
$module->train(true, true, true, true);
// Work as employee
$module->workAsEmployee();

// Get owned companies
use Erpk\Harvester\Module\Management\Company;

$companies = $module->getCompanies(); // Returns CompanyCollection object
$companies->filter(function (Company $company) {
    // Filter out all companies where you've already worked as Manager
    // and which are not raw companies
    return $company->hasAlreadyWorked() === false
        && $company->isRaw() === true; 
});

foreach ($companies as $company) { // Iterate filtered Companies
    echo $company->getId(); // Display company ID
}

$companies->reset(); // Resets previously added filters

// Work as manager
use Erpk\Harvester\Module\Management\WorkQueue;

$queue = new WorkQueue;
foreach ($companies as $company) { // Iterate previously filtered CompanyCollection
    $queue->add($company, true, 0); // Work in company as Manager without employees assigned
}
$module->workAsManager($queue);

// Get rewards for daily tasks
$module->getDailyTasksReward();
// Send private message to citizen with ID 123456
$module->sendMessage(
    123456,
    'Subject of message',
    'Content of message'
);
```

###Media
```php
use Erpk\Harvester\Module\Media\PressModule;
use Erpk\Harvester\Module\Media\Article;
use Erpk\Harvester\Module\Media\Category;

$press = new PressModule($client);

// Create new article
$articleId = $press->publishArticle(
    'Test article',
    'Article body',
    35, // Country ID - Poland
    Category::FIRST_STEPS
);

// Edit existing article
$press->editArticle(
    $articleId,
    'Test article 2',
    'Another body',
    Category::BATTLE_ORDERS
);

// Remove article
$press->deleteArticle($articleId);
```
