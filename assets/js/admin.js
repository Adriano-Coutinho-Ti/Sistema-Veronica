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

/**
 * Máscara de telefone BR com auto-inserção do 9 — mesma lógica de
 * assets/js/loja.js (formatarTelefoneBrComNoveAutomatico/
 * ativarMascaraTelefoneComNoveAutomatico), duplicada aqui de propósito
 * porque admin.js e loja.js são bundles independentes (ver header deste
 * arquivo). Usada em clientes/novo.php, clientes/detalhe.php e
 * config_sistema/aparencia.php.
 */
function formatarTelefoneBrComNoveAutomatico(valorBruto) {
    let digitos = valorBruto.replace(/\D/g, '');
    if (digitos.length >= 3 && digitos[2] !== '9') {
        digitos = digitos.slice(0, 2) + '9' + digitos.slice(2);
    }
    digitos = digitos.slice(0, 11);

    if (digitos.length === 0) { return ''; }
    if (digitos.length <= 2) { return '(' + digitos; }
    const ddd = digitos.slice(0, 2);
    const resto = digitos.slice(2);
    const parte1 = resto.slice(0, 5);
    const parte2 = resto.slice(5);
    let formatado = '(' + ddd + ') ' + parte1;
    if (parte2) { formatado += '-' + parte2; }
    return formatado;
}

function ativarMascaraTelefoneComNoveAutomatico(input) {
    if (!input) { return; }
    input.addEventListener('input', function () {
        const posicaoAntes = input.selectionStart;
        const tamanhoAntes = input.value.length;
        input.value = formatarTelefoneBrComNoveAutomatico(input.value);
        const diferenca = input.value.length - tamanhoAntes;
        const novaPosicao = Math.max(0, (posicaoAntes || 0) + diferenca);
        input.setSelectionRange(novaPosicao, novaPosicao);
    });
}

/**
 * Máscara de valor em reais — mesma lógica de assets/js/loja.js
 * (formatarMoedaBr/ativarMascaraMoeda), duplicada aqui pelo mesmo motivo
 * do bloco de telefone acima. Dígitos entram da direita pra esquerda:
 * "15" vira "0,15", "150" vira "1,50".
 */
function formatarMoedaBr(valorBruto) {
    let digitos = (valorBruto || '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    while (digitos.length < 3) {
        digitos = '0' + digitos;
    }
    const centavos = digitos.slice(-2);
    const inteiro = digitos.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return inteiro + ',' + centavos;
}

function ativarMascaraMoeda(input) {
    if (!input) { return; }
    input.addEventListener('input', function () {
        input.value = formatarMoedaBr(input.value);
        input.setSelectionRange(input.value.length, input.value.length);
    });
}

function ativarMascarasMoeda() {
    document.querySelectorAll('.js-mascara-moeda').forEach(ativarMascaraMoeda);
}

/**
 * Mesma ideia da máscara de moeda, só que sem separador de milhar — usada
 * na Taxa de marketplace (%) do painel_dev, o único campo de porcentagem
 * do sistema hoje. "150" vira "1,50" (1,5%).
 */
function formatarPercentualBr(valorBruto) {
    let digitos = (valorBruto || '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    while (digitos.length < 3) {
        digitos = '0' + digitos;
    }
    const decimais = digitos.slice(-2);
    const inteiro = digitos.slice(0, -2).replace(/^0+(?=\d)/, '') || '0';
    return inteiro + ',' + decimais;
}

function ativarMascaraPercentual(input) {
    if (!input) { return; }
    input.addEventListener('input', function () {
        input.value = formatarPercentualBr(input.value);
        input.setSelectionRange(input.value.length, input.value.length);
    });
}

function ativarMascarasPercentual() {
    document.querySelectorAll('.js-mascara-percentual').forEach(ativarMascaraPercentual);
}

/**
 * Converte um valor no formato "1.234,56" (o que formatarMoedaBr() produz)
 * pro float 1234.56 — usado em caixa/pagamento.php pra calcular troco no
 * próprio navegador antes de enviar ao servidor. Precisa tirar o "." de
 * milhar ANTES de trocar "," por "." de decimal, senão "1.234,56" vira
 * "1.234.56" e o parseFloat para no primeiro ponto (== 1.234, errado).
 */
function converterMoedaBrParaFloat(valorFormatado) {
    const limpo = (valorFormatado || '').replace(/\./g, '').replace(',', '.');
    return parseFloat(limpo) || 0;
}

/**
 * Mostra ao lado de um campo "minutos" (ex: config_sistema/pdv.php) o
 * equivalente em horas/minutos conforme o valor é digitado — ex: "150"
 * mostra "= 2h 30min". Existe especificamente pra pegar o erro de digitar
 * pensando em horas num campo que é só minutos (ex: digitar "2" achando
 * que são 2 horas, quando na verdade viraram só 2 minutos) — o valor
 * errado fica visualmente óbvio assim que aparece.
 */
function ativarConversorMinutos(input, saida) {
    if (!input || !saida) { return; }
    function atualizar() {
        const minutos = parseInt(input.value, 10);
        if (!minutos || minutos < 1) {
            saida.textContent = '';
            return;
        }
        const horas = Math.floor(minutos / 60);
        const restoMinutos = minutos % 60;
        if (horas === 0) {
            saida.textContent = '= ' + restoMinutos + ' min';
        } else if (restoMinutos === 0) {
            saida.textContent = '= ' + horas + 'h';
        } else {
            saida.textContent = '= ' + horas + 'h ' + restoMinutos + 'min';
        }
    }
    input.addEventListener('input', atualizar);
    atualizar();
}

document.addEventListener('DOMContentLoaded', function () {
    iniciarMenuMobileAdmin();
    iniciarConfirmacoesFormulario();
    iniciarAlternadoresVisualizacao();
    iniciarSubmenusAdmin();
    ativarMascarasMoeda();
    ativarMascarasPercentual();
});
