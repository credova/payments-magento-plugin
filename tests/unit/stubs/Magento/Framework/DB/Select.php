<?php

namespace Magento\Framework\DB;

class Select
{
    public function from($name, $cols = '*', $schema = null): self
    {
        return $this;
    }

    public function join($name, $cond, $cols = '*', $schema = null): self
    {
        return $this;
    }

    public function where($cond, $value = null, $type = null): self
    {
        return $this;
    }
}
