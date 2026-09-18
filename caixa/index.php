<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
exigirLogin();

$caixa = exigirCaixaAberto($pdo);

$id_venda = buscarVendaReservadaDoOperador($pdo, (int) $caixa['id_caixa'], (int) $_SESSION['id_usuario']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PDV</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>PDV<?= quantidadeCaixas($pdo) > 1 ? ' — Caixa ' . (int) $caixa['numero_caixa'] : '' ?></h1>
            <span class="subtitulo">Caixa aberto desde <?= htmlspecialchars(date('d/m/Y H:i', strtotime($caixa['data_abertura']))) ?></span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="/caixa/fechamento.php" class="btn-outline btn-sm">Fechar caixa</a>
        <a href="/caixa/vendas.php" class="btn-outline btn-sm">Vendas do caixa</a>
        <?php if (quantidadeCaixas($pdo) > 1): ?><a href="/caixa/selecionar.php" class="btn-outline btn-sm">Trocar de caixa</a><?php endif; ?>
        <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?><a href="/caixa/historico.php" class="btn-outline btn-sm">Histórico de caixas</a><?php endif; ?>
        <?php if ($id_venda): ?><span class="status-pill">Venda #<?= $id_venda ?></span><?php endif; ?>
    </p>

    <?php if (!$id_venda): ?>
    <div class="card" style="text-align:center; padding:48px 24px;">
        <span class="status-pill sucesso" style="font-size:0.85rem; padding:6px 16px; margin-bottom:10px;">Caixa livre</span>
        <p style="color:var(--cor-texto-suave); margin-bottom:20px;">Pronto pra começar uma nova venda.</p>
        <button id="btn-iniciar" class="btn-lg">+ Nova venda</button>
        <p id="msg-iniciar" class="alert alert-erro" style="display:none; margin-top:16px; text-align:left;"></p>
    </div>
<script>
document.getElementById('btn-iniciar').addEventListener('click', function () {
    const msg = document.getElementById('msg-iniciar');
    msg.style.display = 'none';
    fetch('/caixa/ajax/iniciar_venda.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                msg.textContent = data.message;
                msg.style.display = '';
            }
        });
});
</script>
    <?php else: ?>
    <p id="msg-pdv" class="alert alert-erro" style="display:none;"></p>

    <div class="grade-2col">
        <div class="card">
            <h2>Buscar produto</h2>
            <div style="display:flex; gap:8px; align-items:flex-start;">
                <input type="text" id="termo-busca" aria-label="Buscar produto" placeholder="Nome do produto ou código da etiqueta..." style="flex:1;">
                <button type="button" class="btn-outline btn-icone" id="btn-abrir-scanner-pdv" title="Ler código de barras com a câmera" aria-label="Ler código de barras com a câmera">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="13" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
            </div>
            <div id="resultados-busca" class="lista-resultados"></div>
            <button type="button" class="btn-outline btn-bloco" id="btn-venda-avulsa" style="margin-top:14px;">Venda avulsa</button>
        </div>

        <div>
            <div class="card">
                <h2>Carrinho</h2>
                <div id="carrinho" class="lista-carrinho"></div>
                <div class="carrinho-rodape">
                    <span>Total</span>
                    <strong>R$ <span id="total-venda">0,00</span></strong>
                </div>
                <a href="/caixa/pagamento.php?id_venda=<?= $id_venda ?>" class="btn btn-bloco btn-lg" style="margin-top:16px;">Ir para pagamento →</a>
            </div>

            <div class="card" style="margin-top:20px;">
                <h2>Cliente</h2>
                <div id="cliente-vinculado"><div class="cliente-vinculado-pill cliente-vinculado-pill-neutro">Consumidor</div></div>
                <input type="text" id="termo-cliente" aria-label="Buscar cliente" placeholder="Buscar cliente (opcional)...">
                <div id="resultados-cliente" class="lista-resultados"></div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-scanner" hidden>
        <div class="modal-card">
            <h3>Ler código de barras</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Aponte a câmera pro código de barras do produto.</p>
            <div id="scanner-viewport" style="position:relative; width:100%; aspect-ratio:4/3; background:#000; border-radius:var(--raio-sm); overflow:hidden;"></div>
            <p id="scanner-erro" class="alert alert-erro" hidden style="margin-top:12px;"></p>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-cancelar-scanner">Cancelar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-avulsa" hidden>
        <div class="modal-card">
            <h3>Venda avulsa</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Pra vender algo que ainda não está cadastrado no sistema. Informe só o valor.</p>
            <input type="text" id="valor-avulsa" class="js-mascara-moeda" placeholder="0,00">
            <p id="erro-avulsa" class="alert alert-erro" style="display:none; margin-top:10px;"></p>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-cancelar-avulsa">Cancelar</button>
                <button type="button" class="btn" id="btn-confirmar-avulsa">Adicionar ao carrinho</button>
            </div>
        </div>
    </div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>
