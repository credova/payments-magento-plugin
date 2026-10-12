<?php

namespace Magento\Payment\Gateway\Data;

interface PaymentDataObjectInterface
{
    public function getOrder();
    public function getPayment();
}
