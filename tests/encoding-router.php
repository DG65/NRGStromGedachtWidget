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
    case '/br-unless-gzip':
        // hartnäckiger Server: Brotli, solange der Client nicht ausdrücklich gzip anfordert
        header('Content-Type: application/json; charset=utf-8');
        if (stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
            header('Content-Encoding: gzip');
            echo gzencode($json);
        } else {
            header('Content-Encoding: br');
            echo "\x0b\x05\x80" . $json . "\x03";
        }
        break;
    case '/br-burst':
        // Phase wie bei der echten API: ca. eine Sekunde lang Brotli, egal was der Client anfordert,
        // danach normale Antworten. Der Beginn der Phase steht je Abfrage-Schlüssel in einer Datei.
        $file = sys_get_temp_dir() . '/sgw-burst-' . md5($_SERVER['QUERY_STRING'] ?? '');
        $first = is_file($file) ? (float) file_get_contents($file) : null;
        if ($first === null) {
            $first = microtime(true);
            file_put_contents($file, (string) $first);
        }
        header('Content-Type: application/json; charset=utf-8');
        if (microtime(true) - $first < 1.0) {
            header('Content-Encoding: br');
            echo "\x0b\x05\x80" . $json . "\x03";
        } else {
            @unlink($file);
            echo $json;
        }
        break;
    case '/echo-encoding':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['acceptEncoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null]);
        break;
    default:
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
}
