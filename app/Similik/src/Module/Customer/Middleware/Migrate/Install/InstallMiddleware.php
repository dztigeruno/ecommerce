<?php
/**
 * Copyright © Nguyen Huu The <thenguyen.dev@gmail.com>.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Similik\Module\Customer\Middleware\Migrate\Install;


use Similik\Middleware\MiddlewareAbstract;
use Similik\Services\Db\Processor;
use Similik\Services\Http\Request;
use Similik\Services\Http\Response;

class InstallMiddleware extends MiddlewareAbstract
{
    /**@var Processor $processor*/
    protected $processor;

    public function __invoke(Request $request, Response $response)
    {
        $this->processor = new Processor();
        $customerTable = $this->processor->executeQuery("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = :dbName AND TABLE_NAME = \"customer\" LIMIT 0,1", ['dbName'=> DB_DATABASE])->fetch(\PDO::FETCH_ASSOC);
        if($customerTable !== false) {
            $response->addData('success', 0)->addData('message', 'Installed');
            return $response;
        }

        $this->processor->startTransaction();
        try {
            //Create customer_group table
            $this->processor->executeQuery("CREATE TABLE `customer_group` (
              `customer_group_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `group_name` char(255) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`customer_group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Customer group'");

            $this->processor->getTable('customer_group')->insert(['group_name'=> 'General']);

            //Create customer table
            $this->processor->executeQuery("CREATE TABLE `customer` (
              `customer_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `status` smallint(6) NOT NULL DEFAULT '1',
              `group_id` int(10) unsigned DEFAULT NULL,
              `email` char(255) NOT NULL,
              `password` char(255) NOT NULL,
              `full_name` char(255) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`customer_id`),
              UNIQUE KEY `EMAIL_UNIQUE` (`email`),
              KEY `FK_CUSTOMER_GROUP` (`group_id`),
              CONSTRAINT `FK_CUSTOMER_GROUP` FOREIGN KEY (`group_id`) REFERENCES `customer_group` (`customer_group_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Customer'");

            //Create customer_address table
            $this->processor->executeQuery("CREATE TABLE `customer_address` (
              `customer_address_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `customer_id` int(10) unsigned NOT NULL,
              `full_name` varchar(255) DEFAULT NULL,
              `telephone` varchar(255) DEFAULT NULL,
              `address_1` varchar(255) DEFAULT NULL,
              `address_2` varchar(255) DEFAULT NULL,
              `postcode` varchar(255) DEFAULT NULL,
              `city` varchar(255) DEFAULT NULL,
              `province` varchar(255) DEFAULT NULL,
              `country` varchar(255) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `is_default` int(10) unsigned DEFAULT NULL,
              PRIMARY KEY (`customer_address_id`),
              KEY `FK_CUSTOMER_ADDRESS_LINK` (`customer_id`),
              CONSTRAINT `FK_CUSTOMER_ADDRESS_LINK` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Customer address'");

            $this->processor->commit();
            $response->addData('success', 1)->addData('message', 'Done');
        } catch (\Exception $e) {
            $this->processor->rollback();
            $response->addData('success', 0)->addData('message', $e->getMessage());
        }

        return $response;
    }
}