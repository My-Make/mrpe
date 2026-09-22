<?php
$titolo_pagina = 'Amministrazione';
require_once __DIR__ . '/../includes/header.php';
require_admin();
?>
<h4><i class="bi bi-gear"></i> Amministrazione</h4>
<p class="text-muted">Configura i dati di base usati nelle schede componente.</p>

<div class="row g-3 mt-2">
  <div class="col-md-4">
    <a href="tipi_componente.php" class="text-decoration-none">
      <div class="card p-4 h-100">
        <i class="bi bi-tags fs-2 text-primary"></i>
        <h6 class="mt-2">Tipi componente</h6>
        <p class="small text-muted mb-0">Definisci le categorie strutturali (Componente, Semilavorato, Prodotto finito, o tipi personalizzati) e quali possono avere una distinta base propria.</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="categorie.php" class="text-decoration-none">
      <div class="card p-4 h-100">
        <i class="bi bi-folder2-open fs-2 text-primary"></i>
        <h6 class="mt-2">Categorie</h6>
        <p class="small text-muted mb-0">Gestisci le categorie merceologiche dei componenti (es. Resistori, Connettori, PCB...) con eventuale gerarchia.</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="unita_misura.php" class="text-decoration-none">
      <div class="card p-4 h-100">
        <i class="bi bi-rulers fs-2 text-primary"></i>
        <h6 class="mt-2">Unità di misura</h6>
        <p class="small text-muted mb-0">Gestisci le unità di misura disponibili e imposta quella proposta di default nei nuovi componenti.</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="tecnologie.php" class="text-decoration-none">
      <div class="card p-4 h-100">
        <i class="bi bi-cpu fs-2 text-primary"></i>
        <h6 class="mt-2">Tecnologie</h6>
        <p class="small text-muted mb-0">Gestisci le tecnologie di montaggio/costruzione (es. SMD, THT) da assegnare ai componenti.</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="causali_movimento.php" class="text-decoration-none">
      <div class="card p-4 h-100">
        <i class="bi bi-arrow-left-right fs-2 text-primary"></i>
        <h6 class="mt-2">Causali movimento</h6>
        <p class="small text-muted mb-0">Configura le causali proponibili quando si registra un carico, scarico, trasferimento o rettifica di magazzino.</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="azienda.php" class="text-decoration-none">
      <div class="card p-4 h-100">
        <i class="bi bi-building fs-2 text-primary"></i>
        <h6 class="mt-2">Dati azienda</h6>
        <p class="small text-muted mb-0">Indirizzo, P.IVA e telefono usati come intestazione nei documenti generati dall'app (es. ordini di acquisto in PDF).</p>
      </div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
