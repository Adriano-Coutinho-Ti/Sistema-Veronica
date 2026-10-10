<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_dev.php';
exigirDev();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_usuario') {
    $nomeNovo = trim($_POST['nome'] ?? '');
    $emailNovo = mb_strtolower(trim($_POST['email'] ?? ''));
    $senhaNova = $_POST['senha'] ?? '';
    $perfilNovo = ($_POST['perfil'] ?? '') === 'Funcionario' ? 'Funcionario' : 'Admin';

    if ($nomeNovo === '' || !filter_var($emailNovo, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe o nome e um e-mail válido.';
    } elseif (strlen($senhaNova) < 6) {
        $erro = 'A senha precisa ter pelo menos 6 caracteres.';
    } else {
        $existe = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = :email');
        $existe->execute([':email' => $emailNovo]);
        if ($existe->fetchColumn()) {
            $erro = 'Já existe um usuário com esse e-mail.';
        } else {
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (:nome, :email, :hash, :perfil)')
                ->execute([':nome' => $nomeNovo, ':email' => $emailNovo, ':hash' => password_hash($senhaNova, PASSWORD_DEFAULT), ':perfil' => $perfilNovo]);
            $sucesso = 'Usuário criado. Passe o e-mail e a senha provisória pro cliente — ele pode trocar a senha depois.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'resetar_senha') {
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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_usuario') {
    $id_usuario = (int) ($_POST['id_usuario'] ?? 0);

    // Exclusão de verdade (some do banco) -- só vale pra quem já está inativo;
    // o "ativo = 0" no próprio DELETE garante isso mesmo se alguém forjar o POST.
    try {
        $stmtExcluir = $pdo->prepare('DELETE FROM usuarios WHERE id_usuario = :id AND ativo = 0');
        $stmtExcluir->execute([':id' => $id_usuario]);
        if ($stmtExcluir->rowCount() > 0) {
            $sucesso = 'Usuário excluído definitivamente.';
        } else {
            $erro = 'Só dá pra excluir usuário inativo (ou ele já foi excluído).';
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Tem vendas, caixas ou movimentos de crédito ligados a ele -- apagar
            // levaria o histórico junto (ou o banco recusa), então fica só inativo.
            $erro = 'Esse usuário tem histórico no sistema (vendas, caixas ou crédito) e não pode ser excluído sem quebrar esses registros. Deixe ele inativo.';
        } else {
            error_log('excluir_usuario: ' . $e->getMessage());
            $erro = 'Não foi possível excluir o usuário.';
        }
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

    <p><button type="button" class="btn" id="btn-novo-usuario">Novo usuário</button></p>

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
                    <?php if (!$u['ativo']): ?>
                    <button type="button" class="btn-sm btn-perigo btn-excluir-usuario" data-id-usuario="<?= $u['id_usuario'] ?>" data-nome-usuario="<?= htmlspecialchars($u['nome']) ?>">Excluir</button>
                    <?php endif; ?>
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
                <?php if (!$u['ativo']): ?>
                <button type="button" class="btn-sm btn-perigo btn-excluir-usuario" data-id-usuario="<?= $u['id_usuario'] ?>" data-nome-usuario="<?= htmlspecialchars($u['nome']) ?>">Excluir</button>
                <?php endif; ?>
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

    <div class="modal-overlay" id="modal-novo-usuario" <?= ($erro && ($_POST['acao'] ?? '') === 'criar_usuario') ? '' : 'hidden' ?>>
        <div class="modal-card">
            <h3>Novo usuário</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Use pra dar o primeiro acesso ao cliente. A senha é provisória: ele troca quando quiser.</p>
            <?php if ($erro && ($_POST['acao'] ?? '') === 'criar_usuario'): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="acao" value="criar_usuario">
                <label>Nome<input type="text" name="nome" required value="<?= htmlspecialchars((string) ($_POST['nome'] ?? '')) ?>"></label>
                <label>E-mail<input type="email" name="email" required value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>"></label>
                <label>Senha provisória<input type="text" name="senha" minlength="6" required autocomplete="off"></label>
                <label>Perfil
                    <select name="perfil">
                        <option value="Admin" <?= ($_POST['perfil'] ?? 'Admin') === 'Admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="Funcionario" <?= ($_POST['perfil'] ?? '') === 'Funcionario' ? 'selected' : '' ?>>Funcionário</option>
                    </select>
                </label>
                <div class="modal-acoes">
                    <button type="button" class="btn-outline" id="btn-cancelar-novo">Cancelar</button>
                    <button type="submit" class="btn">Criar usuário</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modal-excluir-usuario" hidden>
        <div class="modal-card">
            <h3>Excluir usuário</h3>
            <p id="texto-excluir-usuario" style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;"></p>
            <p class="alert alert-erro">Isso apaga o usuário do banco de dados e não dá pra desfazer.</p>
            <form method="post">
                <input type="hidden" name="acao" value="excluir_usuario">
                <input type="hidden" name="id_usuario" id="input-id-usuario-excluir">
                <div class="modal-acoes">
                    <button type="button" class="btn-outline" id="btn-cancelar-excluir">Cancelar</button>
                    <button type="submit" class="btn-perigo">Excluir definitivamente</button>
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

    const modalNovo = document.getElementById('modal-novo-usuario');
    document.getElementById('btn-novo-usuario').addEventListener('click', function () { modalNovo.hidden = false; });
    document.getElementById('btn-cancelar-novo').addEventListener('click', function () { modalNovo.hidden = true; });
    modalNovo.addEventListener('click', function (e) { if (e.target === modalNovo) { modalNovo.hidden = true; } });

    const modalExcluir = document.getElementById('modal-excluir-usuario');
    document.querySelectorAll('.btn-excluir-usuario').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('texto-excluir-usuario').textContent = 'Excluir ' + btn.dataset.nomeUsuario + '?';
            document.getElementById('input-id-usuario-excluir').value = btn.dataset.idUsuario;
            modalExcluir.hidden = false;
        });
    });
    document.getElementById('btn-cancelar-excluir').addEventListener('click', function () { modalExcluir.hidden = true; });
    modalExcluir.addEventListener('click', function (e) { if (e.target === modalExcluir) { modalExcluir.hidden = true; } });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (!modalExcluir.hidden) { modalExcluir.hidden = true; }
        if (!modalNovo.hidden) { modalNovo.hidden = true; }
        if (!modalSenha.hidden) { modalSenha.hidden = true; }
        if (!modalEditar.hidden) { modalEditar.hidden = true; }
    });
});
</script>
</main>
</body>
</html>
