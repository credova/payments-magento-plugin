<?php

namespace Magento;

class Phrase
{
    private string $text;
    private array $arguments;

    public function __construct($text, array $arguments = [])
    {
        $this->text = (string) $text;
        $this->arguments = $arguments;
    }

    public function render(): string
    {
        $text = $this->text;
        foreach ($this->arguments as $key => $value) {
            $text = str_replace('%' . (is_int($key) ? $key + 1 : $key), (string) $value, $text);
        }
        return $text;
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
