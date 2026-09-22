/**
 * Selettore componente con ricerca live via AJAX, al posto di una <select> con
 * potenzialmente migliaia di opzioni (lenta da caricare e scomoda da usare).
 *
 * Uso:
 *   creaSelettoreComponente(elementoContenitore, {
 *     nomeCampo: 'riga_componente_id[]',   // name del campo hidden che porta l'id selezionato
 *     escludiId: 123,                       // opzionale: id da escludere dai risultati (es. il componente stesso)
 *     soloConBom: true,                     // opzionale: solo componenti che possono avere una distinta base
 *     bomId: 45,                            // opzionale: limita ai soli componenti presenti in questa BOM
 *                                            // (numero, oppure funzione che ritorna il valore aggiornato -
 *                                            // utile se il bom_id si conosce solo dopo la creazione della riga)
 *     placeholder: 'Cerca...',              // opzionale
 *     onSelezione: function(componente) {}  // opzionale: callback con {id, codice_interno, descrizione, um_base_id}
 *   });
 *
 * Richiede che la pagina definisca window.BASE_URL_APP (URL assoluto della radice
 * dell'app, es. "/mrp_app/"), così il widget funziona a qualunque profondità di cartella.
 *
 * La lista dei risultati viene agganciata a document.body (posizione "fixed", calcolata
 * dinamicamente sul campo di testo) invece di restare annidata nel contenitore chiamante:
 * così non viene mai tagliata da un antenato con overflow nascosto/scroll (es. una tabella
 * con scroll orizzontale), indipendentemente da dove il widget viene usato nella pagina.
 */
function creaSelettoreComponente(container, opzioni = {}) {
  const nomeCampo = opzioni.nomeCampo || 'componente_id';
  const wrapper = document.createElement('div');
  wrapper.className = 'selettore-componente';
  wrapper.innerHTML = `
    <input type="hidden" name="${nomeCampo}" class="sc-hidden">
    <input type="text" class="form-control form-control-sm sc-testo" placeholder="${opzioni.placeholder || 'Cerca per codice o descrizione...'}" autocomplete="off">
  `;
  container.appendChild(wrapper);

  const inputTesto = wrapper.querySelector('.sc-testo');
  const inputHidden = wrapper.querySelector('.sc-hidden');
  let timeoutRicerca = null;

  const risultati = document.createElement('div');
  risultati.className = 'list-group sc-risultati shadow-sm';
  risultati.style.cssText = 'position:fixed; z-index:2000; max-height:220px; overflow-y:auto; display:none;';
  document.body.appendChild(risultati);
  wrapper._elementoRisultati = risultati; // per un'eventuale pulizia esplicita da parte del chiamante

  function posizionaRisultati() {
    const rect = inputTesto.getBoundingClientRect();
    risultati.style.left = rect.left + 'px';
    risultati.style.top = rect.bottom + 'px';
    risultati.style.width = rect.width + 'px';
  }

  function bomIdAttuale() {
    return typeof opzioni.bomId === 'function' ? opzioni.bomId() : opzioni.bomId;
  }

  function cerca(q) {
    let url = (window.BASE_URL_APP || '') + 'componenti/cerca_ajax.php?q=' + encodeURIComponent(q);
    if (opzioni.escludiId) { url += '&escludi_id=' + opzioni.escludiId; }
    if (opzioni.soloConBom) { url += '&solo_con_bom=1'; }
    const bomId = bomIdAttuale();
    if (bomId) { url += '&bom_id=' + bomId; }

    fetch(url).then(r => r.json()).then(lista => {
      risultati.innerHTML = '';
      if (!lista.length) { risultati.style.display = 'none'; return; }
      lista.forEach(c => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action py-1 px-2 small';
        item.textContent = c.codice_interno + ' - ' + c.descrizione;
        item.addEventListener('click', () => {
          inputHidden.value = c.id;
          inputTesto.value = c.codice_interno + ' - ' + c.descrizione;
          inputTesto.dataset.umBase = c.um_base_id || '';
          risultati.style.display = 'none';
          if (opzioni.onSelezione) { opzioni.onSelezione(c); }
        });
        risultati.appendChild(item);
      });
      posizionaRisultati();
      risultati.style.display = 'block';
    });
  }

  inputTesto.addEventListener('input', function () {
    inputHidden.value = ''; // annulla la selezione precedente finché non se ne scegli un'altra
    clearTimeout(timeoutRicerca);
    const valore = this.value;
    timeoutRicerca = setTimeout(() => cerca(valore), 300);
  });
  inputTesto.addEventListener('focus', function () {
    if (opzioni.bomId !== undefined && !bomIdAttuale()) { return; } // non c'è ancora una BOM di riferimento: niente da mostrare
    cerca(this.value || '');
  });
  document.addEventListener('click', function (e) {
    if (!wrapper.contains(e.target) && !risultati.contains(e.target)) { risultati.style.display = 'none'; }
  });
  // una lista "agganciata" fuori dal flusso normale va nascosta anche allo scroll/resize,
  // altrimenti resterebbe visivamente staccata dal campo che l'ha aperta
  window.addEventListener('scroll', () => { risultati.style.display = 'none'; }, true);
  window.addEventListener('resize', () => { risultati.style.display = 'none'; });

  return wrapper; // il chiamante può leggere wrapper.querySelector('.sc-hidden').value / '.sc-testo'
}
