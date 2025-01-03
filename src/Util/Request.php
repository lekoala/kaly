<?php

declare(strict_types=1);

namespace Kaly\Util;

use Exception;

class Request
{
    /**
     * @param string $url
     * @param string $method
     * @param array<int,string>|array<string,string> $headers
     * @param string|array<mixed>|null $data
     * @param array<mixed> $options
     * @param bool $json
     * @return string
     */
    public static function make(
        string $url,
        string $method = "GET",
        array $headers = [],
        string|array|null $data = null,
        array $options = [],
        bool $json = false
    ): string {
        $ch = curl_init();
        $method = strtoupper($method);

        if (is_array($data) && !empty($data)) {
            if ($method === "GET") {
                $url .= '?' . http_build_query($data);
            } elseif ($json) {
                $data = Json::encode($data);
            } else {
                $data = http_build_query($data);
            }
        }

        // Headers
        $headers = array_map(function ($k, $v): string {
            if (is_int($k)) {
                return $v;
            }
            return "$k: $v";
        }, array_keys($headers), array_values($headers));
        if ($json && !in_array('Content-Type: application/json', $headers)) {
            $headers[] = "Content-Type: application/json";
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        assert(strlen($url) > 0);
        curl_setopt($ch, CURLOPT_URL, $url);

        // Any of the valid options https://www.php.net/manual/en/function.curl-setopt.php
        foreach ($options as $k => $v) {
            curl_setopt($ch, $k, $v);
        }

        // This fixes ca cert issues if server is not configured properly
        $cainfo = ini_get('curl.cainfo');
        if ($cainfo !== false) {
            if (strlen($cainfo) === 0 && class_exists(\Composer\CaBundle\CaBundle::class)) {
                $path = \Composer\CaBundle\CaBundle::getBundledCaBundlePath();
                assert(strlen($path) > 0);
                curl_setopt($ch, CURLOPT_CAINFO, $path);
            } else {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            }
        }

        $hasData = $data !== null;

        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($hasData) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                if ($hasData) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
        }

        $result = curl_exec($ch);

        if (is_bool($result)) {
            throw new Exception('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
        }

        curl_close($ch);

        return $result;
    }
}
