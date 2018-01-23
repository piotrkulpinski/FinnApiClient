<?php

namespace Finn\RestClient;

interface ClientInterface {
    public function setHeaders($headers);
    public function send($url, $data);
}
