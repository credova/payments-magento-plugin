<?php

namespace Magento\Framework\DB\Adapter;

use Magento\Framework\DB\Select;

interface AdapterInterface
{
    public function select(): Select;

    public function fetchAll($sql, $bind = [], $fetchMode = null);

    public function fetchOne($sql, $bind = []);

    public function insertOnDuplicate($table, array $data, array $fields = []);

    public function insertArray($table, array $columns, array $data, $strategy = 0);

    public function delete($table, $where = '');
}
