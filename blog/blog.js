/* Filtro de artigos por tema (?tema=slug). Sem JS, os links só recarregam a lista completa. */
(function () {
  var lista = document.getElementById('lista-artigos');
  if (!lista) return;
  var cards = lista.querySelectorAll('.article-card');
  var chips = document.querySelectorAll('.blog-main .tema-chip');
  var vazio = document.querySelector('.blog-vazio');

  function aplicar(tema) {
    var achou = 0;
    cards.forEach(function (c) {
      var mostra = !tema || c.dataset.tema === tema;
      c.hidden = !mostra;
      if (mostra) achou++;
    });
    chips.forEach(function (c) {
      c.setAttribute('aria-current', (c.dataset.tema || '') === (tema || '') ? 'true' : 'false');
    });
    if (vazio) vazio.hidden = achou > 0;
  }

  chips.forEach(function (c) {
    c.addEventListener('click', function (e) {
      e.preventDefault();
      var tema = c.dataset.tema || '';
      history.replaceState(null, '', tema ? '?tema=' + encodeURIComponent(tema) : location.pathname);
      aplicar(tema);
    });
  });

  aplicar(new URLSearchParams(location.search).get('tema') || '');
})();
