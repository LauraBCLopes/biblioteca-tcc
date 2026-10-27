<?php
/**
 * Versão do CSS/JS, colocada no fim do endereço (estilo.css?v=...).
 * Troque este valor sempre que mudar o CSS ou o JS: assim o navegador
 * (principalmente no celular) baixa o arquivo novo em vez de usar o antigo.
 */
const VERSAO_ASSETS = '2026-10-26a';

/**
 * Funções auxiliares usadas em várias páginas do sistema.
 */

/**
 * Guarda uma mensagem para ser exibida na PRÓXIMA página carregada
 * (padrão "flash message" — útil depois de um redirect, por exemplo
 * após salvar um formulário).
 */
function flash(string $mensagem, string $tipo = 'ok', ?array $acao = null): void
{
    // $acao (opcional): ['texto' => 'Desfazer', 'url' => '...'] vira um link na mensagem.
    $_SESSION['flash'] = ['mensagem' => $mensagem, 'tipo' => $tipo, 'acao' => $acao];
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
 * SVG inline do logo Bibliotech: uma casa (telhado) com quatro livros em pé
 * sobre uma base, em fundo transparente. As cores vêm das variáveis do tema
 * (--logo-*, em estilo.css): telhado e base na cor do texto (azul-marinho no
 * claro, quase branco no escuro) e livros no roxo/azul/lilás do sistema.
 * Inline (não é um arquivo de imagem) para funcionar igual local e na Vercel.
 */
function bibliotechLogo(int $tamanho = 34): string
{
    // livros: x, topo, largura, variável de cor (todos terminam em cima da base)
    $livros = [
        [7.7, 17.9, 5.0, '--logo-livro-1'],
        [14.1, 15.4, 4.8, '--logo-livro-2'],
        [20.3, 15.9, 5.4, '--logo-livro-3'],
        [27.0, 18.3, 5.0, '--logo-livro-4'],
    ];
    $base = 31.8;
    $desenho = '';
    foreach ($livros as [$x, $topo, $largura, $cor]) {
        $desenho .= '<rect x="' . $x . '" y="' . $topo . '" width="' . $largura . '" height="' . round($base - $topo, 2) . '" rx="0.9" style="fill:var(' . $cor . ')"/>';
        // faixas claras da lombada: duas perto do topo e uma perto da base
        foreach ([$topo + 2.2, $topo + 3.6, $base - 2.3] as $faixa) {
            $desenho .= '<rect x="' . $x . '" y="' . round($faixa, 2) . '" width="' . $largura . '" height="0.7" fill="#fff" fill-opacity="0.6"/>';
        }
    }
    return '<svg class="logo-bibliotech" width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Bibliotech">
        <g transform="translate(20 20.6) scale(1.1) translate(-20 -20.6)">
        <path d="M8 16.6 19.9 10.2 31.8 16.6Z" style="fill:var(--logo-sotao)"/>
        ' . $desenho . '
        <path d="M6.3 14.3 19.9 7.3 33.5 14.3" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="stroke:var(--logo-telhado)"/>
        <rect x="5.5" y="32.3" width="29" height="1.7" rx="0.85" style="fill:var(--logo-telhado)"/>
        </g>
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
        VALUES (:tipo, :id, :titulo, :mensagem, FALSE, :agora)');
    $stmt->execute([
        'tipo'    => $usuarioTipo,
        'id'      => $usuarioId,
        'titulo'  => $titulo,
        'mensagem'=> $mensagem,
        'agora'   => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Normaliza uma coluna boolean vinda do banco para um bool PHP de verdade.
 * Necessário porque o PDO_PGSQL devolve 't'/'f' (strings, e ambas são
 * "truthy" em PHP), enquanto o MySQL devolve '1'/'0'.
 */
function bdBool($valor): bool
{
    return $valor === true || $valor === 1 || $valor === '1' || $valor === 't';
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

/** Abreviação dos meses em português, indexada por 'MM'. */
const MESES_ABREV = ['01'=>'jan','02'=>'fev','03'=>'mar','04'=>'abr','05'=>'mai','06'=>'jun',
                     '07'=>'jul','08'=>'ago','09'=>'set','10'=>'out','11'=>'nov','12'=>'dez'];

/**
 * Conta empréstimos e devoluções por mês ('AAAA-MM') entre $de e $ate.
 * Agrupamento feito no PHP: MySQL usa DATE_FORMAT e Postgres usa TO_CHAR,
 * então buscar as datas e contar aqui mantém uma única versão da consulta.
 * Limita a 24 meses para o gráfico continuar legível.
 */
function movimentoPorMes(PDO $pdo, string $de, string $ate): array
{
    $meses = [];
    $cursor = new DateTime(substr($de, 0, 7) . '-01');
    $ultimoMes = new DateTime(substr($ate, 0, 7) . '-01');
    while ($cursor <= $ultimoMes && count($meses) < 24) {
        $meses[$cursor->format('Y-m')] = ['emprestimos' => 0, 'devolucoes' => 0];
        $cursor->modify('+1 month');
    }

    // placeholders distintos para cada BETWEEN: com EMULATE_PREPARES desligado,
    // o mesmo nome não pode aparecer duas vezes na consulta
    $stmt = $pdo->prepare('SELECT data_emprestimo, data_devolucao FROM emprestimos
        WHERE data_emprestimo BETWEEN :de_emp AND :ate_emp
           OR data_devolucao  BETWEEN :de_dev AND :ate_dev');
    $stmt->execute(['de_emp' => $de, 'ate_emp' => $ate, 'de_dev' => $de, 'ate_dev' => $ate]);

    foreach ($stmt->fetchAll() as $linha) {
        $emp = (string) $linha['data_emprestimo'];
        if ($emp >= $de && $emp <= $ate && isset($meses[substr($emp, 0, 7)])) {
            $meses[substr($emp, 0, 7)]['emprestimos']++;
        }
        $dev = (string) $linha['data_devolucao'];
        if ($dev !== '' && $dev >= $de && $dev <= $ate && isset($meses[substr($dev, 0, 7)])) {
            $meses[substr($dev, 0, 7)]['devolucoes']++;
        }
    }
    return $meses;
}

/**
 * Gráfico de colunas agrupadas (empréstimos x devoluções por mês), em HTML/CSS puro.
 * Cada coluna mostra o valor exato ao passar o mouse (ou focar pelo teclado).
 */
function graficoMovimento(array $meses): string
{
    $maior = 1;
    foreach ($meses as $d) {
        $maior = max($maior, $d['emprestimos'], $d['devolucoes']);
    }

    $colunas = '';
    $rotulos = '';
    foreach ($meses as $chave => $d) {
        [$ano, $mes] = explode('-', $chave);
        $nomeMes = MESES_ABREV[$mes] . '/' . substr($ano, 2);
        $colunas .= '<div class="col-grupo"><div class="col-barras">';
        foreach (['emprestimos' => ['s1', 'empréstimo'], 'devolucoes' => ['s2', 'devolução']] as $campo => [$classe, $nome]) {
            $qtd = (int) $d[$campo];
            $plural = $qtd === 1 ? $nome : ($campo === 'emprestimos' ? 'empréstimos' : 'devoluções');
            $colunas .= '<div class="col ' . $classe . '" tabindex="0" style="height:' . round(($qtd / $maior) * 100, 1) . '%"'
                      . ' aria-label="' . e("$nomeMes: $qtd $plural") . '">'
                      . '<span class="tip">' . e("$nomeMes · $qtd $plural") . '</span></div>';
        }
        $colunas .= '</div></div>';
        $rotulos .= '<div>' . e($nomeMes) . '</div>';
    }

    return '<div class="legenda"><span><i style="background:var(--serie-1)"></i>Empréstimos</span>'
         . '<span><i style="background:var(--serie-2)"></i>Devoluções</span></div>'
         . '<div class="col-chart-wrap"><div class="col-chart">' . $colunas . '</div>'
         . '<div class="col-rotulos">' . $rotulos . '</div></div>';
}

/** Temas de cores disponíveis em Configurações → Aparência. */
const TEMAS = ['claro' => 'Claro', 'escuro' => 'Escuro', 'sistema' => 'Automático'];

/**
 * Tema escolhido pela pessoa neste navegador (cookie "tema"); sem escolha,
 * abre no escuro. Fica em cookie, e não no banco, para valer também nas
 * telas de login (antes de saber quem é o usuário) e para não exigir migração.
 */
function temaAtual(): string
{
    $tema = $_COOKIE['tema'] ?? 'escuro';
    return isset(TEMAS[$tema]) ? $tema : 'escuro';
}

/**
 * Atributo data-theme para o <html>: força claro/escuro quando a pessoa
 * escolheu um; no "Automático" não põe nada e o CSS segue o sistema.
 * Vem pronto do PHP para a página já abrir no tema certo, sem piscar.
 */
function atributoTema(): string
{
    $tema = temaAtual();
    if ($tema === 'sistema') {
        return '';
    }
    return ' data-theme="' . ($tema === 'escuro' ? 'dark' : 'light') . '"';
}

/**
 * Mantém as $limite primeiras linhas (já ordenadas por 'qtd', da maior para
 * a menor) e soma o resto numa linha "Outras", para gráficos de categoria
 * não crescerem sem fim conforme o acervo ganha gêneros. A linha "Outras"
 * leva em 'detalhe' os nomes agrupados, mostrados ao passar o mouse.
 */
function agruparOutras(array $linhas, int $limite = 6): array
{
    if (count($linhas) <= $limite + 1) {   // só 1 sobrando: mostra ela mesma
        return $linhas;
    }
    $resto = array_slice($linhas, $limite);
    $principais = array_slice($linhas, 0, $limite);
    $principais[] = [
        'nome'    => 'Outras (' . count($resto) . ')',
        'qtd'     => array_sum(array_map('intval', array_column($resto, 'qtd'))),
        'detalhe' => implode(', ', array_column($resto, 'nome')),
    ];
    return $principais;
}