<script>
const idVenda = <?= $id_venda ?>;

function mostrarErroPdv(mensagem) {
    const msg = document.getElementById('msg-pdv');
    msg.textContent = mensagem;
    msg.style.display = '';
}

(function () {
    const modalAvulsa = document.getElementById('modal-avulsa');
    const campoValorAvulsa = document.getElementById('valor-avulsa');
    const erroAvulsa = document.getElementById('erro-avulsa');

    document.getElementById('btn-venda-avulsa').addEventListener('click', function () {
        campoValorAvulsa.value = '';
        erroAvulsa.style.display = 'none';
        modalAvulsa.hidden = false;
        campoValorAvulsa.focus();
    });
    document.getElementById('btn-cancelar-avulsa').addEventListener('click', function () { modalAvulsa.hidden = true; });
    modalAvulsa.addEventListener('click', function (e) { if (e.target === modalAvulsa) { modalAvulsa.hidden = true; } });

    document.getElementById('btn-confirmar-avulsa').addEventListener('click', function () {
        fetch('/caixa/ajax/adicionar_item_avulso.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_venda=' + idVenda + '&valor=' + encodeURIComponent(campoValorAvulsa.value)
        }).then(r => r.json()).then(data => {
            if (data.success) {
                modalAvulsa.hidden = true;
                carregarCarrinho();
            } else {
                erroAvulsa.textContent = data.message;
                erroAvulsa.style.display = '';
            }
        });
    });
})();

document.getElementById('termo-busca').addEventListener('input', function () {
    const termo = this.value;
    const container = document.getElementById('resultados-busca');
    if (termo.length < 2) { container.innerHTML = ''; return; }
    fetch('/caixa/ajax/buscar_produtos.php?termo=' + encodeURIComponent(termo))
        .then(r => r.json())
        .then(data => {
            container.innerHTML = '';
            if (data.produtos.length === 0) {
                container.innerHTML = '<p class="lista-vazia">Nenhum produto encontrado.</p>';
                return;
            }
            data.produtos.forEach(p => {
                const linha = document.createElement('div');
                linha.className = 'linha-resultado';

                const info = document.createElement('div');
                info.className = 'linha-resultado-info';
                const nome = document.createElement('strong');
                nome.textContent = p.nome_completo;
                const meta = document.createElement('span');
                meta.className = 'linha-resultado-meta';
                meta.textContent = 'R$ ' + p.preco.toFixed(2).replace('.', ',') + ' · ' + (p.estoque_gerenciado ? ('Estoque: ' + p.estoque) : 'Estoque livre');
                info.appendChild(nome);
                info.appendChild(meta);

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn-sm btn-outline';
                btn.textContent = 'Adicionar';
                btn.addEventListener('click', function () { adicionarItem(p.id_produto_variacao); });

                linha.appendChild(info);
                linha.appendChild(btn);
                container.appendChild(linha);
            });
        });
});

function adicionarItem(idProdutoVariacao) {
    fetch('/caixa/ajax/adicionar_item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_venda=' + idVenda + '&id_produto_variacao=' + idProdutoVariacao + '&quantidade=1'
    }).then(r => r.json()).then(data => {
        if (data.success) { carregarCarrinho(); } else { mostrarErroPdv(data.message); }
    });
}

function removerItem(idItem) {
    fetch('/caixa/ajax/remover_item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_item=' + idItem + '&id_venda=' + idVenda
    }).then(r => r.json()).then(data => {
        if (data.success) { carregarCarrinho(); } else { mostrarErroPdv(data.message); }
    });
}

