<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_loja = trim($_POST['nome_loja'] ?? '');
    $tema = $_POST['tema'] ?? 'claro';
    $temasValidos = ['claro', 'escuro', 'personalizado'];
    $cor_primaria = trim($_POST['cor_primaria'] ?? '#8B5CF6');
    $cor_secundaria = trim($_POST['cor_secundaria'] ?? '#F472B6');
    $cor_fundo = trim($_POST['cor_fundo'] ?? '#FFFFFF');
    $cor_texto = trim($_POST['cor_texto'] ?? '#1F2937');

    $whatsapp_loja = preg_replace('/\D/', '', $_POST['whatsapp_loja'] ?? '');
    if (in_array(strlen($whatsapp_loja), [10, 11], true)) {
        $whatsapp_loja = '55' . $whatsapp_loja;
    }
    $whatsapp_loja = $whatsapp_loja !== '' ? $whatsapp_loja : null;
    $endereco_loja = trim($_POST['endereco_loja'] ?? '') ?: null;
    $horario_atendimento = trim($_POST['horario_atendimento'] ?? '') ?: null;
    $email_loja = trim($_POST['email_loja'] ?? '') ?: null;

    $coresValidas = preg_match('/^#[0-9A-Fa-f]{6}$/', $cor_primaria)
        && preg_match('/^#[0-9A-Fa-f]{6}$/', $cor_secundaria)
        && preg_match('/^#[0-9A-Fa-f]{6}$/', $cor_fundo)
        && preg_match('/^#[0-9A-Fa-f]{6}$/', $cor_texto);

    if ($nome_loja === '' || !in_array($tema, $temasValidos, true) || !$coresValidas) {
        $erro = 'Informe o nome da loja, um tema válido e cores em formato hexadecimal (#RRGGBB).';
    } else {
        // A logo é enviada à parte, já recortada (config_sistema/ajax/upload_logo.php).
        if ($erro === '') {
            $campos = [
                ':nome' => $nome_loja,
                ':tema' => $tema,
                ':cp' => $cor_primaria,
                ':cs' => $cor_secundaria,
                ':cf' => $cor_fundo,
                ':ct' => $cor_texto,
                ':wa' => $whatsapp_loja,
                ':end' => $endereco_loja,
                ':hor' => $horario_atendimento,
                ':email' => $email_loja,
            ];
            $sql = 'UPDATE config_loja SET nome_loja = :nome, tema = :tema, cor_primaria = :cp, cor_secundaria = :cs, cor_fundo = :cf, cor_texto = :ct,
                    whatsapp_loja = :wa, endereco_loja = :end, horario_atendimento = :hor, email_loja = :email';
            $sql .= ' WHERE id_config = 1';
            $pdo->prepare($sql)->execute($campos);
            header('Location: /config_sistema/aparencia.php?salvo=1');
            exit;
        }
    }
}

$config = $pdo->query('SELECT * FROM config_loja WHERE id_config = 1')->fetch();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Aparência da loja</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.5-.7 1.5-1.5 0-.4-.15-.75-.4-1.05-.25-.3-.4-.65-.4-1.05 0-.83.67-1.5 1.5-1.5H16a4 4 0 0 0 4-4c0-4.42-3.58-8-8-8Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7.5" cy="10.5" r="1.1" fill="currentColor"/><circle cx="11" cy="7" r="1.1" fill="currentColor"/><circle cx="15.5" cy="8" r="1.1" fill="currentColor"/></svg></span>
        <div>
            <h1>Aparência da loja</h1>
            <span class="subtitulo">Tema, logo e contato exibidos na loja online</span>
        </div>
    </div>
    <?php if (isset($_GET['salvo'])): ?><p class="alert alert-sucesso">Configuração salva.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
    <div class="grade-2col">
        <div class="card">
            <h2>Identidade visual</h2>
            <?php if (!empty($config['logo_arquivo'])): ?>
                <img id="logo-previa" src="/<?= htmlspecialchars($config['logo_arquivo']) ?>" width="150" alt="Logo atual" onerror="this.style.display='none'" style="border-radius:var(--raio-sm); margin-bottom:16px; display:block;">
            <?php endif; ?>
            <label>Nome da loja<input type="text" name="nome_loja" value="<?= htmlspecialchars($config['nome_loja']) ?>" required></label>
            <div style="margin-bottom:16px;">
                <span style="display:block; margin-bottom:6px;">Logo (JPEG ou PNG)</span>
                <button type="button" class="btn-outline btn-sm" id="btn-trocar-logo"><?= empty($config['logo_arquivo']) ? 'Escolher logo' : 'Trocar logo' ?></button>
                <input type="file" id="input-logo" accept="image/png,image/jpeg,image/webp" style="display:none;">
                <p id="msg-logo" class="alert" style="display:none; margin-top:10px;"></p>
            </div>
            <label>Tema
                <select name="tema" id="tema">
                    <option value="claro" <?= $config['tema'] === 'claro' ? 'selected' : '' ?>>Claro</option>
                    <option value="escuro" <?= $config['tema'] === 'escuro' ? 'selected' : '' ?>>Escuro</option>
                    <option value="personalizado" <?= $config['tema'] === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                </select>
            </label>
            <div id="cores-personalizadas" class="form-linha" style="<?= $config['tema'] !== 'personalizado' ? 'display:none;' : '' ?>">
                <label>Cor primária<input type="color" name="cor_primaria" value="<?= htmlspecialchars($config['cor_primaria']) ?>"></label>
                <label>Cor secundária<input type="color" name="cor_secundaria" value="<?= htmlspecialchars($config['cor_secundaria']) ?>"></label>
                <label>Cor de fundo<input type="color" name="cor_fundo" value="<?= htmlspecialchars($config['cor_fundo']) ?>"></label>
                <label>Cor do texto<input type="color" name="cor_texto" value="<?= htmlspecialchars($config['cor_texto']) ?>"></label>
            </div>
        </div>

        <div class="card">
            <h2>Contato da loja</h2>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-top:-8px;">Aparece no rodapé e no botão flutuante de WhatsApp de todas as páginas da loja online. Deixe em branco o que não se aplica.</p>
            <label>WhatsApp (com DDD)<input type="text" name="whatsapp_loja" id="campo-whatsapp-loja" placeholder="(11) 90000-0000" value="<?= htmlspecialchars(formatarWhatsappParaEdicao($config['whatsapp_loja'] ?? '')) ?>"></label>
            <label>E-mail<input type="email" name="email_loja" value="<?= htmlspecialchars($config['email_loja'] ?? '') ?>"></label>
            <label>Endereço<input type="text" name="endereco_loja" placeholder="Ex: Atendimento online para todo o país" value="<?= htmlspecialchars($config['endereco_loja'] ?? '') ?>"></label>
            <label>Horário de atendimento<input type="text" name="horario_atendimento" placeholder="Ex: Segunda a sexta, 9h às 18h" value="<?= htmlspecialchars($config['horario_atendimento'] ?? '') ?>"></label>
        </div>
    </div>

    <button type="submit" class="btn-bloco" style="max-width:300px; margin-top:20px;">Salvar</button>
    </form>

    <div class="modal-overlay" id="modal-recorte-logo" hidden>
        <div class="modal-card">
            <h3>Ajustar logo</h3>
            <div style="display:flex; gap:8px; margin-bottom:10px;">
                <button type="button" class="btn-sm btn-outline" id="btn-corte-quadrado">Quadrado</button>
                <button type="button" class="btn-sm btn-outline" id="btn-corte-livre">Livre</button>
            </div>
            <div class="area-corte"><img id="imagem-recorte-logo" alt=""></div>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-cancelar-recorte-logo">Cancelar</button>
                <button type="button" class="btn" id="btn-confirmar-recorte-logo">Cortar e enviar</button>
            </div>
        </div>
    </div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script>
