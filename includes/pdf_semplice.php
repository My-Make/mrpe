<?php
/**
 * Generatore PDF minimale, senza dipendenze esterne (in questo ambiente non è
 * possibile installare librerie via Composer). Usa solo i font standard PDF
 * (Helvetica/Helvetica-Bold, sempre disponibili in qualunque visualizzatore,
 * senza bisogno di incorporare file di font), quindi il PDF risultante è
 * piccolo e compatibile ovunque.
 *
 * Pensato per documenti a layout fisso (intestazioni, tabelle, testo) come un
 * ordine di acquisto: non è una libreria PDF general-purpose.
 *
 * Sistema di coordinate: origine in ALTO A SINISTRA della pagina (y cresce
 * verso il basso), più intuitivo per disegnare un documento dall'alto in giù;
 * la conversione al sistema PDF (origine in basso) è interna.
 */
class SimplePDF {
    private float $larghezza;
    private float $altezza;
    private array $pagine = [];      // ogni elemento: stringa di operatori del content stream
    private int $paginaCorrente = -1;
    private array $immagini = [];    // immagini JPEG incorporate: [{id, dati, larghezzaPx, altezzaPx, colorSpace}]

    public function __construct(float $larghezza = 595.28, float $altezza = 841.89) {
        $this->larghezza = $larghezza;
        $this->altezza = $altezza;
        $this->nuovaPagina();
    }

    public function nuovaPagina(): void {
        $this->pagine[] = '';
        $this->paginaCorrente++;
    }

    public function altezzaPagina(): float { return $this->altezza; }
    public function larghezzaPagina(): float { return $this->larghezza; }

    private function aggiungi(string $operatori): void {
        $this->pagine[$this->paginaCorrente] .= $operatori . "\n";
    }

