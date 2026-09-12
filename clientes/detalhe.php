<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
exigirLogin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    echo 'Cliente não encontrado.';
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_dados') {
    $nome = trim($_POST['nome'] ?? '');
    $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    if (strlen($whatsapp) === 10 || strlen($whatsapp) === 11) {
        $whatsapp = '55' . $whatsapp;
    }
    $email = trim($_POST['email'] ?? '');
    $email = $email !== '' ? mb_strtolower($email) : null;
    $endereco = trim($_POST['endereco'] ?? '') ?: null;

    if ($nome === '' || strlen($whatsapp) < 10) {
        $erro = 'Informe nome e um WhatsApp válido (com DDD).';
    } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido (ou deixe em branco).';
    } else {
        $existe = $pdo->prepare('SELECT id_cliente FROM clientes WHERE whatsapp = :whatsapp AND id_cliente != :id');
        $existe->execute([':whatsapp' => $whatsapp, ':id' => $id]);
        $existeEmail = $email !== null ? $pdo->prepare('SELECT id_cliente FROM clientes WHERE email = :email AND id_cliente != :id') : null;
        if ($existeEmail) {
            $existeEmail->execute([':email' => $email, ':id' => $id]);
        }

        if ($existe->fetch()) {
            $erro = 'Já existe outro cliente cadastrado com esse WhatsApp.';
        } elseif ($existeEmail && $existeEmail->fetch()) {
            $erro = 'Já existe outro cliente cadastrado com esse e-mail.';
        } else {
            $pdo->prepare('UPDATE clientes SET nome = :nome, whatsapp = :whatsapp, email = :email, endereco = :endereco WHERE id_cliente = :id')
                ->execute([':nome' => $nome, ':whatsapp' => $whatsapp, ':email' => $email, ':endereco' => $endereco, ':id' => $id]);
            $sucesso = 'Dados atualizados.';
            $cliente['nome'] = $nome;
            $cliente['whatsapp'] = $whatsapp;
            $cliente['email'] = $email;
            $cliente['endereco'] = $endereco;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ativar_email_manual') {
    // Alguns clientes (idosos, quem não usa e-mail no dia a dia) não conseguem
    // clicar no link de confirmação sozinhos — a loja verifica pessoalmente
    // (WhatsApp, telefone) e ativa por eles.
    if (empty($cliente['email'])) {
        $erro = 'Esse cliente não tem e-mail cadastrado. Cadastre o e-mail antes de ativar.';
    } else {
        $pdo->prepare('UPDATE clientes SET email_verificado_em = NOW(), token_verificacao_email = NULL, token_verificacao_expira_em = NULL WHERE id_cliente = :id')
            ->execute([':id' => $id]);
        $sucesso = 'E-mail ativado manualmente. O cliente já pode usar o carrinho.';
        $cliente['email_verificado_em'] = date('Y-m-d H:i:s');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_limite') {
    if (($_SESSION['perfil'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }

    $novoLimite = (float) str_replace(',', '.', $_POST['limite_credito'] ?? '0');
    if ($novoLimite < 0) {
        $erro = 'Limite inválido.';
    } else {
        $pdo->prepare('UPDATE clientes SET limite_credito = :limite WHERE id_cliente = :id')
            ->execute([':limite' => $novoLimite, ':id' => $id]);
        $sucesso = 'Limite atualizado.';
        $cliente['limite_credito'] = $novoLimite;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'registrar_pagamento') {
    $valor = (float) str_replace(',', '.', $_POST['valor'] ?? '0');
    $forma = trim($_POST['forma_pagamento'] ?? '');
    $formasValidas = ['Dinheiro', 'Débito', 'Crédito', 'Pix'];
    // Pagamento de dívida recebido no balcão entra na conferência do caixa do dia, então
    // exige um caixa aberto e registra em qual sessão o dinheiro entrou.
    $caixaAberto = caixaAbertoAtual($pdo);

    if ($valor <= 0 || !in_array($forma, $formasValidas, true)) {
        $erro = 'Dados de pagamento inválidos.';
    } elseif (!$caixaAberto) {
        $erro = 'Abra um caixa antes de registrar pagamentos de dívida.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtAbater = $pdo->prepare(
                'UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor)
                 WHERE id_cliente = :id AND saldo_devedor >= :valor2'
            );
            $stmtAbater->execute([':valor' => $valor, ':valor2' => $valor, ':id' => $id]);

            if ($stmtAbater->rowCount() === 0) {
                throw new Exception('Valor maior que a dívida atual.');
            }

            $pdo->prepare(
                "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, forma_pagamento, criado_por, id_caixa)
                 VALUES (:ic, 'pagamento', 'Confirmado', :valor, :forma, :criado_por, :id_caixa)"
            )->execute([
                ':ic' => $id,
                ':valor' => $valor,
                ':forma' => $forma,
                ':criado_por' => $_SESSION['id_usuario'],
                ':id_caixa' => $caixaAberto['id_caixa'],
            ]);

            $pdo->commit();
            $sucesso = 'Pagamento registrado com sucesso.';

            $stmt2 = $pdo->prepare('SELECT saldo_devedor FROM clientes WHERE id_cliente = :id');
            $stmt2->execute([':id' => $id]);
            $cliente['saldo_devedor'] = $stmt2->fetchColumn();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof Exception && !($e instanceof PDOException)) {
                $erro = $e->getMessage();
            } else {
                error_log('Erro ao registrar pagamento de dívida: ' . $e->getMessage());
                $erro = 'Erro ao registrar pagamento. Tente novamente.';
            }
        }
    }
}

$stmtExtrato = $pdo->prepare(
    'SELECT mc.*, u.nome AS nome_funcionario
     FROM movimentos_credito mc
     LEFT JOIN usuarios u ON u.id_usuario = mc.criado_por
     WHERE mc.id_cliente = :id
     ORDER BY mc.data_movimento DESC'
);
$stmtExtrato->execute([':id' => $id]);
$extrato = $stmtExtrato->fetchAll();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($cliente['nome']) ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1><?= htmlspecialchars($cliente['nome']) ?></h1>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <div class="grade-2col">
    <div class="card">
    <h2>Dados</h2>
    <form method="post">
        <input type="hidden" name="acao" value="atualizar_dados">
        <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($cliente['nome']) ?>" required></label>
        <label>WhatsApp (com DDD)<input type="text" name="whatsapp" value="<?= htmlspecialchars($cliente['whatsapp']) ?>" required></label>
        <label>E-mail (obrigatório pro cliente conseguir entrar na loja online)<input type="email" name="email" value="<?= htmlspecialchars($cliente['email'] ?? '') ?>"></label>
        <label>Endereço<input type="text" name="endereco" value="<?= htmlspecialchars($cliente['endereco'] ?? '') ?>"></label>
        <button type="submit">Salvar dados</button>
    </form>
    </div>

    <div class="card">
    <h2>E-mail da loja online</h2>
    <?php if (empty($cliente['email'])): ?>
    <p class="alert alert-info">Sem e-mail cadastrado — o cliente não consegue entrar na loja online até ter um e-mail.</p>
    <?php elseif (!empty($cliente['email_verificado_em'])): ?>
    <p class="status-pill sucesso">✓ Verificado em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($cliente['email_verificado_em']))) ?></p>
    <?php else: ?>
    <p class="alert alert-erro">Ainda não verificado — o cliente não consegue usar o carrinho até confirmar o e-mail (pelo link enviado ou por aqui).</p>
    <form method="post">
        <input type="hidden" name="acao" value="ativar_email_manual">
        <button type="submit">Ativar e-mail manualmente</button>
    </form>
    <p style="font-size:0.85rem; color:var(--cor-texto-suave); margin-top:12px;">Use isso quando o cliente não conseguir clicar no link do e-mail sozinho (ex: não sabe mexer no e-mail) — confirme a identidade dele por WhatsApp/telefone antes de ativar.</p>
    <?php endif; ?>
    </div>
    </div>

    <div class="card">
    <h2>Linha de Crédito</h2>
    <p>Limite: R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></p>
    <p>Saldo devedor: R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></p>
    <p>Crédito disponível: R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></p>

    <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
    <form method="post" class="form-linha-compacta">
        <input type="hidden" name="acao" value="atualizar_limite">
        <label>Novo limite de crédito
            <input type="text" name="limite_credito" value="<?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?>">
        </label>
        <button type="submit">Atualizar limite</button>
    </form>
    <?php endif; ?>

    <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
    <h3>Registrar pagamento da dívida</h3>
    <form method="post" class="form-linha">
        <label>Valor recebido
            <input type="text" name="valor" placeholder="0,00">
        </label>
        <label>Forma de pagamento
            <select name="forma_pagamento">
                <option value="Dinheiro">Dinheiro</option>
                <option value="Débito">Débito</option>
                <option value="Crédito">Crédito</option>
                <option value="Pix">Pix</option>
            </select>
        </label>
        <input type="hidden" name="acao" value="registrar_pagamento">
        <button type="submit" style="align-self:flex-end; margin-bottom:14px;">Registrar pagamento</button>
    </form>
    <?php endif; ?>
    </div>

    <h2>Extrato</h2>
    <?php if (empty($extrato)): ?>
    <p>Nenhum movimento de crédito ainda.</p>
    <?php else: ?>
    <ul class="lista-extrato">
        <?php foreach ($extrato as $mov): ?>
        <li>
            <?= htmlspecialchars($mov['data_movimento']) ?> —
            <?= $mov['tipo'] === 'compra' ? 'Compra a prazo' : 'Pagamento' ?>
            (<?= htmlspecialchars($mov['status']) ?>) —
            R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?>
            <?= $mov['forma_pagamento'] ? ' via ' . htmlspecialchars($mov['forma_pagamento']) : '' ?>
            <?= $mov['nome_funcionario'] ? ' (registrado por ' . htmlspecialchars($mov['nome_funcionario']) . ')' : ' (cliente, online)' ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <p><a href="/clientes/lista.php">Voltar</a></p>
</main>
</body>
</html>
