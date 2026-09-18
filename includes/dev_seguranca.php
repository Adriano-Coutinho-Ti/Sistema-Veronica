<?php
/**
 * Segurança do login do painel_dev: trava progressiva contra tentativa de
 * força bruta + e-mails de alerta. Toda expiração/comparação de tempo é
 * calculada dentro do próprio SQL (NOW(), TIMESTAMPDIFF) -- nunca com
 * time()/date() do PHP, mesma regra do resto do projeto (ver notas em
 * includes/loja.php sobre o bug real de fuso que isso já causou aqui).
 */

const DEV_TENTATIVAS_POR_CICLO = 3;
const DEV_CICLOS_ANTES_DO_BLOQUEIO_SEGURANCA = 3;
const DEV_MINUTOS_BLOQUEIO_TEMPORARIO = 30;
const DEV_MINUTOS_VALIDADE_TOKEN_RESET = 60;

/**
 * Busca o dev pelo usuário já com o estado de bloqueio calculado no próprio
 * SQL (bloqueio_temporario_ativo / minutos_restantes) -- evita comparar
 * bloqueado_ate com o relógio do PHP.
 */
function buscarDevParaLogin(PDO $pdo, string $usuario): ?array
{
    $stmt = $pdo->prepare(
        "SELECT *,
                (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) AS bloqueio_temporario_ativo,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), bloqueado_ate)) AS segundos_bloqueio_restantes
         FROM dev_usuarios WHERE usuario = :usuario"
    );
    $stmt->execute([':usuario' => $usuario]);
    $dev = $stmt->fetch();
    return $dev ?: null;
}

/**
 * Mensagem de erro se o login deve ser recusado ANTES de checar qualquer
 * senha (conta em bloqueio de segurança ou dentro da janela de 30min) --
 * null quando pode seguir pra checagem de senha normalmente.
 */
function mensagemBloqueioLogin(array $dev): ?string
{
    if (!empty($dev['bloqueio_seguranca'])) {
        return 'Conta bloqueada por segurança após várias tentativas incorretas. Verifique seu e-mail — enviamos um link pra redefinir a senha.';
    }
    if (!empty($dev['bloqueio_temporario_ativo'])) {
        $minutos = (int) ceil(((int) $dev['segundos_bloqueio_restantes']) / 60);
        return "Conta temporariamente bloqueada por segurança. Tente novamente em {$minutos} minuto" . ($minutos === 1 ? '' : 's') . ".";
    }
    return null;
}

/**
 * Login (normal ou de recuperação) deu certo -- zera todo o estado de
 * tentativas/bloqueio. Um login válido é sempre o fim de qualquer trava em
 * andamento, não só o bloqueio temporário.
 */
function limparTentativasLogin(PDO $pdo, int $id_dev_usuario): void
{
    $pdo->prepare(
        'UPDATE dev_usuarios SET tentativas_falhas = 0, ciclos_bloqueio = 0, bloqueado_ate = NULL,
                bloqueio_seguranca = 0, token_redefinicao_senha = NULL, token_redefinicao_expira_em = NULL
         WHERE id_dev_usuario = :id'
    )->execute([':id' => $id_dev_usuario]);
}

/**
 * Login errado -- soma mais uma tentativa e, a cada 3 (DEV_TENTATIVAS_POR_CICLO),
 * dispara um ciclo de bloqueio: os 2 primeiros ciclos travam 30min e mandam
 * um alerta por e-mail; o 3º ciclo (9ª tentativa) é o bloqueio de segurança
 * de verdade -- só sai dali com o link de redefinição por e-mail.
 */
function registrarTentativaFalha(PDO $pdo, array $dev): void
{
    $id = (int) $dev['id_dev_usuario'];

    $stmt = $pdo->prepare('UPDATE dev_usuarios SET tentativas_falhas = tentativas_falhas + 1 WHERE id_dev_usuario = :id');
    $stmt->execute([':id' => $id]);

    $tentativas = (int) $dev['tentativas_falhas'] + 1;
    if ($tentativas % DEV_TENTATIVAS_POR_CICLO !== 0) {
        return;
    }

    $ciclo = (int) $dev['ciclos_bloqueio'] + 1;
    $pdo->prepare('UPDATE dev_usuarios SET ciclos_bloqueio = :c WHERE id_dev_usuario = :id')
        ->execute([':c' => $ciclo, ':id' => $id]);

    if ($ciclo >= DEV_CICLOS_ANTES_DO_BLOQUEIO_SEGURANCA) {
        $token = gerarTokenRedefinicaoSenha($pdo, $id);
        $pdo->prepare('UPDATE dev_usuarios SET bloqueio_seguranca = 1 WHERE id_dev_usuario = :id')->execute([':id' => $id]);
        if (!empty($dev['email'])) {
            enviarEmailBloqueioSeguranca($pdo, $dev, $token);
        }
        return;
    }

    $pdo->prepare('UPDATE dev_usuarios SET bloqueado_ate = DATE_ADD(NOW(), INTERVAL :m MINUTE) WHERE id_dev_usuario = :id')
        ->execute([':m' => DEV_MINUTOS_BLOQUEIO_TEMPORARIO, ':id' => $id]);
    if (!empty($dev['email'])) {
        enviarEmailAlertaTentativas($pdo, $dev);
    }
}

