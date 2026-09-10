#!/usr/bin/env php
<?php

declare(strict_types=1);

$host = getenv('HEALTH_HOST') ?: 'web';
$port = (int) (getenv('HEALTH_PORT') ?: '80');
$path = getenv('CONSUL_HEALTH_PATH') ?: '/api/v1/health';

$fp = @fsockopen($host, $port, $errno, $errstr, 2);
if ($fp === false) {
    exit(1);
}

$request = "GET {$path} HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
fwrite($fp, $request);
$response = (string) stream_get_contents($fp);
fclose($fp);

$up = str_contains($response, '"status":"UP"') || str_contains($response, '"status": "UP"');

exit($up ? 0 : 1);
