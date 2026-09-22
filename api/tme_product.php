<?php
/**
 * TME API v2 - Recupero dati prodotto
 *
 * Requisiti:
 * - PHP 8+
 * - estensione cURL
 * - credenziali API TME v2: TOKEN e SECRET
 *
 * Utilizzo:
 *   Browser:
 *     tme_product.php?symbol=NE555D
 *
 *   CLI:
 *     php tme_product.php NE555D
 *
 * La API v2 utilizza OAuth 2.0.
 * Il token di accesso viene richiesto tramite /auth/token
 * e successivamente utilizzato come Bearer token.
 */

declare(strict_types=1);

// ============================================================
// CONFIGURAZIONE
// ============================================================

const TME_API_BASE = 'https://api.tme.eu';
const TME_TOKEN    = '6b6796b8d88cd92b86c56281911f77343f9953a0a939cfd419';
const TME_SECRET   = 'db7c19b8cc1bdea2c287';

const TME_COUNTRY  = 'IT';
const TME_LANGUAGE = 'it';
const TME_CURRENCY = 'EUR';

// ============================================================
// FUNZIONI
// ============================================================

/**
 * Esegue una richiesta HTTP con cURL.
 */
function tmeHttpRequest(
    string $method,
    string $url,
    array $headers = [],
    ?array $postFields = null
): array {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Errore cURL: ' . $error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            "Risposta non JSON da TME. HTTP $httpCode\n$response"
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $data['message'] ?? $data['error'] ?? 'Errore API TME';
        throw new RuntimeException(
            "TME HTTP $httpCode: $message"
        );
    }

    return $data;
}

/**
 * Ottiene un access token OAuth 2.0 da TME.
 */
function tmeGetAccessToken(): string
{
    if (
        TME_TOKEN === 'INSERISCI_IL_TUO_TOKEN' ||
        TME_SECRET === 'INSERISCI_IL_TUO_SECRET'
    ) {
        throw new RuntimeException(
            'Inserire TME_TOKEN e TME_SECRET nel file PHP.'
        );
    }

    $basicAuth = base64_encode(TME_TOKEN . ':' . TME_SECRET);
echo $basicAuth;
    $response = tmeHttpRequest(
        'POST',
        TME_API_BASE . '/auth/token',
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $basicAuth,
            'Accept: application/json',
        ],
        [
            'grant_type' => 'client_credentials',
        ]
    );
	
	echo $response;

    if (empty($response['access_token'])) {
        throw new RuntimeException(
            'TME non ha restituito access_token.'
        );
    }

    return (string) $response['access_token'];
}

/**
 * Esegue una richiesta autenticata alla API TME v2.
 */
function tmeApiGet(
    string $endpoint,
    array $query,
    string $accessToken
): array {
    $url = TME_API_BASE . $endpoint . '?' . http_build_query(
        $query,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    return tmeHttpRequest(
        'GET',
        $url,
        [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Accept-Language: ' . TME_LANGUAGE,
        ]
    );
}

/**
 * Recupera i dati anagrafici del prodotto.
 */
function tmeGetProduct(string $symbol, string $accessToken): array
{
    return tmeApiGet(
        '/products',
        [
            'country'   => TME_COUNTRY,
            'symbols[]' => $symbol,
        ],
        $accessToken
    );
}

/**
 * Recupera prezzi e disponibilità.
 */
function tmeGetProductData(string $symbol, string $accessToken): array
{
    return tmeApiGet(
        '/products/data',
        [
            'country'   => TME_COUNTRY,
            'currency'  => TME_CURRENCY,
            'scope[]'   => ['prices', 'stock'],
            'symbols[]' => $symbol,
        ],
        $accessToken
    );
}

/**
 * Recupera i parametri tecnici del prodotto.
 */
function tmeGetProductParameters(string $symbol, string $accessToken): array
{
    return tmeApiGet(
        '/products/parameters',
        [
            'country'   => TME_COUNTRY,
            'symbols[]' => $symbol,
        ],
        $accessToken
    );
}

// ============================================================
// PROGRAMMA PRINCIPALE
// ============================================================

try {
    // Simbolo passato:
    // - da CLI: php tme_product.php NE555D
    // - da browser: tme_product.php?symbol=NE555D
    if (PHP_SAPI === 'cli') {
        $symbol = $argv[1] ?? '';
    } else {
        $symbol = $_GET['symbol'] ?? '';
    }

    $symbol = trim((string) $symbol);
	
	echo $symbol;

    if ($symbol === '') {
        throw new InvalidArgumentException(
            'Specificare il codice prodotto TME. Esempio: ?symbol=NE555D'
        );
    }

    // 1. Autenticazione OAuth 2.0
	echo "cippa";
    $accessToken = tmeGetAccessToken();
echo $accessToken;
    // 2. Dati anagrafici prodotto
    $product = tmeGetProduct($symbol, $accessToken);

    // 3. Prezzo e disponibilità
    $commercial = tmeGetProductData($symbol, $accessToken);

    // 4. Parametri tecnici
    $parameters = tmeGetProductParameters($symbol, $accessToken);

    $result = [
        'symbol'     => $symbol,
        'product'    => $product['data']['elements'] ?? [],
        'commercial' => $commercial['data']['elements'] ?? [],
        'parameters' => $parameters['data']['elements'] ?? [],
    ];

    // Output JSON leggibile.
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $e) {

    http_response_code(500);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        [
            'status'  => 'ERROR',
            'message' => $e->getMessage(),
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    );
}
?>
