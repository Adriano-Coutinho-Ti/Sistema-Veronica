<?php
/**
 * Envio de e-mail via SMTP autenticado (sem depender de biblioteca externa — só
 * socket + protocolo SMTP puro), usando as credenciais SMTP_* definidas em
 * brechodaveve_config_credenciais.php (mesmo arquivo externo que já guarda as
 * credenciais do banco — nunca fica dentro do repositório).
 */

/**
 * Monta um e-mail HTML com a cara da própria loja (logo + nome da loja vindos de
 * config_loja, não do CoderNex) — quem recebe é cliente da loja, não da CoderNex.
 */
function montarEmailHtmlLoja(PDO $pdo, string $titulo, array $paragrafos, string $textoBotao, string $linkBotao): string
{
    $configLoja = $pdo->query('SELECT nome_loja, logo_arquivo FROM config_loja WHERE id_config = 1')->fetch();
    $nomeLoja = $configLoja['nome_loja'] ?? 'Loja';

    $paragrafosHtml = '';
    foreach ($paragrafos as $paragrafo) {
        $paragrafosHtml .= '<p style="margin:0 0 14px; color:#444444; font-size:14px; line-height:1.55;">' . $paragrafo . '</p>';
    }

    $linkEscapado = htmlspecialchars($linkBotao);
    $cabecalhoLogo = !empty($configLoja['logo_arquivo'])
        ? '<img src="https://brechodaveve.codernex.com.br/' . htmlspecialchars($configLoja['logo_arquivo']) . '" alt="' . htmlspecialchars($nomeLoja) . '" style="max-height:48px; width:auto;">'
        : '<span style="font-family:Georgia,serif; font-size:22px; font-weight:600; color:#222222;">' . htmlspecialchars($nomeLoja) . '</span>';

    return '
    <div style="background:#f4f6f8; padding:32px 16px; font-family:Arial, Helvetica, sans-serif;">
      <div style="max-width:460px; margin:0 auto;">
        <div style="text-align:center; padding-bottom:22px;">' . $cabecalhoLogo . '</div>
        <div style="background:#ffffff; border-radius:10px; padding:32px; box-shadow:0 2px 12px rgba(0,0,0,0.07);">
          <h2 style="margin:0 0 18px; font-size:19px; color:#222222;">' . htmlspecialchars($titulo) . '</h2>
          ' . $paragrafosHtml . '
          <div style="text-align:center; margin:28px 0 8px;">
            <a href="' . $linkEscapado . '" style="background:#8B5CF6; color:#ffffff; text-decoration:none; padding:13px 32px; border-radius:6px; font-weight:bold; font-size:14px; display:inline-block;">' . htmlspecialchars($textoBotao) . '</a>
          </div>
          <p style="font-size:12px; color:#999999; margin-top:24px; line-height:1.5;">Se o botão não funcionar, copie e cole este link no seu navegador:<br>
          <a href="' . $linkEscapado . '" style="color:#8B5CF6; word-break:break-all;">' . $linkEscapado . '</a></p>
        </div>
        <p style="text-align:center; color:#aaaaaa; font-size:12px; margin-top:24px;">' . htmlspecialchars($nomeLoja) . '</p>
      </div>
    </div>';
}

/**
 * Credenciais SMTP: vêm do painel_dev (config_dev) se o desenvolvedor já
 * preencheu por lá, senão caem nas constantes SMTP_* definidas em
 * brechodaveve_config_credenciais.php, do jeito que sempre funcionou.
 */
