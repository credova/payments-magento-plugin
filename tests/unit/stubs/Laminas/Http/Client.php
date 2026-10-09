<?php

namespace Laminas\Http;

// The Laminas client methods the API requests call. Each setter returns the client, as Laminas does.
class Client
{
    public function setUri($uri) { return $this; }
    public function setOptions($options = []) { return $this; }
    public function setMethod($method) { return $this; }
    public function setHeaders($headers) { return $this; }
    public function setRawBody($body) { return $this; }
    public function send($request = null) { return new Response(); }
}
