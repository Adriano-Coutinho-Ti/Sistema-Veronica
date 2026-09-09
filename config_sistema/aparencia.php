<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_loja = trim($_POST['nome_loja'] ?? '');
    $cor_primaria = trim($_POST['cor_primaria'] ?? '#8B5CF6');
    $cor_secundaria = trim($_POST['cor_secundaria'] ?? '#F472B6');

    if ($nome_loja === '') {
        $erro = 'Informe o nome da loja.';
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
            // imagecreatefrom*() returns false (not null) on a decode failure, even when
            // getimagesize() already confirmed the mime type — must catch both.
            if ($origem !== null && $origem !== false) {
                $dir = __DIR__ . '/../assets/img/loja/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                imagepng($origem, $dir . 'logo.png', 9);
                imagedestroy($origem);
                $logoArquivo = 'assets/img/loja/logo.png';
            } else {
                $erro = 'Formato de logo inválido (use JPEG ou PNG).';
            }
        }

        if ($erro === '') {
            if ($logoArquivo !== null) {
                $pdo->prepare('UPDATE config_loja SET nome_loja = :nome, cor_primaria = :cp, cor_secundaria = :cs, logo_arquivo = :logo WHERE id_config = 1')
                    ->execute([':nome' => $nome_loja, ':cp' => $cor_primaria, ':cs' => $cor_secundaria, ':logo' => $logoArquivo]);
            } else {
                $pdo->prepare('UPDATE config_loja SET nome_loja = :nome, cor_primaria = :cp, cor_secundaria = :cs WHERE id_config = 1')
                    ->execute([':nome' => $nome_loja, ':cp' => $cor_primaria, ':cs' => $cor_secundaria]);
            }
            header('Location: /config_sistema/aparencia.php?salvo=1');
            exit;
        }
    }
}

$config = $pdo->query('SELECT * FROM config_loja WHERE id_config = 1')->fetch();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Aparência da loja</title></head>
<body>
    <h1>Aparência da loja</h1>
    <?php if (isset($_GET['salvo'])): ?><p style="color:green;">Configuração salva.</p><?php endif; ?>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if (!empty($config['logo_arquivo'])): ?>
        <img src="/<?= htmlspecialchars($config['logo_arquivo']) ?>?v=<?= time() ?>" width="150" alt="Logo atual"><br>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <label>Nome da loja<br><input type="text" name="nome_loja" value="<?= htmlspecialchars($config['nome_loja']) ?>" required></label><br>
        <label>Logo (JPEG ou PNG)<br><input type="file" name="logo" accept="image/png,image/jpeg"></label><br>
        <label>Cor primária<br><input type="color" name="cor_primaria" value="<?= htmlspecialchars($config['cor_primaria']) ?>"></label><br>
        <label>Cor secundária<br><input type="color" name="cor_secundaria" value="<?= htmlspecialchars($config['cor_secundaria']) ?>"></label><br>
        <button type="submit">Salvar</button>
    </form>
</body>
</html>
