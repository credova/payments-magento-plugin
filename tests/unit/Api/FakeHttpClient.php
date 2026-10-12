<?php

namespace PublicSquare\Payments\Test\Unit\Api;

use Laminas\Http\Client;
use Laminas\Http\Response;

/** Records the request an API class builds, and answers with a canned PublicSquare response body. */
class FakeHttpClient extends Client
{
    public ?string $uri = null;
    public ?string $method = null;
    public array $headers = [];
    public ?string $body = null;
    public int $sendCount = 0;

    public function __construct(private string $responseBody = '{"status":"succeeded"}') {}

    public function setUri($uri) { $this->uri = (string) $uri; return $this; }
    public function setMethod($method) { $this->method = $method; return $this; }
    public function setHeaders($headers) { $this->headers = $headers; return $this; }
    public function setRawBody($body) { $this->body = $body; return $this; }

    public function send($request = null)
    {
        $this->sendCount++;
        return (new Response())->setContent($this->responseBody);
    }

    /** The JSON body as an array, so a test can check value types as PublicSquare decodes them. */
    public function json(): array
    {
        return json_decode($this->body, true);
    }
}
