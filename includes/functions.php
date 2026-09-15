<?php
/**
 * Funções auxiliares usadas em várias páginas do sistema.
 */

/**
 * Guarda uma mensagem para ser exibida na PRÓXIMA página carregada
 * (padrão "flash message" — útil depois de um redirect, por exemplo
 * após salvar um formulário).
 */
function flash(string $mensagem, string $tipo = 'ok'): void
{
    $_SESSION['flash'] = ['mensagem' => $mensagem, 'tipo' => $tipo];
}

/**
 * Recupera a mensagem flash (se houver) e a remove da sessão,
 * para que ela apareça só uma vez.
 */
function pegarFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/**
 * Atalho para escapar valores antes de imprimir no HTML (evita XSS).
 */
function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * SVG inline do logo Bibliotech (livro aberto, gradiente roxo → azul).
 * Inline (não é um arquivo de imagem) para funcionar igual local e na Vercel,
 * e para poder herdar as cores do tema via gradiente definido no próprio SVG.
 */
function bibliotechLogo(int $tamanho = 34): string
{
    $id = 'lg' . $tamanho;
    return '<svg width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="40" y2="40">
          <stop offset="0" stop-color="#6C63E8"/><stop offset="1" stop-color="#3F7FE8"/>
        </linearGradient></defs>
        <rect width="40" height="40" rx="10" fill="url(#' . $id . ')"/>
        <path d="M20 13c-2.4-1.6-6-2-9-1.2v14.4c3-.8 6.6-.4 9 1.2 2.4-1.6 6-2 9-1.2V11.8c-3-.8-6.6-.4-9 1.2Z"
              fill="none" stroke="#fff" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>
        <path d="M20 13v14.4" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
      </svg>';
}

/**
 * Monta o e-mail final a partir do que foi digitado num campo "usuário":
 * se já tem "@", usa exatamente como foi digitado (mantém contas antigas,
 * com e-mail em outro domínio, funcionando); senão, completa com o domínio
 * fixo do tipo de conta (ex.: "aluno.com.br").
 */
function montarEmailComDominio(string $entrada, string $dominio): string
{
    $entrada = trim($entrada);
    return strpos($entrada, '@') !== false ? $entrada : $entrada . '@' . $dominio;
}

/**
 * Cria uma notificação para um usuário (admin ou aluno), evitando duplicar
 * o mesmo aviso no mesmo dia (ex.: não notificar "atrasado" a cada
 * carregamento de página, só uma vez por dia).
 */
function criarNotificacao(PDO $pdo, string $usuarioTipo, int $usuarioId, string $titulo, string $mensagem): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificacoes
        WHERE usuario_tipo = :tipo AND usuario_id = :id AND titulo = :titulo
          AND data_criacao >= :desde");
    $stmt->execute([
        'tipo'  => $usuarioTipo,
        'id'    => $usuarioId,
        'titulo'=> $titulo,
        'desde' => date('Y-m-d 00:00:00'),
    ]);
    if ($stmt->fetchColumn() > 0) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO notificacoes (usuario_tipo, usuario_id, titulo, mensagem, lida, data_criacao)
        VALUES (:tipo, :id, :titulo, :mensagem, 0, :agora)');
    $stmt->execute([
        'tipo'    => $usuarioTipo,
        'id'      => $usuarioId,
        'titulo'  => $titulo,
        'mensagem'=> $mensagem,
        'agora'   => date('Y-m-d H:i:s'),
    ]);
}

/** Gera as iniciais (até 2 letras) de um nome, usadas no avatar circular. */
function iniciaisNome(string $nome): string
{
    $iniciais = '';
    foreach (array_slice(explode(' ', trim($nome)), 0, 2) as $parte) {
        $iniciais .= mb_strtoupper(mb_substr($parte, 0, 1));
    }
    return $iniciais;
}
