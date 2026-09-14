<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$id_usuario = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id_usuario = :id');
$stmt->execute([':id' => $id_usuario]);
$usuario = $stmt->fetch();
if (!$usuario) {
    header('Location: /usuarios/lista.php');
    exit;
}

// Um admin não pode tirar o próprio perfil de Admin nem desativar a própria
// conta por aqui — evita se trancar fora do sistema sem ter outro admin
// disponível para desfazer.
$ehVoceMesmo = $id_usuario === (int) $_SESSION['id_usuario'];

$erro = '';

$trocarSenhaMarcado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $trocarSenha = isset($_POST['trocar_senha']);
    $senha = $_POST['senha'] ?? '';
    $confirmarSenha = $_POST['confirmar_senha'] ?? '';
    $perfil = $_POST['perfil'] === 'Admin' ? 'Admin' : 'Funcionario';
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $trocarSenhaMarcado = $trocarSenha;

    if ($nome === '' || $email === '') {
        $erro = 'Preencha nome e e-mail.';
    } elseif ($trocarSenha && strlen($senha) < 6) {
        $erro = 'A nova senha precisa ter pelo menos 6 caracteres.';
    } elseif ($trocarSenha && $senha !== $confirmarSenha) {
        $erro = 'As senhas não coincidem.';
    } elseif ($ehVoceMesmo && ($perfil !== 'Admin' || $ativo === 0)) {
        $erro = 'Você não pode remover seu próprio perfil de Admin nem desativar sua própria conta.';
    } else {
        $existe = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE email = :email AND id_usuario != :id');
        $existe->execute([':email' => $email, ':id' => $id_usuario]);
        if ($existe->fetch()) {
            $erro = 'Já existe outro usuário com esse e-mail.';
        } else {
            if ($trocarSenha) {
                $pdo->prepare('UPDATE usuarios SET nome = :nome, email = :email, senha_hash = :hash, perfil = :perfil, ativo = :ativo WHERE id_usuario = :id')
                    ->execute([
                        ':nome' => $nome, ':email' => $email, ':hash' => password_hash($senha, PASSWORD_DEFAULT),
                        ':perfil' => $perfil, ':ativo' => $ativo, ':id' => $id_usuario,
                    ]);
            } else {
                $pdo->prepare('UPDATE usuarios SET nome = :nome, email = :email, perfil = :perfil, ativo = :ativo WHERE id_usuario = :id')
                    ->execute([':nome' => $nome, ':email' => $email, ':perfil' => $perfil, ':ativo' => $ativo, ':id' => $id_usuario]);
            }
            header('Location: /usuarios/lista.php?atualizado=1');
            exit;
        }
    }

    $usuario['nome'] = $nome;
    $usuario['email'] = $email;
    $usuario['perfil'] = $perfil;
    $usuario['ativo'] = $ativo;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Editar usuário</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 4a3 3 0 1 0 0-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Editar usuário</h1>
            <span class="subtitulo"><?= htmlspecialchars($usuario['nome']) ?></span>
        </div>
    </div>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($ehVoceMesmo): ?><p class="alert alert-info">Esta é a sua própria conta — perfil e status ficam travados em Admin/Ativo aqui.</p><?php endif; ?>

    <div class="card">
    <form method="post" id="form-editar-usuario">
        <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($usuario['nome']) ?>" required></label>
        <label>E-mail<input type="email" name="email" value="<?= htmlspecialchars($usuario['email']) ?>" required></label>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="trocar_senha" id="trocar-senha" style="width:auto; min-height:0;" <?= $trocarSenhaMarcado ? 'checked' : '' ?>>
            Trocar senha
        </label>
        <div id="campos-senha" <?= $trocarSenhaMarcado ? '' : 'hidden' ?>>
            <label>Nova senha<input type="password" name="senha" id="campo-senha" minlength="6" <?= $trocarSenhaMarcado ? 'required' : '' ?>></label>
            <label>Confirmar nova senha<input type="password" name="confirmar_senha" id="campo-confirmar-senha" minlength="6" <?= $trocarSenhaMarcado ? 'required' : '' ?>></label>
            <p id="erro-senha" class="alert alert-erro" hidden></p>
        </div>
        <label>Perfil
            <select name="perfil" <?= $ehVoceMesmo ? 'disabled' : '' ?>>
                <option value="Funcionario" <?= $usuario['perfil'] === 'Funcionario' ? 'selected' : '' ?>>Funcionário</option>
                <option value="Admin" <?= $usuario['perfil'] === 'Admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </label>
        <?php if ($ehVoceMesmo): ?><input type="hidden" name="perfil" value="Admin"><?php endif; ?>
        <label style="display:flex; align-items:center; gap:8px; flex-direction:row;">
            <input type="checkbox" name="ativo" value="1" style="width:auto; min-height:0;" <?= $usuario['ativo'] ? 'checked' : '' ?> <?= $ehVoceMesmo ? 'disabled' : '' ?>>
            Ativo
        </label>
        <?php if ($ehVoceMesmo): ?><input type="hidden" name="ativo" value="1"><?php endif; ?>
        <button type="submit">Salvar</button>
    </form>
    </div>
    <p><a href="/usuarios/lista.php" class="btn-texto">← Ver usuários</a></p>
<script>
document.getElementById('trocar-senha').addEventListener('change', function () {
    const campoSenha = document.getElementById('campo-senha');
    const campoConfirmar = document.getElementById('campo-confirmar-senha');
    document.getElementById('campos-senha').hidden = !this.checked;
    campoSenha.required = this.checked;
    campoConfirmar.required = this.checked;
    if (!this.checked) {
        campoSenha.value = '';
        campoConfirmar.value = '';
        document.getElementById('erro-senha').hidden = true;
    }
});

document.getElementById('form-editar-usuario').addEventListener('submit', function (e) {
    if (!document.getElementById('trocar-senha').checked) {
        return;
    }
    const erroSenha = document.getElementById('erro-senha');
    const senha = document.getElementById('campo-senha').value;
    const confirmar = document.getElementById('campo-confirmar-senha').value;
    if (senha.length < 6) {
        e.preventDefault();
        erroSenha.textContent = 'A nova senha precisa ter pelo menos 6 caracteres.';
        erroSenha.hidden = false;
        return;
    }
    if (senha !== confirmar) {
        e.preventDefault();
        erroSenha.textContent = 'As senhas não coincidem.';
        erroSenha.hidden = false;
        return;
    }
    erroSenha.hidden = true;
});
</script>
</main>
</body>
</html>
