<?php

$proxy = new Proxy();
$proxy->processRequest();

class Proxy
{
    protected $config;

    public function __construct()
    {
        $config = parse_ini_file('config.ini', true);

        $requestDomain = $_SERVER['HTTP_HOST'];

        foreach ($config as $domain => $domainConfig) {
            if (strpos($requestDomain, $domain) !== false) {
                $this->config = $domainConfig;
                $this->config['ourDomain'] = $domain;
                $this->config['domainPrefix'] = str_replace($domain, '', $requestDomain);

                return;
            }
        }

        echo 'No such domain';
        die();
    }

    public function processRequest()
    {
        $curlSession = curl_init();

        $url = 'http' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 's' : '') . '://' . $this->config['domainPrefix'] . $this->config['remoteDomain'] . $_SERVER['REQUEST_URI'];
        curl_setopt($curlSession, CURLOPT_URL, $url);
        curl_setopt($curlSession, CURLOPT_HEADER, 1);
        curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlSession, CURLOPT_TIMEOUT, 30);

        $userHeaders = getallheaders();
        $curlHeaders = [];
        foreach ($userHeaders as $key => $value) {
            if ($key == 'Accept-Encoding') {
                continue;
            }
            $value = $this->replaceOurDomain($value);
            $curlHeaders[] = $key . ': ' . $value;
        }
        curl_setopt($curlSession, CURLOPT_HTTPHEADER, $curlHeaders);

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $post = $_POST;
            foreach ($post as $key => &$value) {
                $value = $this->replaceOurDomain($value);
            }
            curl_setopt($curlSession, CURLOPT_POST, 1);
            curl_setopt($curlSession, CURLOPT_POSTFIELDS, $_POST);
        }
        if (!empty($_COOKIE)) {
            $cookies = '';

            foreach ($_COOKIE as $key => $value) {
                $cookies .= $key . '=' . $value . '; ';
            }

            curl_setopt($curlSession, CURLOPT_COOKIE, $cookies);
        }

        $response = curl_exec($curlSession);


        if (curl_error($curlSession)) {
            die('Error. Please try again later.');
        }

        curl_close($curlSession);


        $response = $this->replaceRemoteDomain($response);


        list($headers, $body) = explode("\r\n\r\n", $response, 2);

        $headers = explode("\n", $headers);

        foreach ($headers as $header) {
            if ($header = trim($header)) {
                list($name) = explode(':', $header, 2);
                if (in_array($name, ['Transfer-Encoding'])) {
                    continue;
                }
                header($header);
            }
        }

        $body = $this->postProcessHtml($body);

        echo $body;
    }

    public function replaceRemoteDomain($contents)
    {
        $contents = str_replace($this->config['remoteDomain'], $this->config['ourDomain'], $contents);

        return $contents;
    }


    public function replaceOurDomain($contents)
    {
        $contents = str_replace($this->config['ourDomain'], $this->config['remoteDomain'], $contents);

        return $contents;
    }

    public function postProcessHtml($html)
    {
        $html = $this->doReplacements($html);

        return $html;
    }

    public function doReplacements($html)
    {
        if (empty($this->config['htmlSearch'])) {
            return $html;
        }

        $html = preg_replace($this->config['htmlSearch'], $this->config['htmlReplace'], $html);

        return $html;
    }
}
