<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
require_once __DIR__ . '/../includes/credito.php';
exigirLogin();

$busca = trim($_GET['busca'] ?? '');

$where = 'saldo_devedor > 0 AND excluido_em IS NULL';
$params = [];
if ($busca !== '') {
    $where .= ' AND (nome LIKE :busca OR whatsapp LIKE :busca)';
    $params[':busca'] = '%' . $busca . '%';
}

$stmt = $pdo->prepare("SELECT id_cliente, nome, whatsapp, saldo_devedor, prazo_dias_credito FROM clientes WHERE $where ORDER BY nome");
$stmt->execute($params);

// saldo_devedor > 0 já filtra no SQL quem tem pendência, mas o valor em
// aberto/vencido por compra (FIFO, comparando com a data de cada compra)
// só dá pra saber calculando — não tem como fazer isso num ORDER BY do
// SQL, então calcula e ordena aqui mesmo. Uma loja de bairro não deve ter
// centenas de devedores simultâneos, então paginar em PHP é suficiente.
$devedores = [];
foreach ($stmt->fetchAll() as $c) {
    $situacao = calcularSituacaoCreditoCliente($pdo, (int) $c['id_cliente'], (int) $c['prazo_dias_credito']);
    $devedores[] = [
        'id_cliente' => (int) $c['id_cliente'],
        'nome' => $c['nome'],
        'whatsapp' => $c['whatsapp'],
        'prazo_dias_credito' => (int) $c['prazo_dias_credito'],
        'total_aberto' => $situacao['total_aberto'],
        'total_vencido' => $situacao['total_vencido'],
    ];
}
usort($devedores, fn($a, $b) => $b['total_vencido'] <=> $a['total_vencido']);

$nomeLoja = $pdo->query('SELECT nome_loja FROM config_loja WHERE id_config = 1')->fetchColumn() ?: 'a loja';

