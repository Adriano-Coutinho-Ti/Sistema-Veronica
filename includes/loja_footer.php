<?php
/**
 * Rodapé compartilhado de toda página da Loja Online — faixa de contato
 * (só mostra o que estiver preenchido em Aparência) + barra final com
 * copyright, e o botão flutuante do WhatsApp (fixo, sempre verde, não muda
 * com o tema da loja — é a cor universal do WhatsApp). Espera $pdo já
 * definido pelo require de conecta_bd.php no arquivo que inclui este.
 */
require_once __DIR__ . '/loja.php';
require_once __DIR__ . '/config_dev.php';

$configContato = $pdo->query('SELECT nome_loja, whatsapp_loja, endereco_loja, horario_atendimento, email_loja FROM config_loja WHERE id_config = 1')->fetch();
$temContato = $configContato['whatsapp_loja'] || $configContato['endereco_loja'] || $configContato['horario_atendimento'] || $configContato['email_loja'];
$creditoRodape = creditoRodape($pdo);
?>
<footer class="site-footer">
    <?php if ($temContato): ?>
    <div class="footer-contato">
        <div class="container footer-contato-grid">
            <?php if ($configContato['endereco_loja']): ?>
            <div><span class="rotulo">Endereço</span><span><?= htmlspecialchars($configContato['endereco_loja']) ?></span></div>
            <?php endif; ?>
            <?php if ($configContato['whatsapp_loja']): ?>
            <div><span class="rotulo">WhatsApp</span><span><?= htmlspecialchars(formatarWhatsappExibicao($configContato['whatsapp_loja'])) ?></span></div>
            <?php endif; ?>
            <?php if ($configContato['email_loja']): ?>
            <div><span class="rotulo">E-mail</span><span><?= htmlspecialchars($configContato['email_loja']) ?></span></div>
            <?php endif; ?>
            <?php if ($configContato['horario_atendimento']): ?>
            <div><span class="rotulo">Horário</span><span><?= htmlspecialchars($configContato['horario_atendimento']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="footer-final">
        <div class="container footer-final-inner">
            <span class="footer-logo"><?= htmlspecialchars($configContato['nome_loja']) ?></span>
            <span>© <?= date('Y') ?> <?= htmlspecialchars($configContato['nome_loja']) ?>. Todos os direitos reservados.</span>
            <span>Desenvolvido por <a href="<?= htmlspecialchars($creditoRodape['link']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($creditoRodape['nome']) ?></a></span>
        </div>
    </div>
</footer>

<?php if ($configContato['whatsapp_loja']): ?>
<a class="whatsapp-flutuante" href="https://wa.me/<?= htmlspecialchars($configContato['whatsapp_loja']) ?>" target="_blank" rel="noopener" aria-label="Falar no WhatsApp com a loja">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
</a>
<?php endif; ?>
