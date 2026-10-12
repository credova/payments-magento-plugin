<?php

namespace Magento\Sales\Api;

interface TransactionRepositoryInterface
{
    public function get($id);
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);
}