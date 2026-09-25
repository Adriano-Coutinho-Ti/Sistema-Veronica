<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
require_once __DIR__ . '/../includes/loja.php';
require_once __DIR__ . '/../includes/credito.php';
require_once __DIR__ . '/../includes/clientes_lixeira.php';
exigirLogin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id]);
$cliente = $stmt->fetch();

if (!$cliente || $cliente['excluido_em'] !== null) {
    http_response_code(404);
    echo 'Cliente não encontrado.';
    exit;
}

$ehAdmin = ($_SESSION['perfil'] ?? '') === 'Admin';
$whatsappAtivo = (bool) $pdo->query('SELECT whatsapp_verificacao_ativo FROM config_dev WHERE id_config = 1')->fetchColumn();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'lancar_divida_manual') {
    if (!$ehAdmin) {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }

    $valorDivida = converterMoedaBrParaFloat($_POST['valor_divida'] ?? '0');
    $dataDivida = trim($_POST['data_divida'] ?? '');
    $obsDivida = trim(mb_substr($_POST['observacao_divida'] ?? '', 0, 255)) ?: null;
    $dataObj = DateTime::createFromFormat('Y-m-d', $dataDivida);
    $dataValida = $dataObj && $dataObj->format('Y-m-d') === $dataDivida;

    if ($valorDivida <= 0) {
        $erro = 'Informe o valor da dívida.';
    } elseif (!$dataValida) {
        $erro = 'Informe a data em que a dívida foi feita.';
    } else {
        // "Data no futuro" é decidido pelo relógio do MySQL, nunca pelo do PHP.
        $stmtFuturo = $pdo->prepare('SELECT :d > CURDATE()');
        $stmtFuturo->execute([':d' => $dataDivida]);
        if ($stmtFuturo->fetchColumn()) {
            $erro = 'A data da dívida não pode ser no futuro.';
        } else {
            // Migração de dívida antiga: não passa pelo caixa (sem id_caixa/id_venda)
            // e ignora o limite de crédito de propósito -- a dívida já existe.
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE clientes SET saldo_devedor = saldo_devedor + :v WHERE id_cliente = :id')
                    ->execute([':v' => $valorDivida, ':id' => $id]);
                $pdo->prepare(
                    "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, observacao, criado_por, data_movimento)
                     VALUES (:ic, 'compra', 'Confirmado', :v, :obs, :cp, CONCAT(:d, ' 12:00:00'))"
                )->execute([':ic' => $id, ':v' => $valorDivida, ':obs' => $obsDivida, ':cp' => (int) $_SESSION['id_usuario'], ':d' => $dataDivida]);
                $pdo->commit();
                header('Location: /clientes/detalhe.php?id=' . $id . '&divida_lancada=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('detalhe.php: falha ao lançar dívida manual: ' . $e->getMessage());
                $erro = 'Não foi possível lançar a dívida. Nada foi gravado.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'mover_lixeira') {
    if (!$ehAdmin) {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }
    moverClienteParaLixeira($pdo, $id, (int) $_SESSION['id_usuario']);
    header('Location: /clientes/lista.php?lixeira=1');
    exit;
}

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

    $novoLimite = converterMoedaBrParaFloat($_POST['limite_credito'] ?? '0');
    if ($novoLimite < 0) {
        $erro = 'Limite inválido.';
    } else {
        $pdo->prepare('UPDATE clientes SET limite_credito = :limite WHERE id_cliente = :id')
            ->execute([':limite' => $novoLimite, ':id' => $id]);
        $sucesso = 'Limite atualizado.';
        $cliente['limite_credito'] = $novoLimite;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_prazo_credito') {
    if (($_SESSION['perfil'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }

    $novoPrazo = (int) ($_POST['prazo_dias_credito'] ?? 0);
    if ($novoPrazo < 1) {
        $erro = 'O prazo precisa ser de pelo menos 1 dia.';
    } else {
        $pdo->prepare('UPDATE clientes SET prazo_dias_credito = :prazo WHERE id_cliente = :id')
            ->execute([':prazo' => $novoPrazo, ':id' => $id]);
        $sucesso = 'Prazo de pagamento atualizado.';
        $cliente['prazo_dias_credito'] = $novoPrazo;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'registrar_pagamento') {
    $valor = converterMoedaBrParaFloat($_POST['valor'] ?? '0');
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

$situacaoCredito = calcularSituacaoCreditoCliente($pdo, $id, (int) $cliente['prazo_dias_credito']);

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($cliente['nome']) ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8c0-3.31 3.13-6 7-6s7 2.69 7 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1><?= htmlspecialchars($cliente['nome']) ?></h1>
            <span class="subtitulo"><?= htmlspecialchars(formatarWhatsappExibicao($cliente['whatsapp'])) ?></span>
        </div>
    </div>

    <?php
    $whatsappValidado = !empty($cliente['whatsapp_verificado_em']);
    $emailValidado = !empty($cliente['email']) && !empty($cliente['email_verificado_em']);
    ?>
    <div class="card" style="margin-bottom:20px;">
        <p style="margin:0 0 10px;">
            <strong>WhatsApp</strong> <?= seloContatoValidado(true, $cliente['whatsapp_verificado_em'], $whatsappAtivo) ?>
            &nbsp;&nbsp;
            <strong>E-mail</strong> <?= seloContatoValidado(!empty($cliente['email']), $cliente['email_verificado_em']) ?>
        </p>
        <p style="margin:0; color:var(--cor-texto-suave); font-size:0.9rem;"><?= htmlspecialchars(resumoCanalConfiavel($whatsappValidado, $emailValidado, $whatsappAtivo)) ?></p>
    </div>

    <p class="acoes-topo">
        <a href="/clientes/lista.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar
        </a>
        <?php if ($ehAdmin): ?>
        <form method="post" style="display:inline;" data-confirm="Mover <?= htmlspecialchars($cliente['nome'], ENT_QUOTES) ?> para a lixeira? Ele perde o acesso à loja na hora e some das listas. Dá pra restaurar depois, dentro da lixeira.">
            <input type="hidden" name="acao" value="mover_lixeira">
            <button type="submit" class="btn-perigo btn-sm">Mover para a lixeira</button>
        </form>
        <?php endif; ?>
    </p>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if (isset($_GET['divida_lancada'])): ?><p class="alert alert-sucesso">Dívida lançada no saldo do cliente.</p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <div class="grade-2col">
    <div class="card">
    <h2>Dados</h2>
    <form method="post">
        <input type="hidden" name="acao" value="atualizar_dados">
        <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($cliente['nome']) ?>" required></label>
        <label>WhatsApp (com DDD)<input type="text" name="whatsapp" id="campo-whatsapp" value="<?= htmlspecialchars(formatarWhatsappParaEdicao($cliente['whatsapp'])) ?>" required></label>
        <label>E-mail (obrigatório pro cliente conseguir entrar na loja online)<input type="email" name="email" value="<?= htmlspecialchars($cliente['email'] ?? '') ?>"></label>
        <label>Endereço<input type="text" name="endereco" value="<?= htmlspecialchars($cliente['endereco'] ?? '') ?>"></label>
        <button type="submit" class="btn-bloco">Salvar dados</button>
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
        <button type="submit" class="btn-bloco">Ativar e-mail manualmente</button>
    </form>
    <p style="font-size:0.85rem; color:var(--cor-texto-suave); margin-top:12px;">Use isso quando o cliente não conseguir clicar no link do e-mail sozinho (ex: não sabe mexer no e-mail) — confirme a identidade dele por WhatsApp/telefone antes de ativar.</p>
    <?php endif; ?>
    </div>
    </div>

    <div class="card" style="margin-top:32px;">
    <div class="card-cabecalho">
        <h2>Linha de Crédito</h2>
        <span class="status-pill<?= (float) $cliente['saldo_devedor'] > 0 ? ' erro' : ' sucesso' ?>"><?= (float) $cliente['saldo_devedor'] > 0 ? 'Com pendência' : 'Em dia' ?></span>
    </div>

    <div class="stats-credito">
        <div class="stat-credito">
            <span class="stat-label">Limite</span>
            <span class="stat-valor">R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></span>
        </div>
        <div class="stat-credito">
            <span class="stat-label">Saldo devedor</span>
            <span class="stat-valor<?= (float) $cliente['saldo_devedor'] > 0 ? ' erro' : '' ?>">R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></span>
        </div>
        <div class="stat-credito">
            <span class="stat-label">Crédito disponível</span>
            <span class="stat-valor<?= $creditoDisponivel > 0 ? ' sucesso' : '' ?>">R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></span>
        </div>
        <div class="stat-credito">
            <span class="stat-label">Vencido</span>
            <span class="stat-valor<?= $situacaoCredito['total_vencido'] > 0 ? ' erro' : '' ?>">R$ <?= number_format($situacaoCredito['total_vencido'], 2, ',', '.') ?></span>
        </div>
    </div>

    <?php if ($creditoDisponivel < 0): ?>
    <p class="alert alert-info">O saldo devedor está acima do limite de crédito — o cliente não consegue comprar a prazo até pagar ou o limite aumentar.</p>
    <?php endif; ?>

    <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
    <div style="display:flex; gap:24px; flex-wrap:wrap;">
    <form method="post" class="form-linha-compacta">
        <input type="hidden" name="acao" value="atualizar_limite">
        <label>Novo limite de crédito
            <input type="text" name="limite_credito" class="js-mascara-moeda" value="<?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?>">
        </label>
        <button type="submit" class="btn-outline">Atualizar limite</button>
    </form>
    <form method="post" class="form-linha-compacta">
        <input type="hidden" name="acao" value="atualizar_prazo_credito">
        <label>Prazo de pagamento (dias)
            <input type="number" min="1" name="prazo_dias_credito" value="<?= (int) $cliente['prazo_dias_credito'] ?>" class="campo-valor-curto">
        </label>
        <button type="submit" class="btn-outline">Atualizar prazo</button>
    </form>
    </div>
    <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:8px;">Quantos dias esse cliente tem pra pagar cada compra a partir da data dela — usado pra calcular se uma compra já venceu.</p>

    <h3 style="margin-top:28px;">Lançar dívida do caderno</h3>
    <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:12px;">Pra passar pro sistema uma dívida antiga anotada no caderno. Não passa pelo caixa, não mexe no estoque e não depende do limite de crédito. A data é a de quando o cliente ficou devendo — o vencimento é contado a partir dela (mais o prazo de pagamento deste cliente).</p>
    <form method="post" class="form-linha" data-confirm="Lançar essa dívida no saldo de <?= htmlspecialchars($cliente['nome'], ENT_QUOTES) ?>? O saldo devedor aumenta e ela aparece no extrato como dívida do caderno.">
        <input type="hidden" name="acao" value="lancar_divida_manual">
        <label>Valor
            <input type="text" name="valor_divida" class="js-mascara-moeda" placeholder="0,00" required>
        </label>
        <label>Data da dívida
            <input type="date" name="data_divida" required>
        </label>
        <label>Descrição (opcional)
            <input type="text" name="observacao_divida" maxlength="255" placeholder="Ex: blusa e calça — caderno de março">
        </label>
        <button type="submit" class="btn" style="align-self:flex-end; margin-bottom:14px;">Lançar dívida</button>
    </form>
    <?php endif; ?>

    <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
    <h3 style="margin-top:28px;">Registrar pagamento da dívida</h3>
    <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:12px;">Dinheiro/Débito/Crédito são lançados manualmente (o pagamento já foi recebido por fora). Pix gera um QR Code de verdade pelo Mercado Pago, confirmado sozinho quando o cliente pagar.</p>
    <form method="post" class="form-linha" id="form-pagamento-divida">
        <label>Valor recebido
            <input type="text" name="valor" id="valor-pagamento-divida" class="js-mascara-moeda" placeholder="0,00">
        </label>
        <label>Forma de pagamento
            <select name="forma_pagamento" id="forma-pagamento-divida">
                <option value="Dinheiro">Dinheiro</option>
                <option value="Débito">Débito</option>
                <option value="Crédito">Crédito</option>
                <option value="Pix">Pix</option>
            </select>
        </label>
        <input type="hidden" name="acao" value="registrar_pagamento">
        <button type="submit" class="btn" id="btn-registrar-pagamento-divida" style="align-self:flex-end; margin-bottom:14px;">Registrar pagamento</button>
        <button type="button" class="btn-outline" id="btn-gerar-pix-divida" style="display:none; align-self:flex-end; margin-bottom:14px;">Gerar QR Code Pix</button>
    </form>
    <p id="erro-pix-divida" class="alert alert-erro" style="display:none;"></p>

    <div class="modal-overlay" id="modal-pix-divida" hidden>
        <div class="modal-card" style="text-align:center;">
            <h3>Pix</h3>
            <div id="pix-divida-resultado" style="margin-top:14px;"></div>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-fechar-pix-divida">Fechar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <div class="card">
    <h2>Extrato</h2>
    <?php if (empty($extrato)): ?>
    <p class="alert alert-info">Nenhum movimento de crédito ainda.</p>
    <?php else: ?>
    <div class="tabela-wrap">
    <table>
        <tr><th>Data</th><th>Tipo</th><th>Situação</th><th>Status</th><th>Valor</th><th>Forma</th><th>Registrado por</th></tr>
        <?php foreach ($extrato as $mov): ?>
        <?php $sitCompra = $mov['tipo'] === 'compra' ? ($situacaoCredito['compras'][(int) $mov['id_movimento']] ?? null) : null; ?>
        <tr>
            <td><?= htmlspecialchars($mov['data_movimento']) ?></td>
            <td>
                <?php if ($mov['tipo'] === 'compra' && $mov['id_venda'] === null): ?>Dívida do caderno<?= !empty($mov['observacao']) ? '<br><small>' . htmlspecialchars($mov['observacao']) . '</small>' : '' ?>
                <?php else: ?><?= $mov['tipo'] === 'compra' ? 'Compra a prazo' : 'Pagamento' ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($sitCompra === null): ?>—
                <?php elseif ($sitCompra['vencido'] > 0): ?><span class="status-pill erro">Vencido há <?= $sitCompra['dias_atraso'] ?>d</span>
                <?php elseif ($sitCompra['em_aberto'] > 0): ?><span class="status-pill">Em aberto</span>
                <?php else: ?><span class="status-pill sucesso">Quitado</span>
                <?php endif; ?>
            </td>
            <td><span class="status-pill<?= $mov['status'] === 'Confirmado' ? ' sucesso' : '' ?>"><?= htmlspecialchars($mov['status']) ?></span></td>
            <td>R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?></td>
            <td><?= htmlspecialchars($mov['forma_pagamento'] ?? '—') ?></td>
            <td><?= $mov['nome_funcionario'] ? htmlspecialchars($mov['nome_funcionario']) : 'Cliente (online)' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
    </div>

<?php if ((float) $cliente['saldo_devedor'] > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    ativarMascaraTelefoneComNoveAutomatico(document.getElementById('campo-whatsapp'));

    const idCliente = <?= (int) $id ?>;
    const formPagamento = document.getElementById('form-pagamento-divida');
    const selectForma = document.getElementById('forma-pagamento-divida');
    const btnRegistrar = document.getElementById('btn-registrar-pagamento-divida');
    const btnGerarPix = document.getElementById('btn-gerar-pix-divida');
    const erroPix = document.getElementById('erro-pix-divida');

    function alternarBotoes() {
        const ehPix = selectForma.value === 'Pix';
        btnRegistrar.style.display = ehPix ? 'none' : '';
        btnGerarPix.style.display = ehPix ? '' : 'none';
        erroPix.style.display = 'none';
    }
    selectForma.addEventListener('change', alternarBotoes);
    alternarBotoes();

    const modalPixDivida = document.getElementById('modal-pix-divida');
    document.getElementById('btn-fechar-pix-divida').addEventListener('click', function () { modalPixDivida.hidden = true; });
    modalPixDivida.addEventListener('click', function (e) { if (e.target === modalPixDivida) { modalPixDivida.hidden = true; } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modalPixDivida.hidden) { modalPixDivida.hidden = true; } });

    let pollingPixDivida = null;

    function iniciarPollingPixDivida(idMovimento) {
        if (pollingPixDivida) { return; }
        pollingPixDivida = setInterval(function () {
            fetch('/clientes/ajax/verificar_pagamento_divida.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_movimento=' + idMovimento
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data.aprovado) {
                    clearInterval(pollingPixDivida);
                    window.location.reload();
                } else if (!data.success) {
                    clearInterval(pollingPixDivida);
                    pollingPixDivida = null;
                    erroPix.textContent = data.message;
                    erroPix.style.display = '';
                }
            });
        }, 4000);
    }

    btnGerarPix.addEventListener('click', function () {
        erroPix.style.display = 'none';
        const valor = document.getElementById('valor-pagamento-divida').value;
        btnGerarPix.disabled = true;
        fetch('/clientes/ajax/gerar_pix_divida.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_cliente=' + idCliente + '&valor=' + encodeURIComponent(valor)
        }).then(function (r) { return r.json(); }).then(function (data) {
            btnGerarPix.disabled = false;
            if (!data.success) {
                erroPix.textContent = data.message;
                erroPix.style.display = '';
                return;
            }
            const div = document.getElementById('pix-divida-resultado');
            div.innerHTML = '';
            const img = document.createElement('img');
            img.src = 'data:image/png;base64,' + data.qr_code_base64;
            img.width = 200;
            div.appendChild(img);
            const textarea = document.createElement('textarea');
            textarea.readOnly = true;
            textarea.style.width = '100%';
            textarea.style.marginTop = '10px';
            textarea.value = data.qr_code;
            div.appendChild(textarea);
            const p = document.createElement('p');
            p.className = 'lista-vazia';
            p.textContent = 'Aguardando pagamento...';
            div.appendChild(p);
            modalPixDivida.hidden = false;
            iniciarPollingPixDivida(data.id_movimento);
        });
    });
});
</script>
<?php endif; ?>
</main>
</body>
</html>
