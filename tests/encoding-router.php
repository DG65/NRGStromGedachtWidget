<?php

// Router für den lokalen Testserver von tests/smoke.php (php -S): liefert dieselbe JSON-Antwort
// unkomprimiert, als gzip und als (unlesbare) Brotli-Antwort - so wie die StromGedacht-API
// gelegentlich antwortet - und gibt den gesendeten Accept-Encoding-Header zurück.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$json = '{"state":1}';

switch ($path) {
    case '/br':
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Encoding: br');
        echo "\x0b\x05\x80" . $json . "\x03";
        break;
    case '/gzip':
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Encoding: gzip');
        echo gzencode($json);
        break;
    case '/echo-encoding':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['acceptEncoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null]);
        break;
    default:
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
}
