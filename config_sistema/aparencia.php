<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
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
        $logoArquivo = null;
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $info = getimagesize($_FILES['logo']['tmp_name']);
            $mime = $info['mime'] ?? '';
            $origem = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($_FILES['logo']['tmp_name']),
                'image/png' => imagecreatefrompng($_FILES['logo']['tmp_name']),
                default => null,
            };
            // imagecreatefrom*() retorna false (não null) em falha de decodificação —
            // checar os dois é necessário, checar só null deixa passar decode inválido.
            if ($origem !== null && $origem !== false) {
                $dir = __DIR__ . '/../assets/img/logo/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                imagepng($origem, $dir . 'logo.png', 9);
                imagedestroy($origem);
                $logoArquivo = 'assets/img/logo/logo.png';
            } else {
                $erro = 'Formato de logo inválido (use JPEG ou PNG).';
            }
        }

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
            if ($logoArquivo !== null) {
                $sql .= ', logo_arquivo = :logo';
                $campos[':logo'] = $logoArquivo;
            }
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
    <h1>Aparência da loja</h1>
    <?php if (isset($_GET['salvo'])): ?><p class="alert alert-sucesso">Configuração salva.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <div class="card">
    <?php if (!empty($config['logo_arquivo'])): ?>
        <img src="/<?= htmlspecialchars($config['logo_arquivo']) ?>?v=<?= time() ?>" width="150" alt="Logo atual" style="border-radius:var(--raio-sm); margin-bottom:16px; display:block;">
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <label>Nome da loja<input type="text" name="nome_loja" value="<?= htmlspecialchars($config['nome_loja']) ?>" required></label>
        <label>Logo (JPEG ou PNG)<input type="file" name="logo" accept="image/png,image/jpeg"></label>
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
        <h2>Contato da loja</h2>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-top:-8px;">Aparece no rodapé e no botão flutuante de WhatsApp de todas as páginas da loja online. Deixe em branco o que não se aplica.</p>
        <label>WhatsApp (com DDD)<input type="text" name="whatsapp_loja" placeholder="11987654321" value="<?= htmlspecialchars($config['whatsapp_loja'] ?? '') ?>"></label>
        <label>E-mail<input type="email" name="email_loja" value="<?= htmlspecialchars($config['email_loja'] ?? '') ?>"></label>
        <label>Endereço<input type="text" name="endereco_loja" placeholder="Ex: Atendimento online para todo o país" value="<?= htmlspecialchars($config['endereco_loja'] ?? '') ?>"></label>
        <label>Horário de atendimento<input type="text" name="horario_atendimento" placeholder="Ex: Segunda a sexta, 9h às 18h" value="<?= htmlspecialchars($config['horario_atendimento'] ?? '') ?>"></label>

        <button type="submit">Salvar</button>
    </form>
    </div>
<script>
document.getElementById('tema').addEventListener('change', function () {
    document.getElementById('cores-personalizadas').style.display = this.value === 'personalizado' ? '' : 'none';
});
</script>
</main>
</body>
</html>
