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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_usuario') {
    $id_usuario = (int) ($_POST['id_usuario'] ?? 0);
    $perfil = $_POST['perfil'] === 'Admin' ? 'Admin' : 'Funcionario';
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    // Suporte não pode deixar a loja com zero Admin ativo -- ninguém de lá
    // conseguiria mais nem entrar em usuarios/editar.php pra corrigir.
    $ficariaSemAdmin = $pdo->prepare(
        "SELECT COUNT(*) FROM usuarios WHERE perfil = 'Admin' AND ativo = 1 AND id_usuario != :id"
    );
    $ficariaSemAdmin->execute([':id' => $id_usuario]);
    $outrosAdminsAtivos = (int) $ficariaSemAdmin->fetchColumn();

    if ($outrosAdminsAtivos === 0 && ($perfil !== 'Admin' || $ativo === 0)) {
        $erro = 'Esse é o único Admin ativo da loja — não dá pra tirar o perfil de Admin nem desativar sem deixar outro Admin ativo antes.';
    } else {
        $pdo->prepare('UPDATE usuarios SET perfil = :perfil, ativo = :ativo WHERE id_usuario = :id')
            ->execute([':perfil' => $perfil, ':ativo' => $ativo, ':id' => $id_usuario]);
        $sucesso = 'Usuário atualizado.';
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

    <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:16px;">Use isso só pra dar suporte: resetar senha esquecida, mudar perfil ou desativar um usuário quando a loja não tem um Admin disponível pra resolver sozinha.</p>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <div class="alternador-visualizacao" data-chave="usuarios-dev" data-alvo-lista="visualizacao-lista" data-alvo-cards="visualizacao-cards">
        <button type="button" class="btn-sm btn-outline" data-modo="lista" title="Ver em lista">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
        <button type="button" class="btn-sm btn-outline" data-modo="cards" title="Ver em cards">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        </button>
    </div>

    <div id="visualizacao-lista">
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
                <td style="white-space:nowrap;">
                    <button type="button" class="btn-sm btn-outline btn-editar-usuario" data-id-usuario="<?= $u['id_usuario'] ?>" data-nome-usuario="<?= htmlspecialchars($u['nome']) ?>" data-perfil="<?= htmlspecialchars($u['perfil']) ?>" data-ativo="<?= $u['ativo'] ?>">Editar</button>
                    <button type="button" class="btn-sm btn-outline btn-resetar-senha" data-id-usuario="<?= $u['id_usuario'] ?>" data-nome-usuario="<?= htmlspecialchars($u['nome']) ?>">Resetar senha</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
    </div>

    <div id="visualizacao-cards" hidden>
    <div class="grade-cards">
        <?php foreach ($usuarios as $u): ?>
        <div class="item-card">
            <div class="item-card-topo">
                <strong><?= htmlspecialchars($u['nome']) ?></strong>
                <span class="status-pill<?= $u['ativo'] ? ' sucesso' : ' erro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span>
            </div>
            <p><?= htmlspecialchars($u['email']) ?></p>
            <p><span class="status-pill<?= $u['perfil'] === 'Admin' ? ' sucesso' : '' ?>"><?= htmlspecialchars($u['perfil']) ?></span></p>
            <div class="celula-acoes">
                <button type="button" class="btn-sm btn-outline btn-editar-usuario" data-id-usuario="<?= $u['id_usuario'] ?>" data-nome-usuario="<?= htmlspecialchars($u['nome']) ?>" data-perfil="<?= htmlspecialchars($u['perfil']) ?>" data-ativo="<?= $u['ativo'] ?>">Editar</button>
                <button type="button" class="btn-sm btn-outline btn-resetar-senha" data-id-usuario="<?= $u['id_usuario'] ?>" data-nome-usuario="<?= htmlspecialchars($u['nome']) ?>">Resetar senha</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    </div>

    <div class="modal-overlay" id="modal-editar-usuario" <?= ($erro && ($_POST['acao'] ?? '') === 'atualizar_usuario') ? '' : 'hidden' ?>>
        <div class="modal-card">
            <h3>Editar usuário</h3>
            <p id="texto-editar-usuario" style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;"></p>
            <?php if ($erro && ($_POST['acao'] ?? '') === 'atualizar_usuario'): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="acao" value="atualizar_usuario">
                <input type="hidden" name="id_usuario" id="input-id-usuario-editar" value="<?= htmlspecialchars((string) ($_POST['id_usuario'] ?? '')) ?>">
                <label>Perfil
                    <select name="perfil" id="input-perfil-editar">
                        <option value="Admin" <?= ($_POST['perfil'] ?? '') === 'Admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="Funcionario" <?= ($_POST['perfil'] ?? '') === 'Funcionario' ? 'selected' : '' ?>>Funcionário</option>
                    </select>
                </label>
                <label style="flex-direction:row; align-items:center; gap:8px;">
                    <input type="checkbox" name="ativo" value="1" id="input-ativo-editar" style="width:auto;" <?= isset($_POST['ativo']) ? 'checked' : '' ?>>
                    Usuário ativo (pode fazer login)
                </label>
                <div class="modal-acoes">
                    <button type="button" class="btn-outline" id="btn-cancelar-editar">Cancelar</button>
                    <button type="submit" class="btn">Salvar</button>
                </div>
            </form>
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
    const modalSenha = document.getElementById('modal-resetar-senha');
    const textoSenha = document.getElementById('texto-resetar-senha');
    const inputIdSenha = document.getElementById('input-id-usuario');

    document.querySelectorAll('.btn-resetar-senha').forEach(function (btn) {
        btn.addEventListener('click', function () {
            textoSenha.textContent = 'Definindo uma nova senha pra ' + btn.dataset.nomeUsuario + '.';
            inputIdSenha.value = btn.dataset.idUsuario;
            modalSenha.hidden = false;
        });
    });

    document.getElementById('btn-cancelar-reset').addEventListener('click', function () { modalSenha.hidden = true; });
    modalSenha.addEventListener('click', function (e) { if (e.target === modalSenha) { modalSenha.hidden = true; } });

    const modalEditar = document.getElementById('modal-editar-usuario');
    const textoEditar = document.getElementById('texto-editar-usuario');
    const inputIdEditar = document.getElementById('input-id-usuario-editar');
    const inputPerfilEditar = document.getElementById('input-perfil-editar');
    const inputAtivoEditar = document.getElementById('input-ativo-editar');

    document.querySelectorAll('.btn-editar-usuario').forEach(function (btn) {
        btn.addEventListener('click', function () {
            textoEditar.textContent = 'Editando ' + btn.dataset.nomeUsuario + '.';
            inputIdEditar.value = btn.dataset.idUsuario;
            inputPerfilEditar.value = btn.dataset.perfil;
            inputAtivoEditar.checked = btn.dataset.ativo === '1';
            modalEditar.hidden = false;
        });
    });

    document.getElementById('btn-cancelar-editar').addEventListener('click', function () { modalEditar.hidden = true; });
    modalEditar.addEventListener('click', function (e) { if (e.target === modalEditar) { modalEditar.hidden = true; } });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (!modalSenha.hidden) { modalSenha.hidden = true; }
        if (!modalEditar.hidden) { modalEditar.hidden = true; }
    });
});
</script>
</main>
</body>
</html>