const DEVEDORES_POR_PAGINA = 20;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$totalDevedores = count($devedores);
$totalPaginas = max(1, (int) ceil($totalDevedores / DEVEDORES_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$devedoresPagina = array_slice($devedores, ($pagina - 1) * DEVEDORES_POR_PAGINA, DEVEDORES_POR_PAGINA);

function montarLinkDevedores(int $pagina, string $busca): string
{
    $params = ['pagina' => $pagina];
    if ($busca !== '') {
        $params['busca'] = $busca;
    }
    return '/clientes/devedores.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Clientes devedores</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M2 10h20" stroke="currentColor" stroke-width="1.8"/></svg></span>
        <div>
            <h1>Clientes devedores</h1>
            <span class="subtitulo"><?= $totalDevedores ?> cliente<?= $totalDevedores === 1 ? '' : 's' ?> com valor em aberto na Linha de Crédito</span>
        </div>
    </div>

    <div class="card">
        <form method="get" class="busca-lista">
            <input type="text" name="busca" placeholder="Buscar por nome ou WhatsApp..." value="<?= htmlspecialchars($busca) ?>">
            <button type="submit">Buscar</button>
        </form>

        <?php if (empty($devedoresPagina)): ?>
        <p class="alert alert-info">Nenhum cliente com valor em aberto.</p>
        <?php else: ?>
        <div class="alternador-visualizacao" data-chave="devedores" data-alvo-lista="visualizacao-lista" data-alvo-cards="visualizacao-cards">
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
            <tr><th>Cliente</th><th>WhatsApp</th><th>Prazo</th><th>Em aberto</th><th>Vencido</th><th></th></tr>
            <?php foreach ($devedoresPagina as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['nome']) ?></td>
                <td><?= htmlspecialchars(formatarWhatsappExibicao($d['whatsapp'])) ?></td>
                <td><?= $d['prazo_dias_credito'] ?>d</td>
                <td><span class="status-pill">R$ <?= number_format($d['total_aberto'], 2, ',', '.') ?></span></td>
                <td><?php if ($d['total_vencido'] > 0): ?><span class="status-pill erro">R$ <?= number_format($d['total_vencido'], 2, ',', '.') ?></span><?php else: ?>—<?php endif; ?></td>
                <td class="celula-acoes">
                    <button type="button" class="btn-sm btn-outline btn-ver-extrato" data-id-cliente="<?= $d['id_cliente'] ?>" data-nome-cliente="<?= htmlspecialchars($d['nome']) ?>">Ver extrato</button>
                    <button type="button" class="btn-sm btn-outline btn-lembrete-whatsapp" data-nome-cliente="<?= htmlspecialchars($d['nome']) ?>" data-whatsapp="<?= htmlspecialchars($d['whatsapp']) ?>" data-aberto="<?= $d['total_aberto'] ?>" data-vencido="<?= $d['total_vencido'] ?>" title="Enviar lembrete pelo WhatsApp">
                        <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
                        Lembrete
                    </button>
                    <a href="/clientes/detalhe.php?id=<?= $d['id_cliente'] ?>" class="btn-sm btn-outline">Abrir cliente</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        </div>

        <div id="visualizacao-cards" hidden>
        <div class="grade-cards">
            <?php foreach ($devedoresPagina as $d): ?>
            <div class="item-card">
                <div class="item-card-topo">
                    <strong><?= htmlspecialchars($d['nome']) ?></strong>
                    <?php if ($d['total_vencido'] > 0): ?><span class="status-pill erro">Vencido</span><?php else: ?><span class="status-pill">Em dia</span><?php endif; ?>
                </div>
                <p><?= htmlspecialchars(formatarWhatsappExibicao($d['whatsapp'])) ?></p>
                <p>Prazo: <?= $d['prazo_dias_credito'] ?> dias</p>
                <p>Em aberto: <span class="status-pill">R$ <?= number_format($d['total_aberto'], 2, ',', '.') ?></span></p>
                <?php if ($d['total_vencido'] > 0): ?><p>Vencido: <span class="status-pill erro">R$ <?= number_format($d['total_vencido'], 2, ',', '.') ?></span></p><?php endif; ?>
                <div class="celula-acoes">
                    <button type="button" class="btn-sm btn-outline btn-ver-extrato" data-id-cliente="<?= $d['id_cliente'] ?>" data-nome-cliente="<?= htmlspecialchars($d['nome']) ?>">Ver extrato</button>
                    <button type="button" class="btn-sm btn-outline btn-lembrete-whatsapp" data-nome-cliente="<?= htmlspecialchars($d['nome']) ?>" data-whatsapp="<?= htmlspecialchars($d['whatsapp']) ?>" data-aberto="<?= $d['total_aberto'] ?>" data-vencido="<?= $d['total_vencido'] ?>" title="Enviar lembrete pelo WhatsApp">
                        <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
                        Lembrete
                    </button>
                    <a href="/clientes/detalhe.php?id=<?= $d['id_cliente'] ?>" class="btn-sm btn-outline">Abrir cliente</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        </div>

        <?php if ($totalPaginas > 1): ?>
        <nav class="paginacao">
            <?php if ($pagina > 1): ?><a href="<?= montarLinkDevedores($pagina - 1, $busca) ?>">‹ Anterior</a><?php endif; ?>
            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                <a href="<?= montarLinkDevedores($p, $busca) ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($pagina < $totalPaginas): ?><a href="<?= montarLinkDevedores($pagina + 1, $busca) ?>">Próxima ›</a><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="modal-overlay" id="modal-lembrete" hidden>
        <div class="modal-card">
            <h3>Enviar lembrete pelo WhatsApp</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Confira o número antes de enviar — o WhatsApp abre com a mensagem já pronta pra só conferir e mandar.</p>
            <label>Número<input type="text" id="numero-lembrete" placeholder="Número com DDD"></label>
            <label>Mensagem<textarea id="texto-lembrete" rows="9"></textarea></label>
            <p id="erro-lembrete" class="alert alert-erro" style="display:none; margin-top:10px;"></p>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-cancelar-lembrete">Cancelar</button>
                <button type="button" class="btn" id="btn-enviar-lembrete">
                    <svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
                    Abrir WhatsApp
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-extrato" hidden>
        <div class="modal-card modal-card-lg">
            <h3 id="extrato-modal-titulo">Extrato</h3>
            <div class="stats-credito" id="extrato-modal-stats" style="margin-top:10px;"></div>
            <div id="extrato-modal-conteudo" style="margin-top:14px; max-height:50vh; overflow-y:auto; padding-right:4px;"></div>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-fechar-extrato">Fechar</button>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modal-extrato');
    const titulo = document.getElementById('extrato-modal-titulo');
    const stats = document.getElementById('extrato-modal-stats');
    const conteudo = document.getElementById('extrato-modal-conteudo');

    function fecharModal() { modal.hidden = true; }

    function montarStatCredito(rotulo, valor, ehErro) {
        const div = document.createElement('div');
        div.className = 'stat-credito';
        const spanRotulo = document.createElement('span');
        spanRotulo.className = 'stat-label';
        spanRotulo.textContent = rotulo;
        const spanValor = document.createElement('span');
        spanValor.className = 'stat-valor' + (ehErro ? ' erro' : '');
        spanValor.textContent = 'R$ ' + valor.toFixed(2).replace('.', ',');
        div.appendChild(spanRotulo);
        div.appendChild(spanValor);
        return div;
    }

    function formatarValor(valor) {
        return 'R$ ' + valor.toFixed(2).replace('.', ',');
    }

    function montarPillSituacao(mov) {
        const pill = document.createElement('span');
        pill.className = 'status-pill';
        if (mov.vencido !== null && mov.vencido > 0) {
            pill.classList.add('erro');
            pill.textContent = 'Vencido há ' + mov.dias_atraso + 'd';
        } else if (mov.em_aberto !== null && mov.em_aberto > 0) {
            pill.textContent = 'Em aberto';
        } else if (mov.em_aberto !== null) {
            pill.classList.add('sucesso');
            pill.textContent = 'Quitado';
        } else {
            pill.textContent = '—';
        }
        return pill;
    }

    document.querySelectorAll('.btn-ver-extrato').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const idCliente = btn.dataset.idCliente;
            titulo.textContent = 'Extrato — ' + btn.dataset.nomeCliente;
            stats.innerHTML = '';
            conteudo.innerHTML = '<p class="alert alert-info">Carregando...</p>';
            modal.hidden = false;

            fetch('/clientes/ajax/ver_extrato.php?id_cliente=' + encodeURIComponent(idCliente))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        conteudo.innerHTML = '';
                        const erro = document.createElement('p');
                        erro.className = 'alert alert-erro';
                        erro.textContent = data.message;
                        conteudo.appendChild(erro);
                        return;
                    }

                    stats.appendChild(montarStatCredito('Em aberto', data.total_aberto, data.total_aberto > 0));
                    stats.appendChild(montarStatCredito('Vencido', data.total_vencido, data.total_vencido > 0));

                    conteudo.innerHTML = '';
                    if (data.movimentos.length === 0) {
                        const vazio = document.createElement('p');
                        vazio.className = 'alert alert-info';
                        vazio.textContent = 'Nenhum movimento de crédito ainda.';
                        conteudo.appendChild(vazio);
                        return;
                    }

                    const wrap = document.createElement('div');
                    wrap.className = 'tabela-wrap';
                    const tabela = document.createElement('table');
                    const cabecalho = document.createElement('tr');
                    ['Data', 'Tipo', 'Situação', 'Valor', 'Forma'].forEach(function (texto) {
                        const th = document.createElement('th');
                        th.textContent = texto;
                        cabecalho.appendChild(th);
                    });
                    tabela.appendChild(cabecalho);

                    data.movimentos.forEach(function (mov) {
                        const linha = document.createElement('tr');

                        const tdData = document.createElement('td');
                        tdData.textContent = mov.data_movimento;
                        linha.appendChild(tdData);

                        const tdTipo = document.createElement('td');
                        tdTipo.textContent = mov.tipo === 'compra' ? 'Compra a prazo' : 'Pagamento';
                        linha.appendChild(tdTipo);

                        const tdSituacao = document.createElement('td');
                        tdSituacao.appendChild(montarPillSituacao(mov));
                        linha.appendChild(tdSituacao);

                        const tdValor = document.createElement('td');
                        tdValor.textContent = formatarValor(mov.valor);
                        linha.appendChild(tdValor);

                        const tdForma = document.createElement('td');
                        tdForma.textContent = mov.forma_pagamento || '—';
                        linha.appendChild(tdForma);

                        tabela.appendChild(linha);
                    });

                    wrap.appendChild(tabela);
                    conteudo.appendChild(wrap);
                })
                .catch(function () {
                    conteudo.innerHTML = '';
                    const erro = document.createElement('p');
                    erro.className = 'alert alert-erro';
                    erro.textContent = 'Erro de conexão. Tente novamente.';
                    conteudo.appendChild(erro);
                });
        });
    });

    document.getElementById('btn-fechar-extrato').addEventListener('click', fecharModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) { fecharModal(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { fecharModal(); } });

    // Lembrete amigável por WhatsApp — nunca é cobrança, só um empurrãozinho
    // gentil lembrando o cliente da Linha de Crédito dele.
    const nomeLoja = <?= json_encode($nomeLoja) ?>;
    const modalLembrete = document.getElementById('modal-lembrete');
    const campoNumeroLembrete = document.getElementById('numero-lembrete');
    const campoTextoLembrete = document.getElementById('texto-lembrete');
    const erroLembrete = document.getElementById('erro-lembrete');

    function formatarReal(valor) {
        return 'R$ ' + valor.toFixed(2).replace('.', ',');
    }

    function montarMensagemLembrete(nome, totalAberto, totalVencido) {
        const primeiroNome = nome.split(' ')[0];
        let msg = 'Oi, ' + primeiroNome + '!\n\n';
        msg += 'Passando aqui com carinho pra lembrar da sua Linha de Crédito na ' + nomeLoja + '.\n\n';
        if (totalVencido > 0) {
            msg += 'Você está com ' + formatarReal(totalAberto) + ' em aberto, sendo ' + formatarReal(totalVencido) + ' já vencido.\n\n';
            msg += 'Quando puder, dá uma olhadinha pra gente organizar certinho, tá bem?';
        } else {
            msg += 'Você tem ' + formatarReal(totalAberto) + ' em aberto — nada vencido ainda, mas já deixamos esse lembrete com carinho pra você se organizar.';
        }
        msg += '\n\nQualquer dúvida ou pra combinar a forma de pagamento, é só chamar por aqui. Obrigada por fazer parte da nossa lojinha!';
        return msg;
    }

    function fecharModalLembrete() { modalLembrete.hidden = true; }

    document.querySelectorAll('.btn-lembrete-whatsapp').forEach(function (btn) {
        btn.addEventListener('click', function () {
            erroLembrete.style.display = 'none';
            campoNumeroLembrete.value = btn.dataset.whatsapp;
            campoTextoLembrete.value = montarMensagemLembrete(
                btn.dataset.nomeCliente,
                parseFloat(btn.dataset.aberto),
                parseFloat(btn.dataset.vencido)
            );
            modalLembrete.hidden = false;
        });
    });

    document.getElementById('btn-cancelar-lembrete').addEventListener('click', fecharModalLembrete);
    modalLembrete.addEventListener('click', function (e) { if (e.target === modalLembrete) { fecharModalLembrete(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modalLembrete.hidden) { fecharModalLembrete(); } });

    document.getElementById('btn-enviar-lembrete').addEventListener('click', function () {
        let numero = campoNumeroLembrete.value.replace(/\D/g, '');
        if (numero.length === 10 || numero.length === 11) {
            numero = '55' + numero;
        }
        if (numero.length !== 12 && numero.length !== 13) {
            erroLembrete.textContent = 'Número inválido — confira o DDD.';
            erroLembrete.style.display = '';
            return;
        }
        window.open('https://wa.me/' + numero + '?text=' + encodeURIComponent(campoTextoLembrete.value), '_blank');
        fecharModalLembrete();
    });
});
</script>
</main>
</body>
</html>
