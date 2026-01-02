<?php
/**
 * Simple Secure REST Proxy
 * -----------------------------------------------------
 * This script forwards HTTP requests (GET, POST, PUT, DELETE)
 * to a target URL passed as a query parameter (?url=...).
 * It preserves JSON bodies and headers, adds timeout control,
 * and limits access to safe destinations.
 *
 * Usage example:
 *  curl --request POST \
 *   --url 'https://yourdomain.com/proxy.php?url=https%3A%2F%2Fapi.example.com%2Fendpoint' \
 *   --header 'Content-Type: application/json' \
 *   --data '{"key":"value"}'
 */

// --- CONFIGURATION ---
$ALLOWED_DOMAINS = [
    'pbx-panel.pishgaman.net',
    'ms-pay.aminh.pro',
    // add more allowed domains here for safety
];

$TIMEOUT = 20; // seconds for cURL timeout

// --- HELPER: safe URL extraction ---
$url = $_GET['url'] ?? $_POST['url'] ?? null;

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => "Missing 'url' parameter."]);
    exit;
}

// validate and sanitize url
$decodedUrl = urldecode($url);
$host = parse_url($decodedUrl, PHP_URL_HOST);

if (!$host || !in_array($host, $ALLOWED_DOMAINS, true)) {
    http_response_code(403);
    echo json_encode(['error' => "Access to this domain is not permitted."]);
    exit;
}

// --- determine HTTP method ---
$method = strtoupper($_SERVER['REQUEST_METHOD']);

// --- read body if present ---
$body = file_get_contents('php://input');

// --- init CURL ---
$ch = curl_init($decodedUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_TIMEOUT => $TIMEOUT,
    CURLOPT_USERAGENT => 'PHP Proxy/1.1',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HEADER => true, // to capture headers
]);

// --- set headers from client ---
$client_headers = getallheaders();
$curl_headers = [];

foreach ($client_headers as $key => $value) {
    // skip host, content-length — cURL handles those automatically
    if (in_array(strtolower($key), ['host', 'content-length'])) continue;
    $curl_headers[] = "$key: $value";
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);

// attach body for POST/PUT/PATCH
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

// --- execute request ---
$response = curl_exec($ch);
if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

// --- separate headers and body ---
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headers     = substr($response, 0, $header_size);
$body        = substr($response, $header_size);

curl_close($ch);

// --- forward response ---
http_response_code($http_code);

// forward key headers to client (e.g., content-type)
foreach (explode("\r\n", $headers) as $header) {
    if (stripos($header, 'Content-Type:') === 0 ||
        stripos($header, 'Cache-Control:') === 0 ||
        stripos($header, 'Pragma:') === 0
    ) {
        header($header);
    }
}

echo $body;