function carregarCarrinho() {
    fetch('/caixa/ajax/ver_carrinho.php?id_venda=' + idVenda)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('carrinho');
            container.innerHTML = '';

            if (data.itens.length === 0) {
                container.innerHTML = '<p class="lista-vazia">Carrinho vazio — busque um produto ao lado.</p>';
            }

            data.itens.forEach(item => {
                const linha = document.createElement('div');
                linha.className = 'linha-carrinho';

                const info = document.createElement('div');
                info.className = 'linha-carrinho-info';
                const nome = document.createElement('strong');
                nome.textContent = item.quantidade + 'x ' + item.nome_produto;
                info.appendChild(nome);
                if (item.descricao_combinacao) {
                    const meta = document.createElement('span');
                    meta.className = 'linha-carrinho-meta';
                    meta.textContent = item.descricao_combinacao;
                    info.appendChild(meta);
                }

                const direita = document.createElement('div');
                direita.className = 'linha-carrinho-direita';
                const subtotal = document.createElement('span');
                subtotal.className = 'linha-carrinho-subtotal';
                subtotal.textContent = 'R$ ' + item.subtotal.toFixed(2).replace('.', ',');
                const btnRemover = document.createElement('button');
                btnRemover.type = 'button';
                btnRemover.className = 'btn-sm btn-perigo btn-icone';
                btnRemover.title = 'Remover item';
                btnRemover.setAttribute('aria-label', 'Remover item');
                btnRemover.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3m-8 0 1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M10 11v6M14 11v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                btnRemover.addEventListener('click', function () { removerItem(item.id_item); });
                direita.appendChild(subtotal);
                direita.appendChild(btnRemover);

                linha.appendChild(info);
                linha.appendChild(direita);
                container.appendChild(linha);
            });
            document.getElementById('total-venda').textContent = data.total.toFixed(2).replace('.', ',');
        });
}

// iniciarLeitorCodigoBarras() vive em admin.js, carregado com "defer" — ele
// só existe depois que o HTML inteiro terminar de ser interpretado. Chamar
// direto aqui (fora de DOMContentLoaded) lançava ReferenceError e travava
// TODO o restante deste script, inclusive a busca de cliente e o
// carregarCarrinho() final lá embaixo.
document.addEventListener('DOMContentLoaded', function () {
    iniciarLeitorCodigoBarras('btn-abrir-scanner-pdv', function (codigo) {
        const campoBusca = document.getElementById('termo-busca');
        const containerResultados = document.getElementById('resultados-busca');
        campoBusca.value = codigo;
        fetch('/caixa/ajax/buscar_produtos.php?termo=' + encodeURIComponent(codigo))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Código só é ambíguo se o mesmo produto tiver mais de uma
                // combinação (cor/tamanho) — nesse caso mostra a lista normal
                // pro operador escolher qual. Uma única combinação encontrada
                // já vai direto pro carrinho, sem precisar clicar "Adicionar".
                if (data.produtos.length === 1) {
                    adicionarItem(data.produtos[0].id_produto_variacao);
                    campoBusca.value = '';
                    containerResultados.innerHTML = '';
                } else {
                    campoBusca.dispatchEvent(new Event('input'));
                }
            });
    });
});

document.getElementById('termo-cliente').addEventListener('input', function () {
    const termo = this.value;
    const container = document.getElementById('resultados-cliente');
    if (termo.length < 2) { container.innerHTML = ''; return; }
    fetch('/caixa/ajax/buscar_clientes.php?termo=' + encodeURIComponent(termo))
        .then(r => r.json())
        .then(data => {
            container.innerHTML = '';
            if (data.clientes.length === 0) {
                container.innerHTML = '<p class="lista-vazia">Nenhum cliente encontrado.</p>';
                return;
            }
            data.clientes.forEach(c => {
                const linha = document.createElement('div');
                linha.className = 'linha-resultado';

                const info = document.createElement('div');
                info.className = 'linha-resultado-info';
                const nome = document.createElement('strong');
                nome.textContent = c.nome;
                const meta = document.createElement('span');
                meta.className = 'linha-resultado-meta';
                meta.textContent = c.whatsapp;
                info.appendChild(nome);
                info.appendChild(meta);

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn-sm btn-outline';
                btn.textContent = 'Vincular';
                btn.addEventListener('click', function () { vincularCliente(c.id_cliente, c.nome); });

                linha.appendChild(info);
                linha.appendChild(btn);
                container.appendChild(linha);
            });
        });
});

function vincularCliente(idCliente, nome) {
    fetch('/caixa/ajax/vincular_cliente.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_venda=' + idVenda + '&id_cliente=' + idCliente
    }).then(r => r.json()).then(data => {
        if (data.success) {
            const painel = document.getElementById('cliente-vinculado');
            painel.innerHTML = '';
            const pill = document.createElement('div');
            pill.className = 'cliente-vinculado-pill';
            const texto = document.createElement('span');
            texto.textContent = '✓ ' + nome;
            pill.appendChild(texto);
            painel.appendChild(pill);
            document.getElementById('resultados-cliente').innerHTML = '';
            document.getElementById('termo-cliente').value = '';
        } else {
            mostrarErroPdv(data.message);
        }
    });
}

carregarCarrinho();
</script>
    <?php endif; ?>
</main>
</body>
</html>
