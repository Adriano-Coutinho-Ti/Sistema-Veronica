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

document.addEventListener('DOMContentLoaded', function () {
    iniciarMenuMobileAdmin();
});