/**
 * Pra onde mandar o dev depois de um login válido -- perfil incompleto
 * (sem nome ou sem e-mail, caso do DevMaster seedado direto pelo SQL) vai
 * primeiro pra tela de completar cadastro, ela mesma redireciona pro
 * painel normal depois de preenchido.
 */
function destinoAposLoginDev(array $dev): string
{
    if (empty($dev['nome']) || empty($dev['email'])) {
        return '/painel_dev/completar_cadastro.php';
    }
    return '/painel_dev/index.php';
}

function gerarTokenRedefinicaoSenha(PDO $pdo, int $id_dev_usuario): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'UPDATE dev_usuarios SET token_redefinicao_senha = :t, token_redefinicao_expira_em = DATE_ADD(NOW(), INTERVAL :m MINUTE) WHERE id_dev_usuario = :id'
    )->execute([':t' => $token, ':m' => DEV_MINUTOS_VALIDADE_TOKEN_RESET, ':id' => $id_dev_usuario]);
    return $token;
}

/**
 * Token de redefinição ainda válido? Checado inteiramente no SQL (mesma
 * regra de nunca comparar timestamp do banco com o relógio do PHP).
 */
function buscarDevPorTokenValido(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM dev_usuarios
         WHERE token_redefinicao_senha = :token AND token_redefinicao_expira_em > NOW()"
    );
    $stmt->execute([':token' => $token]);
    return $stmt->fetch() ?: null;
}

function enviarEmailAlertaTentativas(PDO $pdo, array $dev): array
{
    $nomeSistema = nomeDoSistema($pdo);
    $primeiroNome = $dev['nome'] ? explode(' ', trim($dev['nome']))[0] : $dev['usuario'];

    $corpo = montarEmailHtmlDev(
        $pdo,
        'Tentativas de acesso à sua conta de desenvolvedor',
        [
            'Olá, ' . htmlspecialchars($primeiroNome) . '.',
            'Detectamos várias tentativas de login incorretas na sua conta de desenvolvedor no sistema <strong>' . htmlspecialchars($nomeSistema) . '</strong>.',
            'Por segurança, o acesso ficou bloqueado por <strong>' . DEV_MINUTOS_BLOQUEIO_TEMPORARIO . ' minutos</strong>.',
            'Se foi você mesmo errando a senha, pode tentar de novo depois desse tempo. Se não foi você, sua conta segue protegida — nenhuma tentativa teve sucesso.',
        ]
    );

    return enviarEmailSMTP($pdo, $dev['email'], 'Tentativas de acesso — ' . $nomeSistema, $corpo);
}

function enviarEmailBloqueioSeguranca(PDO $pdo, array $dev, string $token): array
{
    $nomeSistema = nomeDoSistema($pdo);
    $primeiroNome = $dev['nome'] ? explode(' ', trim($dev['nome']))[0] : $dev['usuario'];
    $link = urlBaseAtual() . '/painel_dev/redefinir_senha.php?token=' . urlencode($token);

    $corpo = montarEmailHtmlDev(
        $pdo,
        'Conta de desenvolvedor bloqueada por segurança',
        [
            'Olá, ' . htmlspecialchars($primeiroNome) . '.',
            'Sua conta de desenvolvedor no sistema <strong>' . htmlspecialchars($nomeSistema) . '</strong> foi bloqueada depois de várias tentativas de login incorretas seguidas — uma medida de segurança contra ataques.',
            'Pra voltar a acessar, redefina sua senha usando o botão abaixo. O link é válido por ' . DEV_MINUTOS_VALIDADE_TOKEN_RESET . ' minutos.',
        ],
        'Redefinir minha senha',
        $link
    );

    return enviarEmailSMTP($pdo, $dev['email'], 'Conta bloqueada por segurança — ' . $nomeSistema, $corpo);
}
