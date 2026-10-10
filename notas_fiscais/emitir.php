<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal.php';
require_once __DIR__ . '/../includes/superfrete.php';
exigirLogin();

$id_venda = (int) ($_GET['id_venda'] ?? $_POST['id_venda'] ?? 0);
$ehAdmin = ($_SESSION['perfil'] ?? '') === 'Admin';

if (!fiscalAtivo($pdo)) {
    header('Location: /dashboard.php');
    exit;
}

$erro = '';
$pronto = fiscalPronto($pdo);

// Sem cron: confere aqui as notas que ficaram "processando" desta venda.
if ($pronto) {
    fiscalConferirPendentes($pdo, $id_venda);
}

$form = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pronto) {
    $form = $_POST;
    try {
        $nota = fiscalEmitir($pdo, $id_venda, $form, (string) ($_SESSION['nome'] ?? 'Usuário'));
        header('Location: /notas_fiscais/emitir.php?id_venda=' . $id_venda . '&emitida=' . (int) $nota['id_nota']);
        exit;
    } catch (FiscalErro $e) {
        $erro = $e->getMessage();
    }
}

$plano = null;
if ($pronto) {
    try {
        // Primeira abertura: sugere os dados do destinatário (envio SuperFrete ou cadastro do cliente).
        if ($form === []) {
            $f = fiscalFonte($pdo, $id_venda);
            $form = ['nome' => $f['venda']['cliente_nome'] ?? '', 'email' => $f['venda']['cliente_email'] ?? ''];
            $envio = $f['venda']['origem'] === 'loja' ? superfreteEnvioDaVenda($pdo, $id_venda) : null;
            if ($envio) {
                $form = [
                    'doc' => $envio['destino_documento'], 'nome' => $envio['destino_nome'], 'email' => $envio['destino_email'] ?? $form['email'],
                    'cep' => $envio['destino_cep'], 'logradouro' => $envio['destino_endereco'], 'numero' => $envio['destino_numero'],
                    'complemento' => $envio['destino_complemento'], 'bairro' => $envio['destino_bairro'], 'cidade' => $envio['destino_cidade'], 'uf' => $envio['destino_uf'],
                ];
            }
            $semValidar = true;
        }
        $plano = fiscalPlanejar($pdo, $id_venda, $semValidar ?? false ? [] : $form);
    } catch (FiscalErro $e) {
        $erro = $erro ?: $e->getMessage();
    }
}