function smtpCredenciais(PDO $pdo): array
{
    $configDev = $pdo->query('SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from_email, smtp_from_name FROM config_dev WHERE id_config = 1')->fetch();

    return [
        'host' => $configDev['smtp_host'] ?? null ?: (defined('SMTP_HOST') ? SMTP_HOST : null),
        'port' => $configDev['smtp_port'] ?? null ?: (defined('SMTP_PORT') ? SMTP_PORT : null),
        'user' => $configDev['smtp_user'] ?? null ?: (defined('SMTP_USER') ? SMTP_USER : null),
        'pass' => $configDev['smtp_pass'] ?? null ?: (defined('SMTP_PASS') ? SMTP_PASS : null),
        'from_email' => $configDev['smtp_from_email'] ?? null ?: (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : null),
        'from_name' => $configDev['smtp_from_name'] ?? null ?: (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Loja'),
    ];
}

/**
 * $corpoTexto é opcional — se não informado, é gerado automaticamente a partir do
 * HTML (tags removidas), pra clientes de e-mail que não mostram HTML caírem numa
 * versão em texto puro em vez de ver o código HTML cru.
 */
function enviarEmailSMTP(PDO $pdo, string $destinatario, string $assunto, string $corpoHtml, ?string $corpoTexto = null): array
{
    $smtp = smtpCredenciais($pdo);
    if (!$smtp['host'] || !$smtp['user'] || !$smtp['pass']) {
        return ['success' => false, 'message' => 'SMTP não configurado (painel_dev ou brechodaveve_config_credenciais.php).'];
    }

    // Porta 465 = SSL implícito (a conexão já nasce criptografada, sem handshake em
    // texto puro antes). Porta 587 (ou qualquer outra) = STARTTLS (conecta em texto
    // puro e só depois manda "STARTTLS" pra criptografar). São protocolos diferentes —
    // usar o esquema errado pra porta trava ou é recusado pelo servidor.
    $usa_ssl_implicito = ((int) $smtp['port'] === 465);
    $esquema = $usa_ssl_implicito ? 'ssl://' : 'tcp://';

    $socket = @stream_socket_client(
        $esquema . $smtp['host'] . ':' . $smtp['port'],
        $errno, $errstr, 15
    );
    if (!$socket) {
        return ['success' => false, 'message' => "Não foi possível conectar ao SMTP ($errstr)"];
    }
    stream_set_timeout($socket, 15);

    $lerResposta = function () use ($socket) {
        $resposta = '';
        while (($linha = fgets($socket, 515)) !== false) {
            $resposta .= $linha;
            // Linha final de uma resposta multi-linha tem um espaço na 4ª coluna (ex: "250 ")
            // em vez de hífen (ex: "250-"). Sem checar isso, paramos de ler no meio da resposta.
            if (isset($linha[3]) && $linha[3] === ' ') {
                break;
            }
        }
        return $resposta;
    };
    $enviarComando = function ($cmd) use ($socket) {
        fwrite($socket, $cmd . "\r\n");
    };
    $codigo = function ($resposta) {
        return (int) substr($resposta, 0, 3);
    };

    $lerResposta(); // saudação inicial do servidor

    $enviarComando('EHLO brechodaveve.codernex.com.br');
    $lerResposta();

    // Se a conexão já é SSL implícito (porta 465), não faz STARTTLS — já está
    // criptografada desde o "ssl://" do stream_socket_client acima.
    if (!$usa_ssl_implicito) {
        $enviarComando('STARTTLS');
        $respTls = $lerResposta();
        if ($codigo($respTls) === 220) {
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['success' => false, 'message' => 'Falha ao negociar TLS com o servidor SMTP.'];
            }
            $enviarComando('EHLO brechodaveve.codernex.com.br');
            $lerResposta();
        }
    }

    $enviarComando('AUTH LOGIN');
    $lerResposta();
    $enviarComando(base64_encode($smtp['user']));
    $lerResposta();
    $enviarComando(base64_encode($smtp['pass']));
    $respAuth = $lerResposta();
    if ($codigo($respAuth) !== 235) {
        $enviarComando('QUIT');
        fclose($socket);
        return ['success' => false, 'message' => 'Autenticação SMTP recusada (usuário/senha). Resposta: ' . trim($respAuth)];
    }

    $remetente = $smtp['from_email'];
    $enviarComando("MAIL FROM:<$remetente>");
    $lerResposta();
    $enviarComando("RCPT TO:<$destinatario>");
    $respRcpt = $lerResposta();
    if ($codigo($respRcpt) >= 500) {
        $enviarComando('QUIT');
        fclose($socket);
        return ['success' => false, 'message' => 'Destinatário recusado pelo servidor: ' . trim($respRcpt)];
    }

    $enviarComando('DATA');
    $lerResposta();

    if ($corpoTexto === null) {
        // Fallback automático: HTML -> texto puro (quebra de linha nos <br>/</p>, tags removidas)
        $corpoTexto = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $corpoHtml)), ENT_QUOTES, 'UTF-8'));
        $corpoTexto = preg_replace("/\n{3,}/", "\n\n", $corpoTexto);
    }

    $assuntoCodificado = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    $nomeRemetente = $smtp['from_name'];

    // multipart/alternative: manda as duas versões (texto puro + HTML) na mesma
    // mensagem. Cliente de e-mail que entende HTML mostra a versão bonita; quem não
    // entende (ou o usuário preferir "ver como texto simples") cai na versão em texto.
    $boundary = 'brechodaveve_' . bin2hex(random_bytes(12));

    $cabecalhos = "From: {$nomeRemetente} <{$remetente}>\r\n"
                . "To: <{$destinatario}>\r\n"
                . "Subject: {$assuntoCodificado}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    // Linhas que começam com "." precisam de um "." extra (regra do protocolo SMTP,
    // senão o servidor interpreta como fim da mensagem no meio do texto).
    $escapar = function ($texto) { return preg_replace('/^\./m', '..', $texto); };

    $mensagem = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $escapar($corpoTexto) . "\r\n\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $escapar($corpoHtml) . "\r\n\r\n"
              . "--{$boundary}--";

    $enviarComando($cabecalhos . "\r\n" . $mensagem . "\r\n.");
    $respDados = $lerResposta();

    $enviarComando('QUIT');
    fclose($socket);

    if ($codigo($respDados) !== 250) {
        return ['success' => false, 'message' => 'Servidor recusou o envio: ' . trim($respDados)];
    }

    return ['success' => true, 'message' => 'E-mail enviado.'];
}
