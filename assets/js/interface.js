// Interações puramente visuais: menu hambúrguer (mobile) e dropdowns do topo.
// Nenhuma chamada ao servidor aqui — regras de negócio ficam todas no PHP.
document.addEventListener('DOMContentLoaded', function () {
  var hamburger = document.querySelector('.hamburger');
  if (hamburger) {
    hamburger.addEventListener('click', function () {
      document.body.classList.toggle('menu-aberto');
    });
  }

  document.body.addEventListener('click', function (evento) {
    if (document.body.classList.contains('menu-aberto') &&
        !evento.target.closest('.sidebar') &&
        !evento.target.closest('.hamburger')) {
      document.body.classList.remove('menu-aberto');
    }
  });

  document.querySelectorAll('.sidebar nav a').forEach(function (link) {
    link.addEventListener('click', function () {
      document.body.classList.remove('menu-aberto');
    });
  });

  // Campos "usuário + domínio fixo" (login, cadastro, cadastro de leitor):
  // escondem o sufixo (@aluno.com.br etc.) quando a pessoa digita um e-mail
  // completo de uma conta antiga (com "@"), já que nesse caso o valor é
  // usado exatamente como digitado.
  document.querySelectorAll('.login-user-field').forEach(function (wrap) {
    var input = wrap.querySelector('input');
    var sufixo = wrap.querySelector('.login-suffix');
    if (!input || !sufixo) { return; }
    var atualizar = function () {
      sufixo.hidden = input.value.indexOf('@') !== -1;
    };
    input.addEventListener('input', atualizar);
    atualizar();
  });

  // Tela de login: abas "Sou aluno" / "Sou administrador" trocam o domínio
  // sugerido (@aluno.com.br / @admin.com.br) e destacam a aba ativa.
  var dominios = { aluno: 'aluno.com.br', admin: 'admin.com.br' };
  var tipoLoginInput = document.getElementById('tipo_login');
  var sufixoLogin = document.getElementById('login_suffix');

  document.querySelectorAll('.login-tab').forEach(function (aba) {
    aba.addEventListener('click', function () {
      document.querySelectorAll('.login-tab').forEach(function (outra) {
        outra.classList.remove('active');
      });
      aba.classList.add('active');
      if (tipoLoginInput) { tipoLoginInput.value = aba.dataset.tipo; }
      if (sufixoLogin) { sufixoLogin.textContent = '@' + dominios[aba.dataset.tipo]; }
    });
  });

  document.querySelectorAll('.dropdown-wrap').forEach(function (wrap) {
    var botao = wrap.querySelector('.dropdown-trigger');
    if (!botao) { return; }

    botao.addEventListener('click', function (evento) {
      evento.stopPropagation();
      var estavaAberto = wrap.classList.contains('open');
      document.querySelectorAll('.dropdown-wrap.open').forEach(function (outro) {
        outro.classList.remove('open');
      });
      if (!estavaAberto) {
        wrap.classList.add('open');
      }
    });
  });

  document.addEventListener('click', function () {
    document.querySelectorAll('.dropdown-wrap.open').forEach(function (wrap) {
      wrap.classList.remove('open');
    });
  });
});