    private function escapeTesto(string $testo): string {
        // i font standard PDF usano WinAnsiEncoding (~ CP1252): convertiamo da UTF-8
        // per rendere correttamente le lettere accentate italiane
        $convertito = @iconv('UTF-8', 'CP1252//IGNORE', $testo);
        if ($convertito === false) { $convertito = $testo; }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $convertito);
    }

    // stima approssimativa della larghezza testo (senza tabelle metriche complete dei font,
    // sufficiente per il wrapping su un documento semplice come un ordine di acquisto)
    private function larghezzaTesto(string $testo, float $size, bool $grassetto): float {
        $fattore = $grassetto ? 0.60 : 0.52;
        return mb_strlen($testo) * $size * $fattore;
    }

    public function testo(float $x, float $y, string $testo, float $size = 10, bool $grassetto = false, string $allinea = 'left', ?float $larghezzaBox = null): void {
        $font = $grassetto ? 'F2' : 'F1';
        $pdfY = $this->altezza - $y;
        $testoX = $x;
        if ($allinea !== 'left' && $larghezzaBox !== null) {
            $larghezzaTesto = $this->larghezzaTesto($testo, $size, $grassetto);
            if ($allinea === 'right') { $testoX = $x + $larghezzaBox - $larghezzaTesto; }
            elseif ($allinea === 'center') { $testoX = $x + ($larghezzaBox - $larghezzaTesto) / 2; }
        }
        $this->aggiungi(sprintf('BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $font, $size, $testoX, $pdfY, $this->escapeTesto($testo)));
    }

    // scompone un testo in righe che rispettano una larghezza massima (word-wrap), senza
    // disegnarlo: utile per calcolare in anticipo quanto spazio verticale servirà (es. altezza
    // di una riga di tabella con una descrizione lunga) prima di comporre il layout
    public function scomponiRighe(string $testo, float $larghezzaMax, float $size = 10, bool $grassetto = false): array {
        $parole = preg_split('/\s+/', trim($testo));
        $righe = [];
        $riga = '';
        foreach ($parole as $parola) {
            $prova = $riga === '' ? $parola : $riga . ' ' . $parola;
            if ($this->larghezzaTesto($prova, $size, $grassetto) > $larghezzaMax && $riga !== '') {
                $righe[] = $riga;
                $riga = $parola;
            } else {
                $riga = $prova;
            }
        }
        if ($riga !== '') { $righe[] = $riga; }
        return $righe ?: [''];
    }

    public function testoMultiriga(float $x, float $y, string $testo, float $larghezzaMax, float $size = 10, bool $grassetto = false, float $interlinea = 1.3): float {
        $yCorrente = $y;
        foreach ($this->scomponiRighe($testo, $larghezzaMax, $size, $grassetto) as $riga) {
            $this->testo($x, $yCorrente, $riga, $size, $grassetto);
            $yCorrente += $size * $interlinea;
        }
        return $yCorrente;
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, float $spessore = 0.5): void {
        $pdfY1 = $this->altezza - $y1;
        $pdfY2 = $this->altezza - $y2;
        $this->aggiungi(sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $spessore, $x1, $pdfY1, $x2, $pdfY2));
    }

    public function rettangolo(float $x, float $y, float $w, float $h, float $spessore = 0.5): void {
        $pdfY = $this->altezza - $y - $h; // il PDF disegna il rettangolo dall'angolo in basso a sinistra
        $this->aggiungi(sprintf('%.2F w %.2F %.2F %.2F %.2F re S', $spessore, $x, $pdfY, $w, $h));
    }

    public function rettangoloRiempito(float $x, float $y, float $w, float $h, float $grigio = 0.92): void {
        $pdfY = $this->altezza - $y - $h;
        $this->aggiungi(sprintf('%.2F g %.2F %.2F %.2F %.2F re f 0 g', $grigio, $x, $pdfY, $w, $h));
    }

    // Incorpora un'immagine JPEG (unico formato incorporabile senza libreria di decodifica:
    // il flusso JPEG originale viene inserito così com'è nel PDF, filtro DCTDecode). Ritorna
    // true se incorporata, false se il file non è un JPEG valido o non è leggibile.
    // $x, $y: angolo in alto a sinistra dove disegnarla. $altezza: se omessa, calcolata
    // mantenendo le proporzioni originali dell'immagine rispetto a $larghezza.
    public function immagineJPEG(string $percorsoFile, float $x, float $y, float $larghezza, ?float $altezza = null): bool {
        $dati = @file_get_contents($percorsoFile);
        if ($dati === false) { return false; }
        $info = @getimagesizefromstring($dati);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) { return false; }

        $larghezzaPx = $info[0];
        $altezzaPx = $info[1];
        $canali = $info['channels'] ?? 3;
        $colorSpace = $canali === 1 ? '/DeviceGray' : '/DeviceRGB';
        if ($altezza === null) { $altezza = $larghezza * $altezzaPx / $larghezzaPx; }

        $idImg = 'Im' . (count($this->immagini) + 1);
        $this->immagini[] = ['id' => $idImg, 'dati' => $dati, 'larghezzaPx' => $larghezzaPx, 'altezzaPx' => $altezzaPx, 'colorSpace' => $colorSpace];

        $pdfY = $this->altezza - $y - $altezza; // angolo in basso a sinistra dell'immagine (sistema PDF)
        $this->aggiungi(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $larghezza, $altezza, $x, $pdfY, $idImg));
        return true;
    }

    public function output(): string {
        $oggetti = [];
        $oggetti[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $numPagine = count($this->pagine);
        $idPagine = range(3, 2 + $numPagine);
        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $idPagine));
        $oggetti[] = "<< /Type /Pages /Kids [$kids] /Count $numPagine >>";

        $idFontRegular = 3 + $numPagine;
        $idFontBold = $idFontRegular + 1;
        $idImmagineBase = $idFontBold + 1;
        $numImmagini = count($this->immagini);
        $idContenutoBase = $idImmagineBase + $numImmagini;

        // dizionario XObject condiviso da tutte le pagine (anche se un'immagine è usata solo
        // sulla prima pagina, includerla ovunque non causa problemi e semplifica la logica)
        $xObjectDict = '';
        foreach ($this->immagini as $indice => $img) {
            $xObjectDict .= "/{$img['id']} " . ($idImmagineBase + $indice) . " 0 R ";
        }
        $risorseXObject = $xObjectDict ? " /XObject << $xObjectDict>>" : '';

        foreach ($this->pagine as $i => $contenuto) {
            $idContenuto = $idContenutoBase + $i;
            $oggetti[2 + $i] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->larghezza} {$this->altezza}] "
                . "/Resources << /Font << /F1 $idFontRegular 0 R /F2 $idFontBold 0 R >>$risorseXObject >> /Contents $idContenuto 0 R >>";
        }

        $oggetti[$idFontRegular - 1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $oggetti[$idFontBold - 1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $corpiBinari = []; // oggetti immagine: corpo con dati binari, gestiti a parte (mai passati per operazioni testuali)
        foreach ($this->immagini as $indice => $img) {
            $numero = $idImmagineBase + $indice;
            $lunghezza = strlen($img['dati']);
            $corpiBinari[$numero] = "<< /Type /XObject /Subtype /Image /Width {$img['larghezzaPx']} /Height {$img['altezzaPx']} "
                . "/ColorSpace {$img['colorSpace']} /BitsPerComponent 8 /Filter /DCTDecode /Length $lunghezza >>\nstream\n{$img['dati']}\nendstream";
        }

        foreach ($this->pagine as $i => $contenuto) {
            $lunghezza = strlen($contenuto);
            $oggetti[$idContenutoBase - 1 + $i] = "<< /Length $lunghezza >>\nstream\n$contenuto endstream";
        }

        // assembla il file PDF con la xref table (necessaria per un PDF valido)
        $pdf = "%PDF-1.4\n";
        $offset = [];
        $totaleOggettiTesto = count($oggetti) + count($corpiBinari);
        for ($numero = 1; $numero <= $totaleOggettiTesto; $numero++) {
            $corpo = $corpiBinari[$numero] ?? ($oggetti[$numero - 1] ?? null);
            if ($corpo === null) { continue; }
            $offset[$numero] = strlen($pdf);
            $pdf .= "$numero 0 obj\n$corpo\nendobj\n";
        }
        $offsetXref = strlen($pdf);
        $totaleOggetti = $totaleOggettiTesto + 1;
        $pdf .= "xref\n0 $totaleOggetti\n0000000000 65535 f \n";
        for ($n = 1; $n < $totaleOggetti; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offset[$n]);
        }
        $pdf .= "trailer\n<< /Size $totaleOggetti /Root 1 0 R >>\nstartxref\n$offsetXref\n%%EOF";

        return $pdf;
    }
}