document.getElementById('tema').addEventListener('change', function () {
    document.getElementById('cores-personalizadas').style.display = this.value === 'personalizado' ? '' : 'none';
});
document.addEventListener('DOMContentLoaded', function () {
    ativarMascaraTelefoneComNoveAutomatico(document.getElementById('campo-whatsapp-loja'));
});
</script>
<script>
// Logo: escolhe o arquivo, recorta (quadrado ou livre) e envia já cortada — a mesma técnica das fotos de produto.
(function () {
    const input = document.getElementById('input-logo');
    const modal = document.getElementById('modal-recorte-logo');
    const imagem = document.getElementById('imagem-recorte-logo');
    const msg = document.getElementById('msg-logo');
    let cropper = null;

    function aviso(texto, ok) {
        msg.textContent = texto;
        msg.className = 'alert ' + (ok ? 'alert-sucesso' : 'alert-erro');
        msg.style.display = '';
    }
    function fechar() {
        modal.hidden = true;
        if (cropper) { cropper.destroy(); cropper = null; }
        input.value = '';
    }

    document.getElementById('btn-trocar-logo').addEventListener('click', function () { input.click(); });

    input.addEventListener('change', function () {
        const arquivo = input.files && input.files[0];
        if (!arquivo) { return; }
        const leitor = new FileReader();
        leitor.onload = function (e) {
            imagem.src = e.target.result;
            modal.hidden = false;
            if (cropper) { cropper.destroy(); }
            cropper = new Cropper(imagem, { aspectRatio: 1, viewMode: 1, autoCropArea: 1, background: false });
        };
        leitor.readAsDataURL(arquivo);
    });

    document.getElementById('btn-corte-quadrado').addEventListener('click', function () { if (cropper) { cropper.setAspectRatio(1); } });
    document.getElementById('btn-corte-livre').addEventListener('click', function () { if (cropper) { cropper.setAspectRatio(NaN); } });
    document.getElementById('btn-cancelar-recorte-logo').addEventListener('click', fechar);
    modal.addEventListener('click', function (e) { if (e.target === modal) { fechar(); } });

    document.getElementById('btn-confirmar-recorte-logo').addEventListener('click', function () {
        if (!cropper) { return; }
        const botao = this;
        botao.disabled = true;
        cropper.getCroppedCanvas({ maxWidth: 600, maxHeight: 600 }).toBlob(function (blob) {
            const dados = new FormData();
            dados.append('logo', blob, 'logo.png');
            fetch('/config_sistema/ajax/upload_logo.php', { method: 'POST', body: dados })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        let previa = document.getElementById('logo-previa');
                        if (!previa) {
                            previa = document.createElement('img');
                            previa.id = 'logo-previa';
                            previa.width = 150;
                            previa.alt = 'Logo atual';
                            previa.style.cssText = 'border-radius:var(--raio-sm); margin-bottom:16px; display:block;';
                            const card = input.closest('.card');
                            card.insertBefore(previa, card.querySelector('label'));
                        }
                        previa.style.display = 'block';
                        previa.src = d.url;
                        aviso('Logo atualizada.', true);
                    } else {
                        aviso(d.message || 'Não foi possível enviar a logo.', false);
                    }
                })
                .catch(function () { aviso('Erro de conexão. Tente novamente.', false); })
                .finally(function () { botao.disabled = false; fechar(); });
        }, 'image/png');
    });
})();
</script>
</main>
</body>
</html>
