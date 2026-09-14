/**
 * Comportamento compartilhado de toda tela interna (Fundação/PDV/Linha de
 * Crédito): só o menu mobile (hambúrguer) por enquanto — mesmo padrão do
 * assets/js/loja.js, mas como são dois sistemas de página independentes
 * (admin nunca carrega loja.js e vice-versa), a função vive duplicada aqui
 * em vez de compartilhada entre os dois.
 */
function iniciarMenuMobileAdmin() {
    const toggle = document.getElementById('nav-toggle');
    const nav = document.getElementById('site-nav');
    if (!toggle || !nav) {
        return;
    }
    toggle.addEventListener('click', function () {
        const aberto = nav.classList.toggle('aberto');
        toggle.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });
    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            nav.classList.remove('aberto');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
}

/**
 * Menu agrupado (ex: "Caixa" reunindo PDV/Vendas/Histórico) — clicar no
 * grupo abre/fecha o submenu; no desktop o CSS também abre no hover
 * (:hover/:focus-within), isso aqui só cobre o clique (mobile e quem
 * prefere clicar em vez de passar o mouse).
 */
function iniciarSubmenusAdmin() {
    const grupos = document.querySelectorAll('.nav-grupo');
    grupos.forEach(function (grupo) {
        const trigger = grupo.querySelector('.nav-grupo-trigger');
        if (!trigger) { return; }
        trigger.setAttribute('aria-expanded', 'false');
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            const abrir = !grupo.classList.contains('aberto');
            grupos.forEach(function (g) {
                g.classList.remove('aberto');
                g.querySelector('.nav-grupo-trigger')?.setAttribute('aria-expanded', 'false');
            });
            if (abrir) {
                grupo.classList.add('aberto');
                trigger.setAttribute('aria-expanded', 'true');
            }
        });
    });
    document.addEventListener('click', function () {
        grupos.forEach(function (g) {
            g.classList.remove('aberto');
            g.querySelector('.nav-grupo-trigger')?.setAttribute('aria-expanded', 'false');
        });
    });
}

/**
 * Substitui window.confirm() nativo por um popup no padrão visual do
 * sistema (.modal-overlay/.modal-card, mesmo componente usado no recorte
 * de foto) — nunca usar confirm()/alert() do navegador neste sistema.
 * Cria o modal uma única vez e reaproveita pra qualquer chamada.
 */
function confirmarAcao(mensagem) {
    return new Promise(function (resolve) {
        let overlay = document.getElementById('modal-confirmacao');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'modal-confirmacao';
            overlay.className = 'modal-overlay';
            overlay.hidden = true;
            overlay.innerHTML =
                '<div class="modal-card">' +
                    '<h3>Confirmar ação</h3>' +
                    '<p id="modal-confirmacao-texto"></p>' +
                    '<div class="modal-acoes">' +
                        '<button type="button" class="btn-outline" id="modal-confirmacao-cancelar">Cancelar</button>' +
                        '<button type="button" class="btn-perigo" id="modal-confirmacao-ok">Confirmar</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(overlay);
        }

        overlay.querySelector('#modal-confirmacao-texto').textContent = mensagem;
        overlay.hidden = false;

        const btnOk = overlay.querySelector('#modal-confirmacao-ok');
        const btnCancelar = overlay.querySelector('#modal-confirmacao-cancelar');

        function finalizar(resultado) {
            overlay.hidden = true;
            btnOk.removeEventListener('click', onOk);
            btnCancelar.removeEventListener('click', onCancelar);
            overlay.removeEventListener('click', onClickFora);
            document.removeEventListener('keydown', onEsc);
            resolve(resultado);
        }
        function onOk() { finalizar(true); }
        function onCancelar() { finalizar(false); }
        function onClickFora(e) { if (e.target === overlay) { finalizar(false); } }
        function onEsc(e) { if (e.key === 'Escape') { finalizar(false); } }

        btnOk.addEventListener('click', onOk);
        btnCancelar.addEventListener('click', onCancelar);
        overlay.addEventListener('click', onClickFora);
        document.addEventListener('keydown', onEsc);
    });
}

/**
 * Intercepta o submit de qualquer <form data-confirm="mensagem">: mostra o
 * popup e só deixa o form seguir se o usuário confirmar. Evita repetir a
 * mesma lógica de interceptar/reenviar em cada página que tem um botão
 * destrutivo (excluir, remover, rejeitar...).
 */
function iniciarConfirmacoesFormulario() {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmado === '1') {
                return;
            }
            e.preventDefault();
            confirmarAcao(form.dataset.confirm).then(function (ok) {
                if (ok) {
                    form.dataset.confirmado = '1';
                    form.submit();
                }
            });
        });
    });
}

/**
 * Alterna listagens entre tabela e cards, lembrando a escolha do usuário
 * (por navegador, via localStorage — não é dado do sistema, é preferência
 * de exibição). Funciona por atributos data-* no HTML, sem precisar de
 * JS específico por página: qualquer `.alternador-visualizacao` com
 * data-chave/data-alvo-lista/data-alvo-cards é detectado automaticamente.
 */
