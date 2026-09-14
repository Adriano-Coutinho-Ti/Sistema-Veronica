<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$usuarios = $pdo->query('SELECT id_usuario, nome, email, perfil, ativo FROM usuarios ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Usuários</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 4a3 3 0 1 0 0-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Usuários</h1>
            <span class="subtitulo"><?= count($usuarios) ?> usuário<?= count($usuarios) === 1 ? '' : 's' ?> cadastrado<?= count($usuarios) === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <?php if (isset($_GET['criado'])): ?><p class="alert alert-sucesso">Usuário criado com sucesso.</p><?php endif; ?>
    <?php if (isset($_GET['atualizado'])): ?><p class="alert alert-sucesso">Usuário atualizado com sucesso.</p><?php endif; ?>
    <p class="acoes-topo"><a href="/usuarios/novo.php" class="btn">+ Novo usuário</a></p>

    <div class="card">
        <?php if (empty($usuarios)): ?>
        <p class="alert alert-info">Nenhum usuário cadastrado.</p>
        <?php else: ?>
        <div class="alternador-visualizacao" data-chave="usuarios" data-alvo-lista="visualizacao-lista" data-alvo-cards="visualizacao-cards">
            <button type="button" class="btn-sm btn-outline" data-modo="lista" title="Ver em lista">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
            <button type="button" class="btn-sm btn-outline" data-modo="cards" title="Ver em cards">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </button>
        </div>

        <div id="visualizacao-lista">
        <div class="tabela-wrap">
        <table>
            <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th><th></th></tr>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['nome']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="status-pill<?= $u['perfil'] === 'Admin' ? ' sucesso' : '' ?>"><?= htmlspecialchars($u['perfil']) ?></span></td>
                <td><span class="status-pill<?= $u['ativo'] ? ' sucesso' : ' erro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                <td><a href="/usuarios/editar.php?id=<?= (int) $u['id_usuario'] ?>" class="btn-sm btn-outline"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M4 20h4l10.5-10.5a2 2 0 0 0 0-2.83l-1.17-1.17a2 2 0 0 0-2.83 0L4 16v4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg> Editar</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        </div>

        <div id="visualizacao-cards" hidden>
        <div class="grade-cards">
            <?php foreach ($usuarios as $u): ?>
            <div class="item-card">
                <div class="item-card-topo">
                    <strong><?= htmlspecialchars($u['nome']) ?></strong>
                    <span class="status-pill<?= $u['perfil'] === 'Admin' ? ' sucesso' : '' ?>"><?= htmlspecialchars($u['perfil']) ?></span>
                </div>
                <p><?= htmlspecialchars($u['email']) ?></p>
                <p><span class="status-pill<?= $u['ativo'] ? ' sucesso' : ' erro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></p>
                <div class="celula-acoes">
                    <a href="/usuarios/editar.php?id=<?= (int) $u['id_usuario'] ?>" class="btn-sm btn-outline">Editar</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
