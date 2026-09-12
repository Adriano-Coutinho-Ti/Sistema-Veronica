/**
 * Comportamento compartilhado de toda página da Loja Online: menu mobile
 * (hambúrguer) e carrossel de fotos (usado tanto no cartão do catálogo
 * quanto na página de produto). Lógica específica de cada página (cronômetro
 * do carrinho, seletor de quantidade, etc.) continua inline em cada arquivo.
 */

function iniciarMenuMobile() {
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

function iniciarCarrosseis(raiz) {
    (raiz || document).querySelectorAll('[data-carousel]').forEach(function (carousel) {
        // Chamar de novo (ex: depois de injetar um cartão novo via JS) nunca deve
        // religar um carrossel que já está rodando — duplicaria os temporizadores
        // de avanço automático.
        if (carousel.dataset.carouselIniciado) {
            return;
        }
        carousel.dataset.carouselIniciado = '1';

        const track = carousel.querySelector('.carousel-track');
        const dots = carousel.querySelectorAll('.carousel-dots .dot');
        const prev = carousel.querySelector('.carousel-prev');
        const next = carousel.querySelector('.carousel-next');
        if (!track || dots.length === 0) {
            return;
        }

        function irPara(indice) {
            track.scrollTo({ left: indice * track.clientWidth, behavior: 'smooth' });
        }

        dots.forEach(function (dot, indice) {
            dot.addEventListener('click', function (e) {
                e.preventDefault();
                irPara(indice);
            });
        });

        if (prev) {
            prev.addEventListener('click', function () {
                const atual = Math.round(track.scrollLeft / track.clientWidth);
                irPara(Math.max(0, atual - 1));
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                const atual = Math.round(track.scrollLeft / track.clientWidth);
                irPara(Math.min(dots.length - 1, atual + 1));
            });
        }

        let agendado = false;
        track.addEventListener('scroll', function () {
            if (agendado) {
                return;
            }
            agendado = true;
            requestAnimationFrame(function () {
                const indice = Math.round(track.scrollLeft / track.clientWidth);
                dots.forEach(function (d, i) { d.classList.toggle('ativo', i === indice); });
                agendado = false;
            });
        });

        // Avanço automático (ex: cartão do catálogo) — desliga se o visitante preferir
        // menos movimento na tela, e reinicia a contagem sempre que ele mexe manualmente.
        const intervaloAuto = parseInt(carousel.dataset.carouselAuto || '0', 10);
        const semAnimacao = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (intervaloAuto > 0 && !semAnimacao) {
            let temporizador = null;

            function agendarProximo() {
                if (temporizador) {
                    clearInterval(temporizador);
                }
                temporizador = setInterval(function () {
                    const atual = Math.round(track.scrollLeft / track.clientWidth);
                    irPara((atual + 1) % dots.length);
                }, intervaloAuto);
            }

            track.addEventListener('pointerdown', agendarProximo);
            dots.forEach(function (dot) { dot.addEventListener('click', agendarProximo); });
            agendarProximo();
        }
    });
}

function iniciarModalSair() {
    const link = document.getElementById('link-sair');
    const modal = document.getElementById('modal-sair');
    if (!link || !modal) {
        return;
    }
    link.addEventListener('click', function (e) {
        e.preventDefault();
        modal.hidden = false;
    });
    const cancelar = document.getElementById('modal-sair-cancelar');
    if (cancelar) {
        cancelar.addEventListener('click', function () { modal.hidden = true; });
    }
    modal.addEventListener('click', function (e) {
        if (e.target === modal) { modal.hidden = true; }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { modal.hidden = true; }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    iniciarMenuMobile();
    iniciarCarrosseis();
    iniciarModalSair();
});
