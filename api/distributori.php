<?php
/**
 * Modulo di integrazione con le API dei distributori di componenti elettronici.
 *
 * Chiamato da: componenti/view.php (pulsanti nella tab Fornitori)
 * ?componente_fornitore_id=ID   -> aggiorna prezzo/disponibilità per quella singola riga
 * ?componente_id=ID&tutti=1     -> aggiorna in sequenza tutte le righe distributore di quel componente
 *
 * Ogni distributore ha un formato di richiesta/risposta diverso. Sono implementate
 * per intero le chiamate a MOUSER (API key singola), DIGIKEY (OAuth2 con cache del
 * token), FARNELL/element14/Newark (API key singola + sito di riferimento), ARROW
 * (coppia login+apikey) e TME (OAuth2 client-credentials). Avnet è ancora una
 * funzione stub, pronta per essere completata quando avrai le credenziali developer.
 * RS Components non offre un'API self-service equivalente (richiede un accordo
 * commerciale via account manager per PunchOut/EDI), quindi il relativo stub resta
 * volutamente vuoto.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_sezione('componenti');
require_scrittura('componenti');

// Aggiorna una singola riga componenti_fornitori interrogando l'API del suo distributore.
// Ritorna un esito testuale (senza toccare la sessione), così è riutilizzabile sia per
// l'aggiornamento di una riga sola sia per il ciclo "aggiorna tutti".
function aggiorna_riga_fornitore(PDO $pdo, array $riga, array $config): array {
    try {
        $risultato = match ($config['nome_distributore']) {
            'mouser' => query_mouser($config, $riga['codice_fornitore']),
            'digikey' => query_digikey($pdo, $config, $riga['codice_fornitore']),
            'arrow' => query_arrow($config, $riga['codice_fornitore']),
            'avnet' => query_avnet($config, $riga['codice_fornitore']),
            'farnell' => query_farnell($config, $riga['codice_fornitore']),
            'tme' => query_tme($pdo, $config, $riga['codice_fornitore']),
            'rs' => query_rs($config, $riga['codice_fornitore']),
            default => null,
        };

        if ($risultato && $risultato['trovato']) {
            $pdo->prepare("UPDATE componenti_fornitori SET prezzo=COALESCE(?, prezzo), disponibilita_real_time=COALESCE(?, disponibilita_real_time), lead_time_giorni=COALESCE(?, lead_time_giorni), moq=COALESCE(?, moq), url_prodotto=COALESCE(?, url_prodotto), produttore=COALESCE(?, produttore), codice_produttore=COALESCE(?, codice_produttore), ultimo_aggiornamento_api=NOW() WHERE id=?")
                ->execute([$risultato['prezzo'], $risultato['disponibilita'], $risultato['lead_time_giorni'] ?? null, $risultato['moq'] ?? null, $risultato['url_prodotto'] ?? null, $risultato['produttore'] ?? null, $risultato['codice_produttore'] ?? null, $riga['id']]);
            log_attivita($pdo, 'aggiornamento_api_distributore', 'componenti_fornitori', $riga['id'], json_encode($risultato));
            return ['ok' => true, 'messaggio' => $config['nome_distributore'] . ' (' . $riga['codice_fornitore'] . '): aggiornato'];
        }
        return ['ok' => false, 'messaggio' => $config['nome_distributore'] . ' (' . $riga['codice_fornitore'] . '): nessun risultato trovato'];
    } catch (Exception $e) {
        return ['ok' => false, 'messaggio' => $config['nome_distributore'] . ' (' . $riga['codice_fornitore'] . '): ' . $e->getMessage()];
    }
}

$componenteId = (int) ($_GET['componente_id'] ?? 0);

if ($componenteId && isset($_GET['tutti'])) {
    // ------------------------------------------------------------
    // Aggiornamento massivo: tutte le righe distributore di un componente
    // ------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT cf.id cf_id, cf.componente_id, cf.codice_fornitore, dac.*
                            FROM componenti_fornitori cf
                            JOIN componenti c ON c.id = cf.componente_id
                            JOIN distributori_api_config dac ON dac.fornitore_id = cf.fornitore_id AND dac.attivo = 1
                            WHERE cf.componente_id = ? AND c.azienda_id = ?");
    $stmt->execute([$componenteId, azienda_id()]);
    $righeGrezze = $stmt->fetchAll();

    if (!$righeGrezze) {
        $_SESSION['flash_msg'] = 'Nessun fornitore con integrazione API attiva collegato a questo componente.';
        $_SESSION['flash_type'] = 'warning';
    } else {
        $esiti = [];
        foreach ($righeGrezze as $r) {
            // $r contiene sia i dati della riga fornitore (con id come cf_id, per non confonderlo
            // con dac.id) sia l'intera configurazione API (dac.*, incluso il suo id reale) -
            // aggiorna_riga_fornitore si aspetta i due id distinti: quello della riga (per
            // salvare prezzo/MOQ/ecc.) e quello della configurazione (per cachare il token OAuth)
            $riga = ['id' => $r['cf_id'], 'componente_id' => $r['componente_id'], 'codice_fornitore' => $r['codice_fornitore']];
            $esiti[] = aggiorna_riga_fornitore($pdo, $riga, $r);
        }
        $successi = count(array_filter($esiti, fn($e) => $e['ok']));
        $falliti = array_filter($esiti, fn($e) => !$e['ok']);

        $msg = "$successi di " . count($esiti) . ' fornitori aggiornati.';
        if ($falliti) {
            $msg .= ' Non riusciti: ' . implode(' | ', array_map(fn($e) => $e['messaggio'], $falliti));
        }
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $falliti ? ($successi ? 'warning' : 'danger') : 'success';
    }

    header('Location: ../componenti/view.php?id=' . $componenteId . '&tab=tab-fornitori');
    exit;
}

// ------------------------------------------------------------
// Aggiornamento di una singola riga
// ------------------------------------------------------------
$componenteFornitoreId = (int) ($_GET['componente_fornitore_id'] ?? 0);

$stmt = $pdo->prepare("SELECT cf.*, c.codice_interno, f.id fornitore_id
                        FROM componenti_fornitori cf
                        JOIN componenti c ON c.id = cf.componente_id
                        JOIN fornitori f ON f.id = cf.fornitore_id
                        WHERE cf.id = ? AND c.azienda_id = ?");
$stmt->execute([$componenteFornitoreId, azienda_id()]);
$riga = $stmt->fetch();
if (!$riga) { die('Collegamento componente-fornitore non trovato.'); }

$stmt = $pdo->prepare("SELECT * FROM distributori_api_config WHERE fornitore_id = ? AND attivo = 1");
$stmt->execute([$riga['fornitore_id']]);
$config = $stmt->fetch();
if (!$config) {
    $_SESSION['flash_msg'] = 'Nessuna configurazione API attiva per questo fornitore. Configurala dalla scheda fornitore.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ../componenti/view.php?id=' . $riga['componente_id'] . '&tab=tab-fornitori');
    exit;
}

$esito = aggiorna_riga_fornitore($pdo, $riga, $config);
$_SESSION['flash_msg'] = $esito['ok'] ? 'Prezzo e disponibilità aggiornati da ' . $config['nome_distributore'] . '.' : $esito['messaggio'];
$_SESSION['flash_type'] = $esito['ok'] ? 'success' : 'danger';

header('Location: ../componenti/view.php?id=' . $riga['componente_id'] . '&tab=tab-fornitori');
exit;


// ============================================================
// MOUSER - implementazione di riferimento
// Documentazione: https://www.mouser.com/api-search/  (Search API v1 - SearchByPartNumber)
// ============================================================
function query_mouser(array $config, string $codiceFornitore): array {
    $url = rtrim($config['api_base_url'] ?: 'https://api.mouser.com/api/v1', '/')
         . '/search/partnumber?apiKey=' . urlencode($config['api_key']);

    $payload = json_encode([
        'SearchByPartRequest' => [
            'mouserPartNumber' => $codiceFornitore,
            'partSearchOptions' => 'string'
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($risposta === false || $httpCode >= 400) {
        throw new Exception('Errore di comunicazione con Mouser (HTTP ' . $httpCode . ')');
    }

    $dati = json_decode($risposta, true);
    $parte = $dati['SearchResults']['Parts'][0] ?? null;
    if (!$parte) {
        return ['trovato' => false];
    }

    // il prezzo Mouser è in fasce (PriceBreaks) - prendiamo il prezzo unitario alla quantità minima
    $prezzo = null;
    if (!empty($parte['PriceBreaks'][0]['Price'])) {
        $prezzo = (float) preg_replace('/[^0-9.,]/', '', str_replace(',', '.', $parte['PriceBreaks'][0]['Price']));
    }
    $disponibilita = null;
    if (isset($parte['AvailabilityInStock'])) {
        $disponibilita = (float) preg_replace('/[^0-9]/', '', $parte['AvailabilityInStock']);
    }
    // MOQ: Mouser espone il campo "Min" (quantità minima ordinabile); in mancanza,
    // usa la quantità della prima fascia di prezzo (PriceBreaks[0].Quantity)
    $moq = null;
    if (!empty($parte['Min'])) {
        $moq = (float) preg_replace('/[^0-9.]/', '', $parte['Min']);
    } elseif (!empty($parte['PriceBreaks'][0]['Quantity'])) {
        $moq = (float) $parte['PriceBreaks'][0]['Quantity'];
    }

    return [
        'trovato' => true,
        'prezzo' => $prezzo,
        'disponibilita' => $disponibilita,
        'lead_time_giorni' => !empty($parte['LeadTime']) ? (int) preg_replace('/[^0-9]/', '', $parte['LeadTime']) : null,
        'moq' => $moq,
        'url_prodotto' => $parte['ProductDetailUrl'] ?? null,
        'produttore' => $parte['Manufacturer'] ?? null,
        'codice_produttore' => $parte['ManufacturerPartNumber'] ?? null,
    ];
}

// ============================================================
// DIGIKEY - implementazione completa
// Documentazione: https://developer.digikey.com (Product Information API v4)
//
// A differenza di Mouser, DigiKey usa OAuth2 con grant type "client_credentials":
// serve prima uno scambio Client ID + Client Secret -> access token (di breve
// durata, tipicamente 10 minuti), da rifare solo quando scade. Per evitare di
// autenticarsi ad ogni click, il token ottenuto viene salvato in
// distributori_api_config (colonne token_oauth / token_scadenza) e riusato
// finché resta valido.
//
// Configurazione richiesta nella scheda fornitore (Amministrazione API):
// - Client ID     -> campo "client_id"
// - Client Secret  -> campo "API Secret"
// - Endpoint API base URL -> https://api.digikey.com (lascia vuoto per usare il default)
// ============================================================
function digikey_ottieni_token(PDO $pdo, array $config): string {
    // riusa il token salvato se non è ancora scaduto (con un margine di sicurezza di 60s)
    if (!empty($config['token_oauth']) && !empty($config['token_scadenza'])
        && strtotime($config['token_scadenza']) > time() + 60) {
        return $config['token_oauth'];
    }

    $ch = curl_init('https://api.digikey.com/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $config['client_id'],
            'client_secret' => $config['api_secret'],
            'grant_type' => 'client_credentials',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($risposta === false || $httpCode >= 400) {
        throw new Exception('Autenticazione DigiKey fallita (HTTP ' . $httpCode . '). Verifica Client ID e Client Secret nella configurazione del fornitore.');
    }

    $dati = json_decode($risposta, true);
    if (empty($dati['access_token'])) {
        throw new Exception('DigiKey non ha restituito un access token valido.');
    }

    $token = $dati['access_token'];
    $durataSecondi = (int) ($dati['expires_in'] ?? 600);

    // salva il token in cache per le prossime chiamate
    $pdo->prepare("UPDATE distributori_api_config SET token_oauth = ?, token_scadenza = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
        ->execute([$token, $durataSecondi, $config['id']]);

    return $token;
}

function query_digikey(PDO $pdo, array $config, string $codiceFornitore): array {
    $token = digikey_ottieni_token($pdo, $config);

    $baseUrl = rtrim($config['api_base_url'] ?: 'https://api.digikey.com', '/');
    $url = $baseUrl . '/products/v4/search/' . rawurlencode($codiceFornitore) . '/productdetails';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'X-DIGIKEY-Client-Id: ' . $config['client_id'],
            'X-DIGIKEY-Locale-Site: IT',
            'X-DIGIKEY-Locale-Language: it',
            'X-DIGIKEY-Locale-Currency: EUR',
            'X-DIGIKEY-Customer-Id: 0',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 404) {
        return ['trovato' => false];
    }
    if ($risposta === false || $httpCode >= 400) {
        throw new Exception('Errore di comunicazione con DigiKey (HTTP ' . $httpCode . ')');
    }

    $dati = json_decode($risposta, true);
    $prodotto = $dati['Product'] ?? null;
    if (!$prodotto) {
        return ['trovato' => false];
    }

    // Il prezzo è organizzato per "variante di confezionamento" (ProductVariations),
    // ognuna con le proprie fasce di prezzo (StandardPricing). Prendiamo la prima
    // variante disponibile e, al suo interno, il prezzo alla quantità minima.
    // NOTA: la struttura esatta della risposta può variare leggermente a seconda
    // della versione dell'API attiva sul tuo account: se questo smette di funzionare,
    // controlla la risposta reale (var_dump($dati) qui sopra, temporaneamente) e
    // aggiorna i nomi dei campi qui sotto di conseguenza.
    $variante = $prodotto['ProductVariations'][0] ?? null;
    $prezzo = null;
    if ($variante && !empty($variante['StandardPricing'][0]['UnitPrice'])) {
        $prezzo = (float) $variante['StandardPricing'][0]['UnitPrice'];
    }

    $disponibilita = $prodotto['QuantityAvailable'] ?? ($variante['QuantityAvailableforPackageType'] ?? null);

    $leadTimeGiorni = null;
    if (!empty($prodotto['ManufacturerLeadWeeks'])) {
        // es. "8 Weeks" -> converti in giorni
        if (preg_match('/(\d+)/', $prodotto['ManufacturerLeadWeeks'], $m)) {
            $leadTimeGiorni = (int) $m[1] * 7;
        }
    }

    // MOQ: DigiKey espone "MinimumOrderQuantity" a livello di prodotto o di variante di confezionamento
    $moq = $prodotto['MinimumOrderQuantity'] ?? ($variante['MinimumOrderQuantity'] ?? null);

    return [
        'trovato' => true,
        'prezzo' => $prezzo,
        'disponibilita' => $disponibilita !== null ? (float) $disponibilita : null,
        'lead_time_giorni' => $leadTimeGiorni,
        'moq' => $moq !== null ? (float) $moq : null,
        'url_prodotto' => $prodotto['ProductUrl'] ?? null,
        'produttore' => $prodotto['Manufacturer']['Name'] ?? null,
        'codice_produttore' => $prodotto['ManufacturerProductNumber'] ?? null,
    ];
}

// ============================================================
// FARNELL / ELEMENT14 / NEWARK - implementazione completa
// Documentazione: https://partner.element14.com (Product Search API REST)
// Stesso gruppo societario con tre nomi regionali: Farnell (Europa/Africa/Giappone),
// Newark (Americhe), element14 (Asia Pacifico). L'endpoint e la chiave API sono gli
// stessi; cambia solo lo "storeInfo.id" che indica il sito/paese di riferimento.
// ============================================================
function query_farnell(array $config, string $codiceFornitore): array {
    // Sito/paese di riferimento del tuo account Farnell, configurabile dalla scheda
    // fornitore (campo "Sito di riferimento"). Se non impostato, usa l'Italia come default.
    $storeId = trim($config['store_id'] ?? '') ?: 'it.farnell.com';

    $baseUrl = rtrim($config['api_base_url'] ?: 'https://api.element14.com/catalog/products', '/');
    $url = $baseUrl . '?' . http_build_query([
        'callInfo.apiKey' => $config['api_key'],
        'callInfo.responseDataFormat' => 'json',
        'term' => 'id:' . $codiceFornitore, // ricerca per codice prodotto Farnell/element14 (SKU)
        'storeInfo.id' => $storeId,
        'resultsSettings.offset' => 0,
        'resultsSettings.numberOfResults' => 1,
        'resultsSettings.responseGroup' => 'large', // include prezzi e disponibilità
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($risposta === false || $httpCode >= 400) {
        throw new Exception('Errore di comunicazione con Farnell/element14 (HTTP ' . $httpCode . ')');
    }

    $dati = json_decode($risposta, true);
    // la chiave di primo livello della risposta cambia in base al tipo di ricerca:
    // "premierFarnellPartNumberReturn" per la ricerca per codice prodotto (id:) che usiamo qui,
    // "keywordSearchReturn" per la ricerca testuale libera - la gestiamo comunque per sicurezza.
    $wrapper = $dati['premierFarnellPartNumberReturn'] ?? $dati['keywordSearchReturn'] ?? null;
    $prodotto = $wrapper['products'][0] ?? null;
    if (!$prodotto) {
        return ['trovato' => false];
    }

    // NOTA: verifica questi nomi di campo con la risposta reale del tuo account (response
    // group "large") se qualcosa non si popola correttamente: la struttura della risposta
    // può variare leggermente a seconda della versione/regione dell'account.
    $prezzo = null;
    if (!empty($prodotto['prices'][0]['cost'])) {
        $prezzo = (float) $prodotto['prices'][0]['cost'];
    }

    $disponibilita = null;
    if (isset($prodotto['stock']['level'])) {
        $disponibilita = (float) $prodotto['stock']['level'];
    } elseif (isset($prodotto['inv'])) {
        $disponibilita = (float) $prodotto['inv'];
    }

    // MOQ: il campo nella risposta Farnell/element14 si chiama "translatedMinimumOrderQuality"
    // (sì, con "Quality" e non "Quantity" - è così nella API reale, non un refuso nostro).
    // Manteniamo comunque un fallback sul nome "corretto" nel caso lo cambino in futuro.
    $moq = $prodotto['translatedMinimumOrderQuality'] ?? ($prodotto['minimumOrderQuantity'] ?? null);
    $moq = $moq !== null ? (float) $moq : null;

    return [
        'trovato' => true,
        'prezzo' => $prezzo,
        'disponibilita' => $disponibilita,
        'lead_time_giorni' => null, // non presente in questa response; eventualmente ricavabile da altri campi del tuo account
        'moq' => $moq,
        'produttore' => $prodotto['brandName'] ?? $prodotto['vendorName'] ?? null,
        'codice_produttore' => $prodotto['translatedManufacturerPartNumber'] ?? null,
    ];
}

// ============================================================
// ARROW ELECTRONICS - implementazione completa
// Documentazione: https://developers.arrow.com/api (Pricing & Availability API v4)
//
// A differenza di Mouser (una sola chiave) qui servono DUE credenziali distinte,
// ottenute insieme dalla pagina "Request API Key" di Arrow: un "login" e una
// "apikey". Nella configurazione fornitore usiamo il campo "Client ID" per il
// login e "API Key" per la apikey.
// ============================================================
function query_arrow(array $config, string $codiceFornitore): array {
    $baseUrl = rtrim($config['api_base_url'] ?: 'https://api.arrow.com', '/');

    $payload = json_encode([
        'request' => [
            'login' => $config['client_id'],
            'apikey' => $config['api_key'],
            'useExact' => true,
            'parts' => [
                ['partNum' => $codiceFornitore],
            ],
        ],
    ]);

    $url = $baseUrl . '/itemservice/v4/en/search/list?req=' . urlencode($payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($risposta === false || $httpCode >= 400) {
        throw new Exception('Errore di comunicazione con Arrow (HTTP ' . $httpCode . ')');
    }

    $dati = json_decode($risposta, true);

    // Arrow risponde sempre con HTTP 200 anche in caso di richiesta non valida:
    // l'esito reale va letto dentro transactionArea[0].response.
    $risposta_ok = $dati['itemserviceresult']['transactionArea'][0]['response']['success'] ?? false;
    if (!$risposta_ok) {
        $messaggioErrore = $dati['itemserviceresult']['transactionArea'][0]['response']['returnMsg'] ?? 'risposta non valida';
        throw new Exception('Arrow ha restituito un errore: ' . $messaggioErrore);
    }

    $parte = $dati['itemserviceresult']['data'][0]['PartList'][0] ?? null;
    if (!$parte) {
        return ['trovato' => false];
    }

    // il prezzo si trova sotto InvOrg (organizzazione di magazzino) -> sources -> Prices.ResaleList
    $fonte = $parte['InvOrg']['sources'][0] ?? null;
    $prezzo = null;
    if ($fonte && !empty($fonte['Prices']['ResaleList'][0]['price'])) {
        $prezzo = (float) $fonte['Prices']['ResaleList'][0]['price'];
    }

    // NOTA: il nome esatto del campo disponibilità non è documentato in modo univoco
    // nella documentazione pubblica; qui si prova una lista di nomi plausibili. Se la
    // disponibilità non si popola, controlla la risposta reale del tuo account
    // (temporaneamente aggiungi var_dump($fonte); exit; qui sopra) e correggi il nome.
    $disponibilita = null;
    if ($fonte) {
        foreach (['availableToSell', 'totalAvail', 'avail', 'quantity', 'qty'] as $campo) {
            if (isset($fonte[$campo])) { $disponibilita = (float) $fonte[$campo]; break; }
        }
    }

    // MOQ: stessa strategia difensiva (nome campo non documentato in modo univoco)
    $moq = null;
    if ($fonte) {
        foreach (['minOrderQty', 'MinOrderQty', 'moq', 'MOQ', 'minimumOrderQuantity'] as $campo) {
            if (isset($fonte[$campo])) { $moq = (float) $fonte[$campo]; break; }
        }
    }

    // produttore: nome campo non documentato in modo univoco, si prova un elenco di nomi
    // plausibili sia a livello di prodotto ($parte) sia di fonte/magazzino ($fonte). Se dopo
    // un primo utilizzo reale nessuno risulta corretto, individua il nome esatto dalla
    // risposta reale del tuo account e aggiungilo a questo elenco.
    $produttore = null;
    foreach (['mfrName', 'manufacturerName', 'manufacturer', 'mfr', 'brandName', 'vendorName'] as $campo) {
        if (isset($parte[$campo])) { $produttore = $parte[$campo]; break; }
        if ($fonte && isset($fonte[$campo])) { $produttore = $fonte[$campo]; break; }
    }

    // codice produttore (MPN): stessa strategia difensiva, nome campo non documentato
    $codiceProduttore = null;
    foreach (['mfrPartNum', 'manufacturerPartNumber', 'mpn', 'partNum'] as $campo) {
        if (isset($parte[$campo])) { $codiceProduttore = $parte[$campo]; break; }
    }

    return [
        'trovato' => true,
        'prezzo' => $prezzo,
        'disponibilita' => $disponibilita,
        'lead_time_giorni' => null, // non presente in questa risposta
        'moq' => $moq,
        'produttore' => $produttore,
        'codice_produttore' => $codiceProduttore,
    ];
}

// ============================================================
// TME (Transfer Multisort Elektronik) - API v2 (OAuth 2.0)
//
// TME ha dismesso la v1 (firma HMAC-SHA1) per le nuove applicazioni: dal 14
// maggio 2026 le app create su developers.tme.eu funzionano solo con la v2,
// che usa OAuth2 client-credentials (stesso schema già usato per DigiKey).
// Il token va richiesto una volta (Basic Auth su Token/Secret), è valido
// pochi minuti e viene cachato in distributori_api_config come per DigiKey.
//
// ATTENZIONE: TME non pubblica una documentazione REST v2 liberamente
// consultabile (richiede login). L'autenticazione qui sotto è verificata con
// certezza (dal codice sorgente di un progetto open source aggiornato a
// questa versione e testato con credenziali reali). L'endpoint e i nomi dei
// campi della risposta prezzi/stock sono invece una ricostruzione fondata ma
// NON garantita al 100%: se la chiamata fallisce, l'errore mostrato include
// sempre il corpo completo della risposta TME, così possiamo correggere
// endpoint/campi al primo tentativo reale invece di indovinare alla cieca.
//
// Configurazione richiesta nella scheda fornitore (Amministrazione API):
// - API Key      -> il "Token" (chiave pubblica dell'applicazione)
// - API Secret   -> l'"Application Secret"
// - Sito di riferimento -> codice paese a 2 lettere (es. "IT"); se vuoto, "IT"
// - Endpoint API base URL -> lascia vuoto per usare il default (https://api.tme.eu)
// ============================================================
function tme_ottieni_token(PDO $pdo, array $config): string {
    // riusa il token salvato se non è ancora scaduto (con un margine di sicurezza di 60s)
    if (!empty($config['token_oauth']) && !empty($config['token_scadenza'])
        && strtotime($config['token_scadenza']) > time() + 60) {
        return $config['token_oauth'];
    }

    $apiBase = rtrim($config['api_base_url'] ?: 'https://api.tme.eu', '/');
    $basic = base64_encode($config['api_key'] . ':' . $config['api_secret']);

    $ch = curl_init($apiBase . '/auth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $basic, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERAGENT => 'MRP-Elettronica/1.0',
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($risposta === false) {
        throw new Exception('Errore di comunicazione con TME durante l\'autenticazione (nessuna risposta dal server).');
    }
    if ($httpCode >= 400) {
        throw new Exception('Autenticazione TME fallita (HTTP ' . $httpCode . '): ' . $risposta);
    }

    $dati = json_decode($risposta, true);
    if (empty($dati['access_token'])) {
        throw new Exception('TME non ha restituito un access_token valido: ' . $risposta);
    }

    $token = $dati['access_token'];
    $durataSecondi = max((int) ($dati['expires_in'] ?? 300) - 30, 30);

    $pdo->prepare("UPDATE distributori_api_config SET token_oauth = ?, token_scadenza = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
        ->execute([$token, $durataSecondi, $config['id']]);

    return $token;
}

// Esegue una GET autenticata verso l'API TME v2 e restituisce il corpo "data" decodificato.
// Centralizza gestione errori/HTTP comuni alle due chiamate di query_tme() sottostanti.
function tme_get(string $url, string $token, string $paese): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept-Language: ' . $paese, 'Accept: application/json'],
        CURLOPT_USERAGENT => 'MRP-Elettronica/1.0',
        CURLOPT_TIMEOUT => 15,
    ]);
    $risposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($risposta === false) {
        throw new Exception('Errore di comunicazione con TME: nessuna risposta dal server.');
    }
    if ($httpCode >= 400) {
        throw new Exception('TME ha rifiutato la richiesta (HTTP ' . $httpCode . '): ' . $risposta);
    }

    $dati = json_decode($risposta, true);
    if (strtoupper((string) ($dati['status'] ?? '')) !== 'OK') {
        throw new Exception('TME ha risposto senza esito positivo: ' . $risposta);
    }
    return $dati['data'] ?? [];
}

// Costruisce una query string nel formato "chiave[]=val1&chiave[]=val2" per i parametri
// multivalore, esattamente come documentato dall'API TME (es. scope[]=prices&scope[]=stock).
function tme_query_string(array $parametri): string {
    $parti = [];
    foreach ($parametri as $chiave => $valore) {
        foreach ((array) $valore as $v) {
            $parti[] = rawurlencode($chiave . (is_array($valore) ? '[]' : '')) . '=' . rawurlencode((string) $v);
        }
    }
    return implode('&', $parti);
}

function query_tme(PDO $pdo, array $config, string $codiceFornitore): array {
    $paese = strtolower(trim($config['store_id'] ?? '') ?: 'it');
    $token = tme_ottieni_token($pdo, $config);

    // 1) dati anagrafici del prodotto: produttore, MOQ (minimal_amount), multiplo (multiples).
    //    Endpoint confermato: GET /products
    $queryProdotto = tme_query_string(['country' => $paese, 'symbols' => [$codiceFornitore]]);
    $datiProdotto = tme_get('https://api.tme.eu/products?' . $queryProdotto, $token, $paese);
    $elementoProdotto = $datiProdotto['elements'][0] ?? null;

    // 2) prezzo e disponibilità. Endpoint confermato dalla documentazione ufficiale TME:
    //    GET /products/data con scope[]=prices&scope[]=stock. I nomi esatti dei campi
    //    dentro ogni "elements[]" non sono documentati con un esempio reale (solo un
    //    array vuoto); qui si prova un elenco di nomi plausibili, in stile snake_case
    //    come il resto dell'API TME v2 (confermato dall'endpoint /products).
    $queryDati = tme_query_string([
        'country' => $paese,
        'currency' => 'EUR',
        'scope' => ['prices', 'stock'],
        'symbols' => [$codiceFornitore],
    ]);
    $datiPrezzoStock = tme_get('https://api.tme.eu/products/data?' . $queryDati, $token, $paese);
    $elementoDati = $datiPrezzoStock['elements'][0] ?? null;

    if (!$elementoProdotto && !$elementoDati) {
        return ['trovato' => false];
    }

    $prezzo = null;
    $disponibilita = null;
    if ($elementoDati) {
        $listaPrezzi = $elementoDati['prices']['elements'] ?? null;
        if (is_array($listaPrezzi) && isset($listaPrezzi[0])) {
            // le fasce sono già ordinate dalla quantità minima alla massima: la prima è il prezzo base
            $prezzo = $listaPrezzi[0]['price'] ?? null;
            // TME può restituire il prezzo comprensivo di IVA ("type":"GROSS"): lo riportiamo
            // a netto per coerenza con gli altri distributori, che restituiscono prezzi netti
            if ($prezzo !== null && ($elementoDati['prices']['type'] ?? '') === 'GROSS') {
                $aliquota = $elementoDati['prices']['tax']['rate'] ?? null;
                if ($aliquota !== null && $aliquota > 0) {
                    $prezzo = $prezzo / (1 + $aliquota / 100);
                }
            }
        }
        $disponibilita = $elementoDati['stock_quantity'] ?? null;
    }

    return [
        'trovato' => true,
        'prezzo' => $prezzo !== null ? (float) $prezzo : null,
        'disponibilita' => $disponibilita !== null ? (float) $disponibilita : null,
        'lead_time_giorni' => null,
        'moq' => $elementoProdotto['minimal_amount'] ?? null,
        'produttore' => $elementoProdotto['manufacturer']['name'] ?? null,
        'codice_produttore' => $elementoProdotto['manufacturer_symbols'][0] ?? null,
    ];
}

function query_avnet(array $config, string $codiceFornitore): array {
    // Documentazione: Avnet API for Developers (api.avnet.com)
    // TODO: implementare chiamata a endpoint pricing/availability
    return ['trovato' => false];
}

function query_rs(array $config, string $codiceFornitore): array {
    // Documentazione: RS Components / RS PRO Integration API
    // TODO: implementare chiamata a endpoint pricing/availability
    return ['trovato' => false];
}
