<?php

namespace Magento\Payment\Gateway;

interface CommandInterface
{
    public function execute(array $commandSubject);
}
