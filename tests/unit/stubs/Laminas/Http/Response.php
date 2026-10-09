<?php

namespace Laminas\Http;

class Response
{
    private string $content = '';

    public function setContent($content) { $this->content = (string) $content; return $this; }
    public function getBody() { return $this->content; }
}
