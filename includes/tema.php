<?php

/**
 * Lê config_loja e imprime as 4 variáveis CSS de cor do tema ativo, dentro
 * de um <style>. Os temas claro/escuro têm paleta fixa (ignoram as cores
 * salvas no banco) — só o personalizado usa as 4 cores escolhidas pelo
 * lojista. Cores derivadas (bordas, hover, etc) ficam em assets/css/loja.css,
 * calculadas via color-mix() a partir dessas 4 — nunca aqui.
 */
function imprimirVariaveisTema(PDO $pdo): void
{
    $config = $pdo->query('SELECT tema, cor_primaria, cor_secundaria, cor_fundo, cor_texto FROM config_loja WHERE id_config = 1')->fetch();
    $tema = $config['tema'] ?? 'claro';

    $paletasFixas = [
        'claro' => [
            'primaria' => '#8B5CF6',
            'secundaria' => '#F472B6',
            'fundo' => '#FFFFFF',
            'texto' => '#1F2937',
        ],
        'escuro' => [
            'primaria' => '#7C3AED',
            'secundaria' => '#DB2777',
            'fundo' => '#111827',
            'texto' => '#F3F4F6',
        ],
    ];

    if ($tema === 'personalizado') {
        $cores = [
            'primaria' => $config['cor_primaria'],
            'secundaria' => $config['cor_secundaria'],
            'fundo' => $config['cor_fundo'],
            'texto' => $config['cor_texto'],
        ];
    } else {
        $cores = $paletasFixas[$tema] ?? $paletasFixas['claro'];
    }
    ?>
    <style>
        :root {
            --cor-primaria: <?= htmlspecialchars($cores['primaria']) ?>;
            --cor-secundaria: <?= htmlspecialchars($cores['secundaria']) ?>;
            --cor-fundo: <?= htmlspecialchars($cores['fundo']) ?>;
            --cor-texto: <?= htmlspecialchars($cores['texto']) ?>;
        }
    </style>
    <?php
}
