<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/whatsapp_conexao.php';
require_once __DIR__ . '/../includes/loja.php';
exigirAdmin();

// Sem o recurso habilitado pelo dev, a tela nem existe.
if (!whatsappModuloDevAtivo($pdo)) {
    header('Location: /dashboard.php');
    exit;
}

$row = whatsappConexaoLer($pdo);
$tabelaOk = $row !== null;
$aceitou = whatsappConexaoAceiteOk($row);
$estado = $row['estado'] ?? 'desconectado';
$numero = $row['numero'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>WhatsApp da loja</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <div>
            <h1>WhatsApp da loja</h1>
            <span class="subtitulo">Conecte o número da loja para os clientes validarem e entrarem pelo WhatsApp</span>
        </div>
    </div>

    <?php if (!$tabelaOk): ?>
    <p class="alert alert-erro">O banco ainda não tem a tabela do WhatsApp. Avise o desenvolvedor do sistema para atualizar o banco.</p>
    <?php else: ?>

    <div class="card" style="max-width:560px;">
        <p style="margin-top:0;">Situação:
            <span class="status-pill" id="wa-estado">—</span>
            <span id="wa-numero" style="margin-left:8px;"></span>
        </p>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem;">Sem um número conectado, o cliente usa só o e-mail na loja. Conectado, ele também pode validar e entrar pelo número de WhatsApp.</p>

        <?php if (!$aceitou): ?>
        <div id="wa-aceite">
            <p class="alert alert-info" style="font-size:0.9rem;"><?= htmlspecialchars(WHATSAPP_ACEITE_TEXTO) ?></p>
            <label style="flex-direction:row; align-items:center; gap:8px;">
                <input type="checkbox" id="wa-aceite-check" style="width:auto;"> Li e concordo
            </label>
        </div>
        <?php endif; ?>

        <p id="wa-msg" class="alert alert-erro" style="display:none;"></p>

        <div id="wa-qr-area" style="display:none; text-align:center; margin:14px 0;">
            <p style="margin-bottom:8px;">No celular da loja: WhatsApp → Aparelhos conectados → Conectar um aparelho → leia este código.</p>
            <img id="wa-qr" alt="QR code do WhatsApp" style="max-width:260px; width:100%; background:#fff; padding:8px; border-radius:8px;">
            <p id="wa-codigo" style="margin-top:8px; font-family:monospace; font-size:1.1rem;"></p>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;">
            <button type="button" class="btn" id="wa-conectar">Conectar WhatsApp</button>
            <button type="button" class="btn-outline btn-perigo" id="wa-desconectar" style="display:none;">Desconectar</button>
        </div>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:14px;">Para trocar de número: desconecte e conecte de novo lendo o QR no outro celular.</p>
    </div>
    <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnConectar = document.getElementById('wa-conectar');
    if (!btnConectar) { return; }
    const btnDesconectar = document.getElementById('wa-desconectar');
    const elEstado = document.getElementById('wa-estado');
    const elNumero = document.getElementById('wa-numero');
    const msg = document.getElementById('wa-msg');
    const qrArea = document.getElementById('wa-qr-area');
    const rotulos = { conectado: 'Conectado', conectando: 'Aguardando leitura do QR', desconectado: 'Desconectado' };
    let aceitou = <?= json_encode($aceitou) ?>;
    let aguardandoQr = false;

    function post(acao) {
        return fetch('/config_sistema/ajax/whatsapp.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=' + acao
        }).then(function (r) { return r.json(); });
    }
    function erro(texto) { msg.textContent = texto; msg.style.display = texto ? '' : 'none'; }
    function formatarNumero(n) {
        if (!n) { return ''; }
        const m = n.match(/^55(\d{2})(\d{5})(\d{4})$/);
        return m ? '+55 (' + m[1] + ') ' + m[2] + '-' + m[3] : '+' + n;
    }
    function pintar(estado, numero) {
        elEstado.textContent = rotulos[estado] || estado;
        elEstado.className = 'status-pill' + (estado === 'conectado' ? ' sucesso' : (estado === 'conectando' ? ' alerta' : ' erro'));
        elNumero.textContent = estado === 'conectado' ? formatarNumero(numero) : '';
        btnDesconectar.style.display = estado === 'conectado' ? '' : 'none';
        btnConectar.style.display = estado === 'conectado' ? 'none' : '';
        if (estado === 'conectado') { qrArea.style.display = 'none'; aguardandoQr = false; }
    }
    function atualizar() {
        post('estado').then(function (d) {
            if (!d.success) { return; }
            pintar(d.estado, d.numero);
            if (d.erro && !aguardandoQr) { erro(d.erro); }
        }).catch(function () {});
    }

    btnConectar.addEventListener('click', function () {
        erro('');
        const check = document.getElementById('wa-aceite-check');
        const seguir = function () {
            btnConectar.disabled = true;
            post('conectar').then(function (d) {
                btnConectar.disabled = false;
                if (!d.success) { erro(d.message); return; }
                if (d.estado === 'conectado') { atualizar(); return; }
                if (!d.qr) { erro('O servidor não devolveu o QR code. Tente de novo em instantes.'); return; }
                document.getElementById('wa-qr').src = d.qr.indexOf('data:') === 0 ? d.qr : 'data:image/png;base64,' + d.qr;
                document.getElementById('wa-codigo').textContent = d.codigo ? 'Código de pareamento: ' + d.codigo : '';
                qrArea.style.display = '';
                aguardandoQr = true;
                pintar('conectando', null);
            }).catch(function () { btnConectar.disabled = false; erro('Erro de conexão. Tente novamente.'); });
        };
        if (!aceitou) {
            if (!check || !check.checked) { erro('Marque "Li e concordo" para continuar.'); return; }
            post('aceitar').then(function (d) {
                if (!d.success) { erro(d.message); return; }
                aceitou = true;
                const bloco = document.getElementById('wa-aceite');
                if (bloco) { bloco.style.display = 'none'; }
                seguir();
            });
        } else {
            seguir();
        }
    });

    btnDesconectar.addEventListener('click', function () {
        confirmarAcao('Desconectar o WhatsApp da loja? Enquanto não houver número conectado, os clientes usam só o e-mail.').then(function (ok) {
            if (!ok) { return; }
            post('desconectar').then(function (d) {
                if (!d.success) { erro(d.message); return; }
                pintar('desconectado', null);
            });
        });
    });

    pintar(<?= json_encode($estado) ?>, <?= json_encode($numero) ?>);
    atualizar();
    setInterval(atualizar, 4000);
});
</script>
</main>
</body>
</html>
