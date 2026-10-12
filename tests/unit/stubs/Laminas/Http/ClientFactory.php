<?php

namespace Laminas\Http;

// Magento generates this factory. Tests mock create() to return a fake client.
class ClientFactory
{
    public function create(array $data = [])
    {
        return new Client();
    }
}
