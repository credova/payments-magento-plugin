<?php

namespace Magento\Framework\App;

use Magento\Framework\DB\Adapter\AdapterInterface;

class ResourceConnection
{
    public function getConnection($resourceName = 'default'): AdapterInterface
    {
        throw new \LogicException('Mock this method.');
    }

    public function getTableName($modelEntity, $connectionName = 'default'): string
    {
        return (string) $modelEntity;
    }
}
