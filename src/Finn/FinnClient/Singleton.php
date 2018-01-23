<?php

namespace Finn\FinnClient;

class Singleton
{
    public function __get($prop)
    {
        if (isset($this->$prop)) {
            return $this->$prop;
        } else {
            return null;
        }
    }
    
    public function __set($prop, $val)
    {
        $this->$prop = $val;
    }
}