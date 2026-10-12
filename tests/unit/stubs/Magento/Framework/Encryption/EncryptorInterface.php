<?php

namespace Magento\Framework\Encryption;


interface EncryptorInterface
{

    function hash(string $val): string;
    function getHash($password, $salt = false);
    function encrypt($val);
    function decrypt($val);
}