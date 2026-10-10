<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal.php';
exigirAdmin();

$erro = '';
$sucesso = '';
$abaConexao = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        if (!fiscalConfig($pdo)['tabela_ok']) {
            throw new FiscalErro('O banco ainda não tem as tabelas da nota fiscal. Avise o desenvolvedor para atualizar o banco.');
        }
        if ($acao === 'salvar') {
            fiscalSalvarConfig($pdo, $_POST, (int) $_SESSION['id_usuario']);
            $sucesso = !empty($_POST['ativo']) ? 'Configuração salva. A nota fiscal está ligada.' : 'Configuração salva. A nota fiscal está desligada.';
        } elseif ($acao === 'conectar') {
            $abaConexao = true;
            $r = fiscalConectar($pdo, $_POST['token'] ?? '', $_POST['ambiente'] ?? 'homologacao');
            $sucesso = $r['mensagem'];
        } elseif ($acao === 'desconectar') {
            fiscalDesconectar($pdo);
            $sucesso = 'Brasil NFe desconectada.';
        }
    } catch (FiscalErro $e) {
        $erro = $e->getMessage();
    }
}

// Relê depois de salvar: o cabeçalho (menu) e o resto da página precisam do estado novo.
$cfg = fiscalConfig($pdo, true);

$tabelaOk = (bool) $cfg['tabela_ok'];
$conectado = !empty($cfg['token']) && $cfg['conectado_em'] !== null;
$pendentes = ($tabelaOk && (int) $cfg['ativo'] === 1) ? fiscalProdutosSemDados($pdo) : [];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Nota fiscal</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <div>
            <h1>Nota fiscal</h1>
            <span class="subtitulo">NFC-e (cupom) nas vendas do PDV e NF-e (A4) nas vendas online, pela Brasil NFe</span>
        </div>
    </div>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>
    <?php if (!$tabelaOk): ?><p class="alert alert-erro">O banco ainda não tem as tabelas da nota fiscal. Avise o desenvolvedor para atualizar o banco.</p><?php endif; ?>

    <?php if ($tabelaOk): ?>
    <div class="grade-2col">
    <div class="card">
        <h3>1. Conexão com a Brasil NFe</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem;">Você precisa de uma conta na <a href="https://www.brasilnfe.com.br" target="_blank" rel="noopener">Brasil NFe</a> com a empresa cadastrada, o certificado digital A1 e o CSC. Copie o token da empresa e cole abaixo. Quem cuida da parte fiscal (contador) deve fazer esse cadastro.</p>
        <p>Situação: <span class="status-pill<?= $conectado ? ' sucesso' : ' erro' ?>"><?= $conectado ? 'Conectada (' . ($cfg['ambiente'] === 'producao' ? 'produção' : 'homologação') . ')' : 'Não conectada' ?></span></p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="acao" value="conectar">
            <label>Token da empresa<input type="password" name="token" autocomplete="off" required placeholder="<?= $conectado ? 'Já salvo — cole outro para trocar' : 'Cole o token da Brasil NFe' ?>"></label>
            <label>Ambiente
                <select name="ambiente">
                    <option value="homologacao" <?= $cfg['ambiente'] !== 'producao' ? 'selected' : '' ?>>Homologação (testes, sem validade fiscal)</option>
                    <option value="producao" <?= $cfg['ambiente'] === 'producao' ? 'selected' : '' ?>>Produção (notas valem de verdade)</option>
                </select>
            </label>
            <button type="submit" class="btn-bloco"><?= $conectado ? 'Reconectar' : 'Conectar' ?></button>
        </form>
        <?php if ($conectado): ?>
        <form method="post" data-confirm="Desconectar a Brasil NFe? Você não conseguirá emitir notas até conectar de novo.">
            <input type="hidden" name="acao" value="desconectar">
            <button type="submit" class="btn-outline btn-perigo btn-bloco" style="margin-top:10px;">Desconectar</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>2. Ligar e padrões</h3>
        <form method="post">
            <input type="hidden" name="acao" value="salvar">
            <label class="toggle-switch" style="margin-bottom:14px;">
                <input type="checkbox" name="ativo" value="1" <?= (int) $cfg['ativo'] === 1 ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
                <span class="toggle-texto">Nota fiscal ligada</span>
            </label>
            <label style="flex-direction:row; align-items:center; gap:8px;"><input type="checkbox" name="emite_nfce" value="1" style="width:auto;" <?= (int) $cfg['emite_nfce'] === 1 ? 'checked' : '' ?>> Emitir NFC-e (cupom) nas vendas do PDV</label>
            <label style="flex-direction:row; align-items:center; gap:8px;"><input type="checkbox" name="emite_nfe" value="1" style="width:auto;" <?= (int) $cfg['emite_nfe'] === 1 ? 'checked' : '' ?>> Emitir NF-e (A4) nas vendas online</label>
            <label style="margin-top:12px;">Estado (UF) da loja
                <select name="uf_loja">
                    <option value="">Selecione</option>
                    <?php foreach (FISCAL_UFS as $uf): ?><option value="<?= $uf ?>" <?= $cfg['uf_loja'] === $uf ? 'selected' : '' ?>><?= $uf ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>CFOP padrão (venda dentro do estado)<input type="text" name="cfop_padrao" inputmode="numeric" maxlength="4" value="<?= htmlspecialchars((string) $cfg['cfop_padrao']) ?>"></label>
            <label>Grupo tributário padrão<input type="text" name="cod_tributacao_padrao" maxlength="40" value="<?= htmlspecialchars((string) ($cfg['cod_tributacao_padrao'] ?? '')) ?>" placeholder="Usado nos produtos que não têm o próprio"></label>
            <label>Origem padrão
                <select name="origem_padrao">
                    <?php foreach (FISCAL_ORIGENS as $k => $rot): ?><option value="<?= $k ?>" <?= (int) $cfg['origem_padrao'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($rot) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Unidade padrão<input type="text" name="unidade_padrao" maxlength="6" value="<?= htmlspecialchars((string) $cfg['unidade_padrao']) ?>"></label>

            <?php if ($cfg['aceite_em'] === null): ?>
            <p class="alert alert-info" style="font-size:0.85rem;"><?= htmlspecialchars(FISCAL_TEXTO_RESPONSABILIDADE) ?></p>
            <label style="flex-direction:row; align-items:center; gap:8px;"><input type="checkbox" name="aceite" value="1" style="width:auto;"> Li e concordo</label>
            <?php else: ?>
            <p style="color:var(--cor-texto-suave); font-size:0.85rem;">Declaração de responsabilidade aceita em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($cfg['aceite_em']))) ?>.</p>
            <?php endif; ?>
            <button type="submit" class="btn-bloco" style="margin-top:12px;">Salvar</button>
        </form>
    </div>
    </div>

    <?php if ((int) $cfg['ativo'] === 1): ?>
    <div class="card" style="margin-top:20px;">
        <h3>Produtos sem dados fiscais</h3>
        <?php if (empty($pendentes)): ?>
        <p class="alert alert-sucesso">Todos os produtos ativos têm NCM e grupo tributário.</p>
        <?php else: ?>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem;">Estes produtos ainda não saem em nota — complete o cadastro de cada um (seção "Dados fiscais"):</p>
        <div class="tabela-wrap">
        <table>
            <tr><th>Produto</th><th>Falta</th><th></th></tr>
            <?php foreach ($pendentes as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['nome']) ?></td>
                <td><?= htmlspecialchars(implode(', ', $p['faltando'])) ?></td>
                <td><a href="/produtos/editar.php?id=<?= $p['id'] ?>" class="btn-sm btn-outline">Completar</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
