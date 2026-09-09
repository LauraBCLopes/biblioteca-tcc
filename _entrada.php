<?php
/**
 * Ponto de entrada único para a Vercel.
 *
 * O plano gratuito da Vercel permite no máximo 12 funções serverless por
 * deploy quando cada arquivo vira uma função separada — e este projeto tem
 * ~20 páginas .php na raiz. Em vez de reestruturar tudo, uma única função
 * (esta) recebe todas as requisições (via "routes" no vercel.json) e
 * carrega por dentro o arquivo .php correspondente ao caminho pedido.
 *
 * Nenhuma página precisa saber disso: $_GET, $_POST, $_SERVER, sessão,
 * cookies etc. continuam funcionando normalmente, porque isso é resolvido
 * pelo PHP antes deste arquivo rodar — carregar outro .php com require
 * não muda nada disso.
 *
 * Arquivos estáticos (assets/css/...) NÃO passam por aqui: o
 * "handle": "filesystem" no vercel.json os serve direto, antes de cair
 * nesta rota.
 */

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$arquivo = basename($caminho ?? '');

if ($arquivo === '' || $arquivo === '/') {
    $arquivo = 'index.php';
}

// só deixa carregar um .php que exista de fato na raiz do projeto —
// impede tentativa de acessar outro caminho/arquivo por fora daqui.
if (!preg_match('/^[a-zA-Z0-9_]+\.php$/', $arquivo) || $arquivo === basename(__FILE__)) {
    http_response_code(404);
    echo 'Página não encontrada.';
    exit;
}

$caminhoCompleto = __DIR__ . '/' . $arquivo;

if (!is_file($caminhoCompleto)) {
    http_response_code(404);
    echo 'Página não encontrada.';
    exit;
}

require $caminhoCompleto;
