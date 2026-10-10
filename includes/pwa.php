<?php

/**
 * PWA (aplicativo instalável) da loja online e do painel admin: manifesto,
 * ícone gerado a partir do logo e aviso "Instalar aplicativo". São dois apps
 * separados ('loja' e 'admin'), cada um com o seu manifesto.
 */

/** Cores do app: [tema (barra do celular), fundo (tela de abertura)]. */
function pwaCores(PDO $pdo, string $app): array
{
    if ($app === 'admin') {
        return ['#0F172A', '#F5F5FF'];
    }
    $c = $pdo->query('SELECT tema, cor_primaria, cor_fundo FROM config_loja WHERE id_config = 1')->fetch() ?: [];
    $tema = $c['tema'] ?? 'claro';
    if ($tema === 'personalizado' && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $c['cor_primaria']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $c['cor_fundo'])) {
        return [$c['cor_primaria'], $c['cor_fundo']];
    }
    return $tema === 'escuro' ? ['#7C3AED', '#111827'] : ['#8B5CF6', '#FFFFFF'];
}

/** Caminho absoluto + data de modificação do logo da loja, ou null se não houver. */
function pwaLogo(PDO $pdo): ?array
{
    $arq = (string) $pdo->query('SELECT logo_arquivo FROM config_loja WHERE id_config = 1')->fetchColumn();
    $caminho = __DIR__ . '/../' . ltrim($arq, '/');
    if ($arq === '' || !is_file($caminho)) {
        return null;
    }
    return ['caminho' => $caminho, 'mtime' => (int) filemtime($caminho)];
}

/** Imprime as tags do PWA. $app = 'loja' ou 'admin'. Pode ser chamada no <head> ou no <body>. */
function pwaTags(PDO $pdo, string $app): void
{
    $app = $app === 'admin' ? 'admin' : 'loja';
    $versao = (pwaLogo($pdo)['mtime'] ?? 0) . '-' . (int) @filemtime(__DIR__ . '/../manifest.php');
    $tema = pwaCores($pdo, $app)[0];
    $css = (int) @filemtime(__DIR__ . '/../assets/css/pwa.css');
    $js = (int) @filemtime(__DIR__ . '/../assets/js/pwa.js');
    ?>
<link rel="stylesheet" href="/assets/css/pwa.css?v=<?= $css ?>">
<link rel="apple-touch-icon" href="/icone_pwa.php?app=<?= $app ?>&amp;tam=180&amp;v=<?= htmlspecialchars($versao) ?>">
<script src="/assets/js/pwa.js?v=<?= $js ?>" data-sw="/sw.js" data-app="<?= $app ?>" data-manifest="/manifest.php?app=<?= $app ?>&amp;v=<?= htmlspecialchars($versao) ?>" data-tema="<?= htmlspecialchars($tema) ?>" defer></script>
<?php
}
