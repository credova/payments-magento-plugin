<?php

// Same contract as Magento's app/functions.php.
function __(...$argc)
{
    $text = array_shift($argc);
    if (!empty($argc) && is_array($argc[0])) {
        $argc = $argc[0];
    }
    return new \Magento\Phrase($text, $argc);
}
