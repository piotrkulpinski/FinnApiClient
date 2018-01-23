<?php

namespace Finn\RestClient;

class CurlClient implements ClientInterface
{
    private $ch;
    private $curlOpts = array();
    private $settings = array(
        'header'        => array(),
        'userAgent'     => '',
    );

    public function __construct($settings)
    {
        $this->settings = array_merge($this->settings, $settings);
    }

    private function setOpts($data = null)
    {
        $opts = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT => $this->settings['userAgent'],
            CURLOPT_FRESH_CONNECT => 1,
        );

        $this->curlOpts = $opts;
    }

    private function close()
    {
        curl_close($this->ch);
    }

    public function setHeaders($headers)
    {
        $this->settings['header'] = array_merge($this->settings['header'], $headers);
    }

    public function send($url, $data = null)
    {
        $this->ch = curl_init();
        $this->setOpts($data);

        curl_setopt_array($this->ch, $this->curlOpts);

        curl_setopt($this->ch, CURLOPT_URL, utf8_decode($url));
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->settings['header']);

        $rawData = curl_exec($this->ch);
        $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

        if ($httpcode == 200) {
            return $rawData;
        } else {
            return null;
        }
    }
}