$emitidas = fiscalNotasDaVenda($pdo, $id_venda);
$ehNfe = $plano && $plano['tipo'] === 'nfe';
$v = fn(string $k) => htmlspecialchars((string) ($form[$k] ?? ''));
$errosCampo = $plano['erros'] ?? [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errosCampo = []; // só mostra erro de campo depois de tentar emitir
}
$voltar = $plano && $plano['fonte']['venda']['origem'] === 'loja' ? '/pedidos/detalhe.php?id_venda=' . $id_venda : '/caixa/comprovante.php?id_venda=' . $id_venda;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Nota fiscal — venda #<?= $id_venda ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <div>
            <h1>Nota fiscal — venda #<?= $id_venda ?></h1>
            <span class="subtitulo"><?= $ehNfe ? 'Venda online → NF-e (nota em A4)' : 'Venda do PDV → NFC-e (cupom)' ?></span>
        </div>
    </div>
    <p class="acoes-topo"><a href="<?= htmlspecialchars($voltar) ?>" class="btn-outline btn-sm">← Voltar à venda</a></p>

    <?php if (fiscalConfig($pdo)['ambiente'] !== 'producao'): ?>
    <p class="alert alert-info">Ambiente de <strong>homologação</strong> (testes): as notas emitidas aqui não têm validade fiscal.</p>
    <?php endif; ?>
    <?php if (isset($_GET['emitida'])): ?><p class="alert alert-sucesso">Nota enviada para emissão. Veja a situação abaixo.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <?php if (!$pronto): ?>
    <div class="card"><p>A nota fiscal ainda não está pronta para emitir.<?= $ehAdmin ? ' <a href="/config_sistema/nota_fiscal.php">Abrir a configuração</a>.' : ' Peça ao administrador para concluir a configuração.' ?></p></div>
    <?php elseif ($plano): ?>
    <div class="grade-2col">
    <div class="card">
        <h3>Itens da nota</h3>
        <?php foreach ($plano['fonte']['linhas'] as $l): ?>
        <p style="margin:4px 0;"><?= (int) $l['quantidade'] ?> × <?= htmlspecialchars($l['nome_produto']) ?><?= $l['descricao_combinacao'] ? ' (' . htmlspecialchars($l['descricao_combinacao']) . ')' : '' ?> — R$ <?= number_format((float) $l['subtotal'], 2, ',', '.') ?></p>
        <?php endforeach; ?>
        <?php if ($ehNfe && $plano['fonte']['frete'] > 0): ?><p style="margin:4px 0;">Frete — R$ <?= number_format($plano['fonte']['frete'], 2, ',', '.') ?></p><?php endif; ?>
        <p style="font-weight:700; margin-top:10px;">Total da nota: R$ <?= number_format((float) ($plano['nota']['valor'] ?? $plano['fonte']['venda']['valor_total']), 2, ',', '.') ?></p>

        <?php if ($plano['pendencias']): ?>
        <div class="alert alert-erro"><strong>Faltam dados fiscais nestes itens:</strong>
            <?php foreach ($plano['pendencias'] as $p): ?>
            <div>• <?= htmlspecialchars($p['nome']) ?> — <?= htmlspecialchars(implode(', ', $p['faltando'])) ?>
                <?php if ($p['id']): ?> <a href="/produtos/editar.php?id=<?= (int) $p['id'] ?>">completar</a><?php endif; ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php foreach ($plano['avisos'] as $a): ?><p style="color:var(--cor-texto-suave); font-size:0.9rem;"><?= htmlspecialchars($a) ?></p><?php endforeach; ?>
    </div>

    <div class="card">
        <h3><?= $ehNfe ? 'Destinatário da NF-e' : 'Cliente no cupom (opcional)' ?></h3>
        <form method="post" id="form-nota">
            <input type="hidden" name="id_venda" value="<?= $id_venda ?>">
            <label>CPF ou CNPJ<?= $ehNfe ? '' : ' (opcional)' ?><input type="text" name="doc" inputmode="numeric" maxlength="18" value="<?= $v('doc') ?>" <?= $ehNfe ? 'required' : '' ?>></label>
            <?php if (isset($errosCampo['doc'])): ?><p class="alert alert-erro"><?= htmlspecialchars($errosCampo['doc']) ?></p><?php endif; ?>
            <label>Nome<?= $ehNfe ? '' : ' (opcional)' ?><input type="text" name="nome" maxlength="140" value="<?= $v('nome') ?>" <?= $ehNfe ? 'required' : '' ?>></label>
            <?php if (isset($errosCampo['nome'])): ?><p class="alert alert-erro"><?= htmlspecialchars($errosCampo['nome']) ?></p><?php endif; ?>

            <?php if ($ehNfe): ?>
            <label>E-mail (opcional)<input type="email" name="email" value="<?= $v('email') ?>"></label>
            <label>Situação perante o ICMS
                <select name="ie_indicador" id="ie_indicador">
                    <option value="9" <?= ($form['ie_indicador'] ?? '9') == 9 ? 'selected' : '' ?>>Não contribuinte (pessoa física / consumidor)</option>
                    <option value="1" <?= ($form['ie_indicador'] ?? '') == 1 ? 'selected' : '' ?>>Contribuinte de ICMS</option>
                    <option value="2" <?= ($form['ie_indicador'] ?? '') == 2 ? 'selected' : '' ?>>Contribuinte isento</option>
                </select>
            </label>
            <label id="campo-ie" style="display:none;">Inscrição estadual<input type="text" name="ie" value="<?= $v('ie') ?>"></label>
            <?php if (isset($errosCampo['ie'])): ?><p class="alert alert-erro"><?= htmlspecialchars($errosCampo['ie']) ?></p><?php endif; ?>
            <label>CEP<input type="text" name="cep" id="nf_cep" inputmode="numeric" maxlength="9" value="<?= $v('cep') ?>" placeholder="00000-000"></label>
            <?php if (isset($errosCampo['cep'])): ?><p class="alert alert-erro"><?= htmlspecialchars($errosCampo['cep']) ?></p><?php endif; ?>
            <label>Rua<input type="text" name="logradouro" id="nf_logradouro" value="<?= $v('logradouro') ?>"></label>
            <label>Número<input type="text" name="numero" id="nf_numero" value="<?= $v('numero') ?>"></label>
            <label>Complemento (opcional)<input type="text" name="complemento" value="<?= $v('complemento') ?>"></label>
            <label>Bairro<input type="text" name="bairro" id="nf_bairro" value="<?= $v('bairro') ?>"></label>
            <label>Cidade<input type="text" name="cidade" id="nf_cidade" value="<?= $v('cidade') ?>"></label>
            <label>Estado (UF)<input type="text" name="uf" id="nf_uf" maxlength="2" style="text-transform:uppercase;" value="<?= $v('uf') ?>"></label>
            <label>Código do município (IBGE)<input type="text" name="ibge" id="nf_ibge" maxlength="7" value="<?= $v('ibge') ?>" readonly placeholder="Preenchido pelo CEP"></label>
            <?php foreach (['endereco', 'uf', 'ibge', 'email', 'uf_loja'] as $k): if (isset($errosCampo[$k])): ?><p class="alert alert-erro"><?= htmlspecialchars($errosCampo[$k]) ?></p><?php endif; endforeach; ?>
            <?php endif; ?>

            <p style="color:var(--cor-texto-suave); font-size:0.8rem;">Os dados fiscais são de responsabilidade da loja e do seu contador.</p>
            <button type="submit" class="btn-bloco" id="btn-emitir" <?= ($plano['pendencias'] || $plano['ja_tem'] || $plano['nota'] === null) ? 'disabled' : '' ?>>Emitir <?= $ehNfe ? 'NF-e' : 'NFC-e' ?></button>
        </form>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($emitidas): ?>
    <div class="card" style="margin-top:20px;">
        <h3>Notas desta venda</h3>
        <div class="tabela-wrap">
        <table>
            <tr><th>Nota</th><th>Valor</th><th>Situação</th><th></th></tr>
            <?php foreach ($emitidas as $n): [$rotulo, $classe] = fiscalSelo($n); ?>
            <tr>
                <td><strong><?= $n['tipo'] === 'nfe' ? 'NF-e' : 'NFC-e' ?></strong><?= $n['numero'] ? ' nº ' . htmlspecialchars((string) $n['numero']) : '' ?></td>
                <td>R$ <?= number_format((float) $n['valor'], 2, ',', '.') ?></td>
                <td><span class="status-pill<?= $classe ? ' ' . $classe : '' ?>"><?= htmlspecialchars($rotulo) ?></span><?php if ($n['erro']): ?><div style="font-size:0.85rem; color:var(--cor-texto-suave);"><?= htmlspecialchars((string) $n['erro']) ?></div><?php endif; ?></td>
                <td class="celula-acoes">
                    <?php if ($n['status'] === 'autorizada'): ?>
                    <a href="/notas_fiscais/arquivo.php?id=<?= (int) $n['id_nota'] ?>" target="_blank" rel="noopener" class="btn-sm btn-outline"><?= $n['tipo'] === 'nfe' ? 'Abrir nota (A4)' : 'Abrir / imprimir cupom' ?></a>
                    <a href="/notas_fiscais/arquivo.php?id=<?= (int) $n['id_nota'] ?>&tipo=xml" class="btn-sm btn-outline">XML</a>
                    <?php endif; ?>
                    <?php if ($n['status'] === 'autorizada' && $ehAdmin): ?>
                    <button type="button" class="btn-sm btn-perigo btn-cancelar-nota" data-id="<?= (int) $n['id_nota'] ?>">Cancelar</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal-overlay" id="modal-cancelar-nota" hidden>
        <div class="modal-card">
            <h3>Cancelar nota fiscal</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem;">Informe o motivo (15 a 255 caracteres, exigência da SEFAZ). A NFC-e só pode ser cancelada até <?= FISCAL_PRAZO_CANCELAR_NFCE_MIN ?> minutos depois de autorizada.</p>
            <textarea id="motivo-cancelar-nota" rows="3" maxlength="255"></textarea>
            <p id="erro-cancelar-nota" class="alert alert-erro" style="display:none;"></p>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-voltar-cancelar-nota">Voltar</button>
                <button type="button" class="btn-perigo" id="btn-confirmar-cancelar-nota">Cancelar a nota</button>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const soDigitos = v => v.replace(/\D/g, '');
    const cep = document.getElementById('nf_cep');
    if (cep) {
        const ie = document.getElementById('ie_indicador');
        const campoIe = document.getElementById('campo-ie');
        const atualizarIe = () => { campoIe.style.display = ie.value === '1' ? '' : 'none'; };
        ie.addEventListener('change', atualizarIe);
        atualizarIe();

        function buscarCep() {
            const d = soDigitos(cep.value).slice(0, 8);
            if (d.length !== 8) { return; }
            fetch('https://viacep.com.br/ws/' + d + '/json/').then(r => r.json()).then(function (e) {
                if (e.erro) { return; }
                const pre = (id, valor, forcar) => { const el = document.getElementById(id); if (el && (forcar || !el.value)) { el.value = valor || ''; } };
                pre('nf_logradouro', e.logradouro, false);
                pre('nf_bairro', e.bairro, false);
                pre('nf_cidade', e.localidade, true);
                pre('nf_uf', e.uf, true);
                pre('nf_ibge', e.ibge, true);
            }).catch(function () {});
        }
        cep.addEventListener('input', function () {
            const d = soDigitos(cep.value).slice(0, 8);
            cep.value = d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d;
            if (d.length === 8) { buscarCep(); }
        });
        if (!document.getElementById('nf_ibge').value) { buscarCep(); }
    }
    document.getElementById('form-nota') && document.getElementById('form-nota').addEventListener('submit', function () {
        const b = document.getElementById('btn-emitir');
        setTimeout(function () { b.disabled = true; b.textContent = 'Emitindo a nota…'; }, 0);
    });

    // Cancelamento
    const modal = document.getElementById('modal-cancelar-nota');
    let idCancelar = 0;
    document.querySelectorAll('.btn-cancelar-nota').forEach(function (b) {
        b.addEventListener('click', function () { idCancelar = b.dataset.id; modal.hidden = false; });
    });
    document.getElementById('btn-voltar-cancelar-nota').addEventListener('click', function () { modal.hidden = true; });
    modal.addEventListener('click', function (e) { if (e.target === modal) { modal.hidden = true; } });
    document.getElementById('btn-confirmar-cancelar-nota').addEventListener('click', function () {
        const erroEl = document.getElementById('erro-cancelar-nota');
        fetch('/notas_fiscais/ajax/cancelar.php', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_nota=' + idCancelar + '&motivo=' + encodeURIComponent(document.getElementById('motivo-cancelar-nota').value)
        }).then(r => r.json()).then(function (d) {
            if (d.success) { window.location.reload(); } else { erroEl.textContent = d.message; erroEl.style.display = ''; }
        }).catch(function () { erroEl.textContent = 'Erro de conexão. Tente novamente.'; erroEl.style.display = ''; });
    });
});
</script>
</main>
</body>
</html>
