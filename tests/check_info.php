<?php
require_once __DIR__ . '/../config.php';

function get_local_ip_addresses() {
    $ips = [];
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            $ips[$host] = "የአሁኑ አድራሻ ($host)";
        }
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = @shell_exec('ipconfig');
        if ($out) {
            preg_match_all('/(?:IPv4 Address|IPv4-Adresse|አድራሻ)[ .:]*([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/i', $out, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $ip) {
                    $ip = trim($ip);
                    if ($ip && $ip !== '127.0.0.1' && !isset($ips[$ip])) {
                        $ips[$ip] = $ip;
                    }
                }
            }
        }
    }
    return $ips;
}

print_r(get_local_ip_addresses());
