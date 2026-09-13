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

document.addEventListener('DOMContentLoaded', function () {
    iniciarMenuMobileAdmin();
    iniciarConfirmacoesFormulario();
});
