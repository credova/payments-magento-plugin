<?php

namespace Magento\Sales\Api\Data;

interface OrderInterface
{

    function getId(): ?int;
    function getPayment() ;
    function getIncrementId();
}