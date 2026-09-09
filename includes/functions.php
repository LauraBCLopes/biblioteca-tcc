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
