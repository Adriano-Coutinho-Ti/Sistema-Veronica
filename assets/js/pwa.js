(function () {
  'use strict';
  var tag = document.currentScript;
  if (!tag) { return; }
  var swUrl = tag.getAttribute('data-sw');
  var app = tag.getAttribute('data-app') === 'admin' ? 'admin' : 'loja';
  var ua = navigator.userAgent || '';
  var ios = /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1);
  var instalado = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  var evento = null;
  var caixa = null;
  var chave = 'pwaDispensar:' + app;
  var DIAS_SEM_INCOMODAR = 7;

  // O manifesto e a cor da barra vão pro <head> aqui, porque o cabeçalho das páginas é incluído já dentro do <body>.
  if (!document.querySelector('link[rel="manifest"]') && tag.getAttribute('data-manifest')) {
    var m = document.createElement('link');
    m.rel = 'manifest';
    m.href = tag.getAttribute('data-manifest');
    document.head.appendChild(m);
  }
  if (!document.querySelector('meta[name="theme-color"]') && tag.getAttribute('data-tema')) {
    var t = document.createElement('meta');
    t.name = 'theme-color';
    t.content = tag.getAttribute('data-tema');
    document.head.appendChild(t);
  }

  if ('serviceWorker' in navigator && swUrl) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(swUrl).catch(function () {});
    });
  }

  function dispensado() {
    try {
      var ts = parseInt(localStorage.getItem(chave) || '0', 10);
      return ts > 0 && Date.now() - ts < DIAS_SEM_INCOMODAR * 24 * 3600 * 1000;
    } catch (e) { return false; }
  }
  function dispensar() {
    try { localStorage.setItem(chave, String(Date.now())); } catch (e) {}
  }
  function remover() {
    if (caixa && caixa.parentNode) { caixa.parentNode.removeChild(caixa); }
    caixa = null;
  }
  function montar(texto, aoClicar) {
    if (caixa || instalado || dispensado()) { return; }
    caixa = document.createElement('div');
    caixa.className = 'instalar-app';
    caixa.setAttribute('role', 'region');
    caixa.setAttribute('aria-label', 'Instalar aplicativo');
    var acao;
    if (aoClicar) {
      acao = document.createElement('button');
      acao.type = 'button';
      acao.className = 'instalar-app-btn';
      acao.addEventListener('click', aoClicar);
    } else {
      acao = document.createElement('span');
      acao.className = 'instalar-app-dica';
    }
    acao.textContent = texto;
    var fechar = document.createElement('button');
    fechar.type = 'button';
    fechar.className = 'instalar-app-fechar';
    fechar.setAttribute('aria-label', 'Dispensar');
    fechar.textContent = '×';
    fechar.addEventListener('click', function () { dispensar(); remover(); });
    caixa.appendChild(acao);
    caixa.appendChild(fechar);
    document.body.appendChild(caixa);
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    evento = e;
    montar('Instalar aplicativo', function () {
      if (!evento) { return; }
      var ev = evento;
      evento = null;
      ev.prompt();
      ev.userChoice.then(function (r) {
        if (!r || r.outcome !== 'accepted') { dispensar(); }
        remover();
      }).catch(function () {});
    });
  });
  window.addEventListener('appinstalled', function () {
    evento = null;
    remover();
  });

  // iPhone/iPad não têm instalação automática: só dá pra mostrar o passo a passo.
  if (ios && !instalado) {
    window.addEventListener('load', function () {
      montar('Para instalar: toque em Compartilhar e depois em "Adicionar à Tela de Início".', null);
    });
  }
})();