function iniciarAlternadoresVisualizacao() {
    document.querySelectorAll('.alternador-visualizacao').forEach(function (alternador) {
        const chave = alternador.dataset.chave;
        const elLista = document.getElementById(alternador.dataset.alvoLista);
        const elCards = document.getElementById(alternador.dataset.alvoCards);
        if (!chave || !elLista || !elCards) {
            return;
        }

        const botoes = alternador.querySelectorAll('button[data-modo]');

        function aplicar(modo) {
            elLista.hidden = modo === 'cards';
            elCards.hidden = modo !== 'cards';
            botoes.forEach(function (b) { b.classList.toggle('ativo', b.dataset.modo === modo); });
            try { localStorage.setItem('visualizacao-' + chave, modo); } catch (e) { /* navegador sem acesso a localStorage (aba anônima etc) — só não lembra a escolha */ }
        }

        let modoSalvo = 'lista';
        try { modoSalvo = localStorage.getItem('visualizacao-' + chave) || 'lista'; } catch (e) { /* idem */ }
        aplicar(modoSalvo);

        botoes.forEach(function (b) {
            b.addEventListener('click', function () { aplicar(b.dataset.modo); });
        });
    });
}

/**
 * Leitor de código de barras pela câmera do celular/computador, usando
 * QuaggaJS (carregado só nas páginas que chamam esta função — não faz
 * parte do bundle carregado em toda tela admin). Espera encontrar no HTML
 * da página que chama: um botão com o id passado em `idBotaoAbrir`, e o
 * modal padrão #modal-scanner com #scanner-viewport (onde o Quagga desenha
 * o vídeo), #scanner-erro e #btn-cancelar-scanner dentro dele.
 *
 * Exige 3 leituras seguidas do MESMO código antes de aceitar — frames
 * isolados de uma câmera de celular geram falso-positivo com frequência,
 * e aqui o código lido vira dado real (produto cadastrado, busca no
 * caixa), então vale a pena essa folga extra antes de aceitar.
 *
 * `aoAbrir`/`aoFechar` são opcionais — usados quando o botão de escanear
 * fica dentro de outro modal (ex: produtos/editar.php), pra esconder esse
 * modal enquanto a câmera está aberta e mostrar de novo ao cancelar/ler.
 */
function iniciarLeitorCodigoBarras(idBotaoAbrir, aoLerCodigo, aoAbrir, aoFechar) {
    const botaoAbrir = document.getElementById(idBotaoAbrir);
    const modal = document.getElementById('modal-scanner');
    const viewport = document.getElementById('scanner-viewport');
    const erro = document.getElementById('scanner-erro');
    const btnCancelar = document.getElementById('btn-cancelar-scanner');
    if (!botaoAbrir || !modal || !viewport || typeof Quagga === 'undefined') {
        return;
    }

    let scannerAtivo = false;
    let contagemPorCodigo = {};

    function aoDetectar(resultado) {
        const codigo = resultado && resultado.codeResult && resultado.codeResult.code;
        if (!codigo) { return; }
        contagemPorCodigo[codigo] = (contagemPorCodigo[codigo] || 0) + 1;
        if (contagemPorCodigo[codigo] < 3) { return; }
        const codigoConfirmado = codigo;
        pararScanner();
        aoLerCodigo(codigoConfirmado);
    }

    function pararScanner() {
        if (scannerAtivo) {
            Quagga.offDetected(aoDetectar);
            Quagga.stop();
            scannerAtivo = false;
        }
        modal.hidden = true;
        if (aoFechar) { aoFechar(); }
    }

    function iniciarScanner() {
        if (aoAbrir) { aoAbrir(); }
        contagemPorCodigo = {};
        erro.hidden = true;
        modal.hidden = false;
        Quagga.init({
            inputStream: {
                type: 'LiveStream',
                target: viewport,
                constraints: { facingMode: 'environment' },
            },
            decoder: {
                readers: ['ean_reader', 'ean_8_reader', 'code_128_reader', 'code_39_reader', 'upc_reader', 'upc_e_reader'],
            },
            locate: true,
        }, function (err) {
            if (err) {
                erro.textContent = 'Não foi possível acessar a câmera — verifique a permissão do navegador.';
                erro.hidden = false;
                return;
            }
            scannerAtivo = true;
            Quagga.start();
            Quagga.onDetected(aoDetectar);
        });
    }

    botaoAbrir.addEventListener('click', iniciarScanner);
    btnCancelar.addEventListener('click', pararScanner);
    modal.addEventListener('click', function (e) { if (e.target === modal) { pararScanner(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { pararScanner(); } });
}

document.addEventListener('DOMContentLoaded', function () {
    iniciarMenuMobileAdmin();
    iniciarConfirmacoesFormulario();
    iniciarAlternadoresVisualizacao();
    iniciarSubmenusAdmin();
});
