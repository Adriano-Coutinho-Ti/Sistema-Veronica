<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_dev.php';
exigirDev();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'resetar_senha') {
    $id_usuario = (int) ($_POST['id_usuario'] ?? 0);
    $novaSenha = $_POST['nova_senha'] ?? '';

    if (strlen($novaSenha) < 6) {
        $erro = 'A nova senha precisa ter pelo menos 6 caracteres.';
    } else {
        $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id_usuario = :id')
            ->execute([':hash' => $hash, ':id' => $id_usuario]);
        $sucesso = 'Senha redefinida com sucesso.';
    }
}

$usuarios = $pdo->query('SELECT id_usuario, nome, email, perfil, ativo FROM usuarios ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Usuários da loja — Painel do desenvolvedor</title></head>
<body>
<?php require __DIR__ . '/../includes/painel_dev_header.php'; ?>
    <div class="page-title">
        <h1>Usuários da loja</h1>
        <span class="subtitulo"><?= count($usuarios) ?> usuário<?= count($usuarios) === 1 ? '' : 's' ?></span>
    </div>

    <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:16px;">Use isso só pra dar suporte quando alguém (Admin ou Funcionário) esquece a senha. A pessoa vai precisar da senha nova que você definir aqui.</p>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <div class="card">
        <div class="tabela-wrap">
        <table>
            <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th><th></th></tr>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nome']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="status-pill<?= $u['perfil'] === 'Admin' ? ' sucesso' : '' ?>"><?= htmlspecialchars($u['perfil']) ?></span></td>
                <td><span class="status-pill<?= $u['ativo'] ? ' sucesso' : ' erro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                <td>
                    <button type="button" class="btn-sm btn-outline btn-resetar-senha" data-id-usuario="<?= $u['id_usuario'] ?>" data-nome-usuario="<?= htmlspecialchars($u['nome']) ?>">Resetar senha</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>

    <div class="modal-overlay" id="modal-resetar-senha" hidden>
        <div class="modal-card">
            <h3>Resetar senha</h3>
            <p id="texto-resetar-senha" style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;"></p>
            <form method="post" id="form-resetar-senha">
                <input type="hidden" name="acao" value="resetar_senha">
                <input type="hidden" name="id_usuario" id="input-id-usuario">
                <label>Nova senha<input type="password" name="nova_senha" minlength="6" required></label>
                <div class="modal-acoes">
                    <button type="button" class="btn-outline" id="btn-cancelar-reset">Cancelar</button>
                    <button type="submit" class="btn">Salvar nova senha</button>
                </div>
            </form>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modal-resetar-senha');
    const texto = document.getElementById('texto-resetar-senha');
    const inputId = document.getElementById('input-id-usuario');

    function fecharModal() { modal.hidden = true; }

    document.querySelectorAll('.btn-resetar-senha').forEach(function (btn) {
        btn.addEventListener('click', function () {
            texto.textContent = 'Definindo uma nova senha pra ' + btn.dataset.nomeUsuario + '.';
            inputId.value = btn.dataset.idUsuario;
            modal.hidden = false;
        });
    });

    document.getElementById('btn-cancelar-reset').addEventListener('click', fecharModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) { fecharModal(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { fecharModal(); } });
});
</script>
</main>
</body>
</html>
