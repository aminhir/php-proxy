<?php
// proxy.php – diagnostic version
header('Content-Type: application/json');

if (!isset($_GET['url'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing "url" parameter']);
    exit;
}

$url = $_GET['url'];
$method = $_SERVER['REQUEST_METHOD'];
$headers = getallheaders();

// Remove Hop‑by‑hop headers and internal headers
$skipHeaders = [
    'Host', 'Connection', 'Keep-Alive', 'Proxy-Authenticate',
    'Proxy-Authorization', 'TE', 'Trailers', 'Transfer-Encoding',
    'Upgrade', 'Content-Length', 'Content-Type'
];

$curlHeaders = [];
foreach ($headers as $key => $value) {
    if (in_array($key, $skipHeaders, true)) continue;
    $curlHeaders[] = "$key: $value";
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

// Capture response headers
$responseHeaders = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION,
    function($curl, $headerLine) use (&$responseHeaders) {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) < 2) return $len;
        $responseHeaders[trim($parts[0])] = trim($parts[1]);
        return $len;
    }
);

// If it's a POST/PUT/PATCH, forward the body
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $input = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
}

$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0) {
    // Diagnostic output: include cURL error and attempted URL
    http_response_code(502);
    echo json_encode([
        'error' => 'cURL request failed',
        'curl_errno' => $errno,
        'curl_error' => $error,
        'attempted_url' => $url,
        'note' => 'This may indicate outbound network is blocked by PaaS.'
    ]);
    exit;
}

// Forward the remote status code and headers
http_response_code($httpCode);
foreach ($responseHeaders as $name => $value) {
    header("$name: $value");
}
echo $response;
