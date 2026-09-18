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

// Máscara de telefone BR progressiva — usada tanto no cadastro quanto em
// "Minha conta" (troca de WhatsApp), então vive aqui em vez de duplicada.
function formatarTelefoneBr(valorBruto) {
    const digitos = valorBruto.replace(/\D/g, '').slice(0, 11);
    if (digitos.length === 0) { return ''; }
    if (digitos.length <= 2) { return '(' + digitos; }
    const ddd = digitos.slice(0, 2);
    const resto = digitos.slice(2);
    const tamanhoParte1 = digitos.length > 10 ? 5 : 4;
    const parte1 = resto.slice(0, tamanhoParte1);
    const parte2 = resto.slice(tamanhoParte1);
    let formatado = '(' + ddd + ') ' + parte1;
    if (parte2) { formatado += '-' + parte2; }
    return formatado;
}

function ativarMascaraTelefone(input) {
    if (!input) { return; }
    input.addEventListener('input', function () {
        input.value = formatarTelefoneBr(input.value);
    });
}

// Feedback visual (fica verde) quando a senha atinge o mínimo de 6 caracteres
// — usado no cadastro e na troca de senha em "Minha conta".
function ativarFeedbackSenha(input) {
    if (!input) { return; }
    input.addEventListener('input', function () {
        input.classList.toggle('senha-valida', input.value.length >= 6);
    });
}

// Popup de validar e-mail por código -- reaproveitado em qualquer página que
// tenha um botão ".btn-abrir-validar-email" (carrinho, minha conta...).
// Ao validar com sucesso, recarrega a página: mais simples e mais confiável
// do que tentar remendar no JS todo lugar que depende de e-mail verificado.
function iniciarModalValidarEmail() {
    const modal = document.getElementById('modal-validar-email');
    if (!modal) { return; }

    const form = document.getElementById('form-validar-email');
    const campoCodigo = document.getElementById('campo-codigo-email');
    const msgErro = document.getElementById('msg-erro-validar-email');
    const btnNaoRecebi = document.getElementById('btn-nao-recebi-email');

    function abrirModal() {
        msgErro.hidden = true;
        campoCodigo.value = '';
        modal.hidden = false;
        campoCodigo.focus();
    }
    function fecharModal() { modal.hidden = true; }

    document.querySelectorAll('.btn-abrir-validar-email').forEach(function (btn) {
        btn.addEventListener('click', abrirModal);
    });

    document.getElementById('btn-fechar-validar-email').addEventListener('click', fecharModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) { fecharModal(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { fecharModal(); } });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        msgErro.hidden = true;
        fetch('/loja/ajax/validar_codigo_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'codigo=' + encodeURIComponent(campoCodigo.value)
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                msgErro.textContent = data.message;
                msgErro.hidden = false;
            }
        }).catch(function () {
            msgErro.textContent = 'Erro de conexão. Tente novamente.';
            msgErro.hidden = false;
        });
    });

    btnNaoRecebi.addEventListener('click', function () {
        btnNaoRecebi.disabled = true;
        const textoOriginal = btnNaoRecebi.textContent;
        btnNaoRecebi.textContent = 'Enviando...';
        fetch('/loja/ajax/reenviar_verificacao.php', { method: 'POST' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                msgErro.className = 'alert ' + (data.success ? 'alert-sucesso' : 'alert-erro');
                msgErro.textContent = data.message;
                msgErro.hidden = false;
            })
            .catch(function () {
                msgErro.className = 'alert alert-erro';
                msgErro.textContent = 'Erro de conexão. Tente novamente.';
                msgErro.hidden = false;
            })
            .finally(function () {
                btnNaoRecebi.disabled = false;
                btnNaoRecebi.textContent = textoOriginal;
            });
    });
}

// Popup de validar WhatsApp por código -- espelha iniciarModalValidarEmail(),
// mesmo padrão (qualquer botão ".btn-abrir-validar-whatsapp" abre).
function iniciarModalValidarWhatsapp() {
    const modal = document.getElementById('modal-validar-whatsapp');
    if (!modal) { return; }

    const form = document.getElementById('form-validar-whatsapp');
    const campoCodigo = document.getElementById('campo-codigo-whatsapp');
    const msgErro = document.getElementById('msg-erro-validar-whatsapp');
    const btnNaoRecebi = document.getElementById('btn-nao-recebi-whatsapp');

    function abrirModal() {
        msgErro.hidden = true;
        campoCodigo.value = '';
        modal.hidden = false;
        campoCodigo.focus();
    }
    function fecharModal() { modal.hidden = true; }

    document.querySelectorAll('.btn-abrir-validar-whatsapp').forEach(function (btn) {
        btn.addEventListener('click', abrirModal);
    });

    document.getElementById('btn-fechar-validar-whatsapp').addEventListener('click', fecharModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) { fecharModal(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { fecharModal(); } });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        msgErro.hidden = true;
        fetch('/loja/ajax/validar_codigo_whatsapp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'codigo=' + encodeURIComponent(campoCodigo.value)
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                msgErro.textContent = data.message;
                msgErro.hidden = false;
            }
        }).catch(function () {
            msgErro.textContent = 'Erro de conexão. Tente novamente.';
            msgErro.hidden = false;
        });
    });

    btnNaoRecebi.addEventListener('click', function () {
        btnNaoRecebi.disabled = true;
        const textoOriginal = btnNaoRecebi.textContent;
        btnNaoRecebi.textContent = 'Enviando...';
        fetch('/loja/ajax/reenviar_verificacao_whatsapp.php', { method: 'POST' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                msgErro.className = 'alert ' + (data.success ? 'alert-sucesso' : 'alert-erro');
                msgErro.textContent = data.message;
                msgErro.hidden = false;
            })
            .catch(function () {
                msgErro.className = 'alert alert-erro';
                msgErro.textContent = 'Erro de conexão. Tente novamente.';
                msgErro.hidden = false;
            })
            .finally(function () {
                btnNaoRecebi.disabled = false;
                btnNaoRecebi.textContent = textoOriginal;
            });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    iniciarMenuMobile();
    iniciarCarrosseis();
    iniciarModalSair();
    iniciarModalValidarEmail();
    iniciarModalValidarWhatsapp();
});
