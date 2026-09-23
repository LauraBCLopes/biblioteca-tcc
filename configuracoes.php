<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getConexao();
$usuario = adminAtual();

$tabela = isAdmin() ? 'administradores' : 'leitores';

// Máximo de administradores que o sistema aceita ao todo (contando o inicial,
// criado via scripts/seed_admin.php). Acima disso, criar mais exige acesso
// direto ao banco — não dá pra fazer pela tela.
const MAX_ADMINISTRADORES = 2;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'trocar_senha';

    // ---------- aparência (tema claro/escuro) — admin ou aluno ----------
    if ($acao === 'tema') {
        $tema = $_POST['tema'] ?? 'sistema';
        if (isset(TEMAS[$tema])) {
            // atrás do proxy da Vercel o PHP recebe HTTP; o protocolo real vem no cabeçalho
            $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            // assinatura antiga de setcookie() para funcionar também no PHP 7.2 do XAMPP
            setcookie('tema', $tema, time() + 365 * 24 * 60 * 60, '/', '', $https, true);
            flash('Aparência alterada para: ' . TEMAS[$tema] . '.');
        }
        header('Location: configuracoes.php');
        exit;
    }

    // ---------- criar administrador — só administrador logado, até o limite ----------
    if ($acao === 'criar_admin') {
        if (!isAdmin()) {
            flash('Acesso restrito a administradores.', 'erro');
            header('Location: configuracoes.php');
            exit;
        }

        $nomeNovo    = trim($_POST['novo_nome'] ?? '');
        $usuarioNovo = trim($_POST['novo_usuario'] ?? '');
        $senhaNova   = $_POST['nova_senha_admin'] ?? '';
        $confirmaNova = $_POST['confirmar_senha_admin'] ?? '';
        $emailNovo   = montarEmailComDominio($usuarioNovo, 'admin.com.br');

        try {
            $pdo->beginTransaction();

            // trava as linhas de administradores para evitar dois cadastros
            // simultâneos furarem o limite de MAX_ADMINISTRADORES
            $stmt = $pdo->query('SELECT id FROM administradores FOR UPDATE');
            $totalAdmins = count($stmt->fetchAll());

            if ($totalAdmins >= MAX_ADMINISTRADORES) {
                throw new RuntimeException('Limite de ' . MAX_ADMINISTRADORES . ' administradores já atingido.');
            }
            if ($nomeNovo === '' || $usuarioNovo === '' || !filter_var($emailNovo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Preencha o nome e um usuário/e-mail válido para o novo administrador.');
            }
            if (mb_strlen($senhaNova) < 6) {
                throw new RuntimeException('A senha do novo administrador precisa ter pelo menos 6 caracteres.');
            }
            if ($senhaNova !== $confirmaNova) {
                throw new RuntimeException('As senhas digitadas não são iguais.');
            }

            // e-mail de aluno (autocadastrado ou não) não pode virar administrador,
            // pela mesma regra que já impede o contrário em cadastro.php
            $stmt = $pdo->prepare('SELECT id FROM leitores WHERE email = :email');
            $stmt->execute(['email' => $emailNovo]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Este e-mail já é usado por uma conta de aluno. Use outro e-mail para o administrador.');
            }

            $stmt = $pdo->prepare('SELECT id FROM administradores WHERE usuario = :usuario');
            $stmt->execute(['usuario' => $emailNovo]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Já existe um administrador com este e-mail.');
            }

            $stmt = $pdo->prepare('INSERT INTO administradores (nome, usuario, senha_hash) VALUES (:nome, :usuario, :hash)');
            $stmt->execute([
                'nome'    => $nomeNovo,
                'usuario' => $emailNovo,
                'hash'    => password_hash($senhaNova, PASSWORD_DEFAULT),
            ]);

            $pdo->commit();
            flash('Administrador criado com sucesso.');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash($ex->getMessage(), 'erro');
        }

        header('Location: configuracoes.php');
        exit;
    }

    // ---------- trocar a própria senha — admin ou aluno ----------
    $senhaAtual   = $_POST['senha_atual'] ?? '';
    $novaSenha    = $_POST['nova_senha'] ?? '';
    $confirmaSenha = $_POST['confirmar_senha'] ?? '';

    $stmt = $pdo->prepare("SELECT senha_hash FROM {$tabela} WHERE id = :id");
    $stmt->execute(['id' => $usuario['id']]);
    $hashAtual = $stmt->fetchColumn();

    if ($hashAtual === null || !password_verify($senhaAtual, $hashAtual)) {
        flash('Senha atual incorreta.', 'erro');
    } elseif (mb_strlen($novaSenha) < 6) {
        flash('A nova senha precisa ter pelo menos 6 caracteres.', 'erro');
    } elseif ($novaSenha !== $confirmaSenha) {
        flash('As senhas digitadas não são iguais.', 'erro');
    } else {
        $stmt = $pdo->prepare("UPDATE {$tabela} SET senha_hash = :hash WHERE id = :id");
        $stmt->execute(['hash' => password_hash($novaSenha, PASSWORD_DEFAULT), 'id' => $usuario['id']]);
        flash('Senha atualizada com sucesso.');
    }

    header('Location: configuracoes.php');
    exit;
}

$listaAdmins = [];
if (isAdmin()) {
    $listaAdmins = $pdo->query('SELECT id, nome, usuario FROM administradores ORDER BY id')->fetchAll();
}

$tituloAba = 'Configurações';
$paginaAtual = 'configuracoes';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Configurações</div>
        <h2>Minha conta</h2>
      </div>
    </div>

    <div class="panel" style="max-width:480px;">
      <h4 style="margin-bottom:6px;font-size:14px;">Aparência</h4>
      <p class="text-muted" style="font-size:12.5px;margin:0 0 16px;">
        Escolha as cores do sistema. Fica salvo neste navegador.
      </p>
      <form method="post" action="configuracoes.php" id="form-tema">
        <input type="hidden" name="acao" value="tema">
        <fieldset class="tema-opcoes">
          <legend class="text-muted" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Tema</legend>
          <?php foreach (TEMAS as $valor => $nome): ?>
            <label class="tema-opcao">
              <input type="radio" name="tema" value="<?= e($valor) ?>" <?= temaAtual() === $valor ? 'checked' : '' ?>>
              <span class="cartao">
                <span class="tema-previa <?= e($valor) ?>" aria-hidden="true">
                  <span class="lado"></span>
                  <span class="corpo"><?php if ($valor !== 'sistema'): ?><i></i><i></i><i></i><?php endif; ?></span>
                </span>
                <span class="tema-nome">
                  <span><?= e($nome) ?><br><small><?= ['claro' => 'fundo branco', 'escuro' => 'fundo escuro', 'sistema' => 'segue o sistema'][$valor] ?></small></span>
                </span>
              </span>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <noscript><button type="submit" class="btn primary small" style="margin-top:14px;">Salvar aparência</button></noscript>
      </form>
    </div>

    <div class="panel" style="max-width:480px;">
      <h3 style="margin-bottom:6px;"><?= e($usuario['nome']) ?></h3>
      <p class="text-muted" style="font-size:13px;margin:0 0 20px;">
        <?= e($usuario['login']) ?> · <?= isAdmin() ? 'Administrador' : 'Aluno' ?>
      </p>

      <h4 style="margin-bottom:14px;font-size:14px;">Alterar senha</h4>
      <form method="post" action="configuracoes.php">
        <input type="hidden" name="acao" value="trocar_senha">
        <div class="field">
          <label for="senha_atual">Senha atual</label>
          <input type="password" id="senha_atual" name="senha_atual" required>
        </div>
        <div class="field">
          <label for="nova_senha">Nova senha</label>
          <input type="password" id="nova_senha" name="nova_senha" required minlength="6">
        </div>
        <div class="field">
          <label for="confirmar_senha">Confirmar nova senha</label>
          <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="6">
        </div>
        <button type="submit" class="btn primary">Salvar nova senha</button>
      </form>
    </div>

    <?php if (isAdmin()): ?>
    <div class="panel" style="max-width:480px;">
      <h4 style="margin-bottom:6px;font-size:14px;">Administradores do sistema</h4>
      <p class="text-muted" style="font-size:12.5px;margin:0 0 16px;">
        <?= count($listaAdmins) ?>/<?= MAX_ADMINISTRADORES ?> administradores cadastrados.
        Alunos não podem virar administrador nem o contrário — são contas separadas.
      </p>

      <?php foreach ($listaAdmins as $adm): ?>
        <div class="attention-item">
          <span>
            <?= e($adm['nome']) ?>
            <?php if ((int) $adm['id'] === (int) $usuario['id']): ?><span class="badge muted">você</span><?php endif; ?>
          </span>
          <span class="mono text-muted" style="font-size:12px;"><?= e($adm['usuario']) ?></span>
        </div>
      <?php endforeach; ?>

      <?php if (count($listaAdmins) < MAX_ADMINISTRADORES): ?>
        <h4 style="margin:20px 0 14px;font-size:14px;">Adicionar administrador</h4>
        <form method="post" action="configuracoes.php">
          <input type="hidden" name="acao" value="criar_admin">
          <div class="field">
            <label for="novo_nome">Nome completo</label>
            <input type="text" id="novo_nome" name="novo_nome" required>
          </div>
          <div class="field">
            <label for="novo_usuario">Usuário</label>
            <div class="login-user-field">
              <input type="text" id="novo_usuario" name="novo_usuario" required placeholder="nome.sobrenome"
                     autocapitalize="off" autocorrect="off">
              <span class="login-suffix">@admin.com.br</span>
            </div>
          </div>
          <div class="field">
            <label for="nova_senha_admin">Senha</label>
            <input type="password" id="nova_senha_admin" name="nova_senha_admin" required minlength="6">
          </div>
          <div class="field">
            <label for="confirmar_senha_admin">Confirmar senha</label>
            <input type="password" id="confirmar_senha_admin" name="confirmar_senha_admin" required minlength="6">
          </div>
          <button type="submit" class="btn primary">Criar administrador</button>
        </form>
      <?php else: ?>
        <p class="text-muted" style="font-size:12.5px;margin:0;">
          Limite de <?= MAX_ADMINISTRADORES ?> administradores atingido.
        </p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
