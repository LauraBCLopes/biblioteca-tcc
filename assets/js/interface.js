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

  // Todo campo de senha ganha um botão de olho para mostrar/ocultar o que foi digitado.
  var OLHO_ABERTO = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M1.8 10S4.8 4.5 10 4.5 18.2 10 18.2 10 15.2 15.5 10 15.5 1.8 10 1.8 10Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.6" stroke="currentColor" stroke-width="1.5"/></svg>';
  var OLHO_FECHADO = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8.2 4.7c.6-.1 1.2-.2 1.8-.2 5.2 0 8.2 5.5 8.2 5.5s-.8 1.4-2.2 2.9M5.2 6.1C3 7.6 1.8 10 1.8 10S4.8 15.5 10 15.5c1.6 0 3-.5 4.2-1.2M8.2 8.2a2.6 2.6 0 0 0 3.6 3.6M3 3l14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var caixa = document.createElement('span');
    caixa.className = 'senha-campo';
    input.parentNode.insertBefore(caixa, input);
    caixa.appendChild(input);

    var olho = document.createElement('button');
    olho.type = 'button';
    olho.className = 'senha-olho';
    olho.setAttribute('aria-label', 'Mostrar senha');
    olho.setAttribute('aria-pressed', 'false');
    olho.innerHTML = OLHO_ABERTO;
    caixa.appendChild(olho);

    olho.addEventListener('click', function () {
      var mostrando = input.type === 'password';
      input.type = mostrando ? 'text' : 'password';
      olho.innerHTML = mostrando ? OLHO_FECHADO : OLHO_ABERTO;
      olho.setAttribute('aria-label', mostrando ? 'Ocultar senha' : 'Mostrar senha');
      olho.setAttribute('aria-pressed', mostrando ? 'true' : 'false');
      input.focus();
    });
  });

  // "Confirmar senha" (data-confirma="id da senha"): compara enquanto a pessoa
  // digita e NÃO deixa enviar o formulário se as duas forem diferentes.
  // O PHP confere de novo no servidor — isto só avisa antes.
  document.querySelectorAll('input[data-confirma]').forEach(function (confirmacao) {
    var senha = document.getElementById(confirmacao.dataset.confirma);
    var form = confirmacao.form;
    if (!senha || !form) { return; }

    var aviso = document.createElement('div');
    aviso.className = 'senha-aviso';
    aviso.setAttribute('aria-live', 'polite');
    aviso.hidden = true;
    confirmacao.closest('.senha-campo').insertAdjacentElement('afterend', aviso);

    var conferir = function (mostrarSeVazio) {
      var iguais = senha.value === confirmacao.value;
      confirmacao.setCustomValidity(iguais ? '' : 'As senhas não são iguais.');
      if (confirmacao.value === '' && !mostrarSeVazio) {
        aviso.hidden = true;
        return iguais;
      }
      aviso.hidden = false;
      aviso.classList.toggle('ok', iguais && confirmacao.value !== '');
      aviso.textContent = confirmacao.value === ''
        ? 'Digite a senha de novo para confirmar.'
        : (iguais ? '✓ As senhas são iguais.' : '✕ As senhas não são iguais.');
      return iguais && confirmacao.value !== '';
    };

    senha.addEventListener('input', function () { conferir(false); });
    confirmacao.addEventListener('input', function () { conferir(false); });

    form.addEventListener('submit', function (evento) {
      if (!conferir(true)) {
        evento.preventDefault();
        confirmacao.focus();
      }
    });
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
      // textos que só valem para um tipo de conta (ex.: "Cadastre-se" só para aluno)
      document.querySelectorAll('[data-so-tipo]').forEach(function (el) {
        el.hidden = el.dataset.soTipo !== aba.dataset.tipo;
      });
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

  // Listas suspensas dos formulários (.field select): a lista nativa do
  // navegador é desenhada fora da página e ignora o cursor personalizado,
  // então ela é trocada por uma lista feita em HTML. O <select> original
  // continua no formulário (escondido), recebendo o valor escolhido — o
  // envio e a validação de "campo obrigatório" seguem iguais. Só quando o
  // cursor próprio está ligado (veja abaixo); no celular fica a lista nativa.
  var usaCursorProprio = window.matchMedia('(hover: hover) and (pointer: fine)').matches &&
      !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function fecharSelects(exceto) {
    document.querySelectorAll('.select-custom.open').forEach(function (aberto) {
      if (aberto !== exceto) { aberto.classList.remove('open'); }
    });
  }

  (usaCursorProprio ? document.querySelectorAll('.field select') : []).forEach(function (select) {
    var wrap = document.createElement('div');
    wrap.className = 'select-custom';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.tabIndex = -1;

    var botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'select-custom-trigger';
    botao.setAttribute('aria-haspopup', 'listbox');
    var rotulo = select.closest('.field').querySelector('label');
    if (rotulo) { botao.setAttribute('aria-label', rotulo.textContent.trim()); }
    wrap.appendChild(botao);

    var lista = document.createElement('ul');
    lista.className = 'select-custom-lista';
    lista.setAttribute('role', 'listbox');
    wrap.appendChild(lista);

    var itens = Array.prototype.map.call(select.options, function (opcao, i) {
      var item = document.createElement('li');
      item.setAttribute('role', 'option');
      item.textContent = opcao.textContent.trim();
      if (opcao.disabled) { item.setAttribute('aria-disabled', 'true'); }
      item.addEventListener('mousedown', function (evento) { evento.preventDefault(); });
      item.addEventListener('click', function () {
        if (opcao.disabled) { return; }
        escolher(i);
        wrap.classList.remove('open');
        botao.focus();
      });
      lista.appendChild(item);
      return item;
    });
    var ativo = -1;

    function atualizar() {
      var escolhida = select.options[select.selectedIndex];
      botao.textContent = escolhida ? escolhida.textContent.trim() : '';
      botao.classList.toggle('vazio', !select.value);
      itens.forEach(function (item, i) {
        item.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false');
      });
    }

    function escolher(i) {
      if (select.selectedIndex === i) { return; }
      select.selectedIndex = i;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function destacar(i) {
      itens.forEach(function (item) { item.classList.remove('ativo'); });
      ativo = i;
      if (itens[i]) {
        itens[i].classList.add('ativo');
        itens[i].scrollIntoView({ block: 'nearest' });
      }
    }

    function mover(passo) {
      var i = ativo;
      do { i += passo; } while (itens[i] && select.options[i].disabled);
      if (itens[i]) { destacar(i); }
    }

    function abrir() {
      fecharSelects(wrap);
      wrap.classList.add('open');
      destacar(select.selectedIndex);
    }

    botao.addEventListener('click', function (evento) {
      evento.stopPropagation();
      if (wrap.classList.contains('open')) {
        wrap.classList.remove('open');
      } else {
        abrir();
      }
    });

    botao.addEventListener('keydown', function (evento) {
      var aberto = wrap.classList.contains('open');
      if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
        evento.preventDefault();
        if (!aberto) { abrir(); return; }
        mover(evento.key === 'ArrowDown' ? 1 : -1);
      } else if ((evento.key === 'Enter' || evento.key === ' ') && aberto) {
        evento.preventDefault();
        if (itens[ativo] && !select.options[ativo].disabled) { escolher(ativo); }
        wrap.classList.remove('open');
      } else if (evento.key === 'Escape' || evento.key === 'Tab') {
        wrap.classList.remove('open');
      }
    });

    lista.addEventListener('click', function (evento) { evento.stopPropagation(); });

    // campo obrigatório vazio: o aviso do navegador aparece no <select>
    // escondido, então o destaque vai para o botão visível
    select.addEventListener('invalid', function () { botao.focus(); });
    select.addEventListener('change', atualizar);
    if (select.form) {
      select.form.addEventListener('reset', function () { setTimeout(atualizar, 0); });
    }
    atualizar();
  });

  // Campos de data (.field input[type="date"]): o calendário nativo também é
  // desenhado fora da página, então ganha um calendário em HTML, nas mesmas
  // condições da lista acima. O input original fica escondido e continua
  // guardando a data como AAAA-MM-DD, que é o que o PHP recebe.
  var MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho',
    'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
  var DIAS_SEMANA = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];

  function paraISO(d) {
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }
  function deISO(texto) {
    var partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(texto || '');
    return partes ? new Date(+partes[1], partes[2] - 1, +partes[3]) : null;
  }
  function semHora(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }

  (usaCursorProprio ? document.querySelectorAll('.field input[type="date"]') : []).forEach(function (input) {
    var wrap = document.createElement('div');
    wrap.className = 'select-custom data-custom';   // select-custom: fecha junto com as listas
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.tabIndex = -1;

    var botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'data-custom-trigger';
    botao.setAttribute('aria-haspopup', 'dialog');
    var rotulo = input.closest('.field').querySelector('label');
    if (rotulo) {
      botao.setAttribute('aria-label', rotulo.textContent.trim());
      // o <label for> focaria o input escondido; leva para o botão visível
      rotulo.addEventListener('click', function (evento) {
        evento.preventDefault();
        botao.focus();
      });
    }
    wrap.appendChild(botao);

    var painel = document.createElement('div');
    painel.className = 'data-custom-painel';
    painel.innerHTML =
      '<div class="data-custom-topo">' +
        '<button type="button" data-mes="-1" aria-label="Mês anterior">‹</button>' +
        '<strong aria-live="polite"></strong>' +
        '<button type="button" data-mes="1" aria-label="Próximo mês">›</button>' +
      '</div>' +
      '<div class="data-custom-semana">' +
        DIAS_SEMANA.map(function (d) { return '<span>' + d + '</span>'; }).join('') +
      '</div>' +
      '<div class="data-custom-dias"></div>' +
      '<div class="data-custom-rodape"><button type="button" data-hoje>Hoje</button></div>';
    wrap.appendChild(painel);

    var titulo = painel.querySelector('strong');
    var grade = painel.querySelector('.data-custom-dias');
    var ativo = null;   // dia destacado no calendário (navegação pelo teclado)

    function permitido(d) {
      var min = deISO(input.getAttribute('min'));
      var max = deISO(input.getAttribute('max'));
      return !(min && d < min) && !(max && d > max);
    }

    function desenhar() {
      var ano = ativo.getFullYear(), mes = ativo.getMonth();
      titulo.textContent = MESES[mes] + ' de ' + ano;
      grade.innerHTML = '';
      var hoje = paraISO(new Date());
      var primeiro = new Date(ano, mes, 1);
      // 6 semanas fixas, começando no domingo antes (ou no) dia 1
      for (var i = 0; i < 42; i++) {
        var dia = new Date(ano, mes, 1 - primeiro.getDay() + i);
        var iso = paraISO(dia);
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = dia.getDate();
        b.dataset.data = iso;
        b.classList.toggle('fora', dia.getMonth() !== mes);
        b.classList.toggle('escolhido', iso === input.value);
        b.classList.toggle('hoje', iso === hoje);
        b.classList.toggle('ativo', iso === paraISO(ativo));
        b.disabled = !permitido(dia);
        grade.appendChild(b);
      }
    }

    function atualizar() {
      var d = deISO(input.value);
      botao.textContent = d ? d.toLocaleDateString('pt-BR') : 'dd/mm/aaaa';
      botao.classList.toggle('vazio', !d);
    }

    function escolher(d) {
      if (!permitido(d)) { return; }
      input.value = paraISO(d);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      wrap.classList.remove('open');
      botao.focus();
    }

    function abrir() {
      fecharSelects(wrap);
      ativo = semHora(deISO(input.value) || new Date());
      desenhar();
      wrap.classList.add('open');
    }

    function moverDias(n) {
      ativo = new Date(ativo.getFullYear(), ativo.getMonth(), ativo.getDate() + n);
      desenhar();
    }

    // troca de mês mantendo o dia (31/jan → 28/fev, não 3/mar)
    function moverMeses(n) {
      var ultimoDia = new Date(ativo.getFullYear(), ativo.getMonth() + n + 1, 0).getDate();
      ativo = new Date(ativo.getFullYear(), ativo.getMonth() + n, Math.min(ativo.getDate(), ultimoDia));
      desenhar();
    }

    botao.addEventListener('click', function (evento) {
      evento.stopPropagation();
      if (wrap.classList.contains('open')) {
        wrap.classList.remove('open');
      } else {
        abrir();
      }
    });

    var PASSOS = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    botao.addEventListener('keydown', function (evento) {
      var aberto = wrap.classList.contains('open');
      if (!aberto) {
        if (evento.key === 'ArrowDown') { evento.preventDefault(); abrir(); }
        return;
      }
      if (PASSOS[evento.key]) {
        evento.preventDefault();
        moverDias(PASSOS[evento.key]);
      } else if (evento.key === 'PageUp' || evento.key === 'PageDown') {
        evento.preventDefault();
        moverMeses(evento.key === 'PageUp' ? -1 : 1);
      } else if (evento.key === 'Enter' || evento.key === ' ') {
        evento.preventDefault();
        escolher(ativo);
      } else if (evento.key === 'Escape' || evento.key === 'Tab') {
        wrap.classList.remove('open');
      }
    });

    // o foco fica no botão do campo enquanto se clica no calendário
    painel.addEventListener('mousedown', function (evento) { evento.preventDefault(); });
    painel.addEventListener('click', function (evento) {
      evento.stopPropagation();
      var alvo = evento.target.closest('button');
      if (!alvo || alvo.disabled) { return; }
      if (alvo.dataset.mes) {
        moverMeses(+alvo.dataset.mes);
      } else if (alvo.hasAttribute('data-hoje')) {
        escolher(semHora(new Date()));
      } else if (alvo.dataset.data) {
        escolher(deISO(alvo.dataset.data));
      }
    });

    input.addEventListener('invalid', function () { botao.focus(); });
    input.addEventListener('change', atualizar);
    if (input.form) {
      input.form.addEventListener('reset', function () { setTimeout(atualizar, 0); });
    }
    atualizar();
  });

  document.addEventListener('click', function () { fecharSelects(null); });

  // Tabelas que viram cards no celular: copia o nome de cada coluna para
  // as células (data-label), que o CSS mostra ao lado do valor.
  document.querySelectorAll('.tabela-cartoes table').forEach(function (tabela) {
    var nomes = Array.prototype.map.call(tabela.querySelectorAll('thead th'), function (th) {
      return th.textContent.trim();
    });
    tabela.querySelectorAll('tbody tr').forEach(function (linha) {
      Array.prototype.forEach.call(linha.children, function (celula, i) {
        if (!celula.hasAttribute('colspan') && nomes[i]) {
          celula.setAttribute('data-label', nomes[i]);
        }
      });
    });
  });

  // Confirmação dentro do site (no lugar do confirm() do navegador) para
  // links com data-confirmar. Só segue o link se a pessoa confirmar.
  var modal = null;
  var linkPendente = null;

  function criarModal() {
    modal = document.createElement('dialog');
    modal.className = 'confirm-modal';
    modal.innerHTML =
      '<h3 class="confirm-titulo"></h3>' +
      '<p class="confirm-texto"></p>' +
      '<div class="confirm-acoes">' +
        '<button type="button" class="btn ghost" data-acao="cancelar">Cancelar</button>' +
        '<button type="button" class="btn danger" data-acao="confirmar"></button>' +
      '</div>';
    document.body.appendChild(modal);

    modal.addEventListener('click', function (evento) {
      var acao = evento.target.dataset.acao;
      if (acao === 'confirmar' && linkPendente) {
        window.location.href = linkPendente.href;
      }
      // Clique em "Cancelar" ou no fundo escuro fora do cartão fecha o modal.
      if (acao === 'cancelar' || evento.target === modal) {
        modal.close();
      }
    });
  }

  document.querySelectorAll('[data-confirmar]').forEach(function (link) {
    link.addEventListener('click', function (evento) {
      evento.preventDefault();
      if (!modal) { criarModal(); }
      linkPendente = link;
      modal.querySelector('.confirm-titulo').textContent = link.dataset.confirmarTitulo || 'Confirmar';
      modal.querySelector('.confirm-texto').textContent = link.dataset.confirmar;
      modal.querySelector('[data-acao="confirmar"]').textContent = link.dataset.confirmarBotao || 'Confirmar';
      modal.showModal();
      modal.querySelector('[data-acao="cancelar"]').focus();
    });
  });

  // Cursor "blurry dot" com movimento fluido: um círculo desfocado, mais forte
  // no centro, que segue o mouse como se estivesse preso a uma mola e se
  // estica na direção do movimento (como uma gota), voltando a ficar redondo
  // quando para. Só em aparelho com mouse (no celular
  // não existe cursor) e respeitando quem pediu menos animação no sistema.
  if (usaCursorProprio) {
    var raizCursor = document.documentElement;
    var borrao = document.createElement('div');
    borrao.className = 'cursor-borrao';
    borrao.setAttribute('aria-hidden', 'true');
    document.body.appendChild(borrao);
    raizCursor.classList.add('cursor-proprio');

    var mouseX = -100, mouseY = -100, borraoX = -100, borraoY = -100;
    var velX = 0, velY = 0, angulo = 0;
    var MOLA = 0.2, ATRITO = 0.68;   // mola mais forte = segue mais rápido; atrito menor = balança mais
    var SELETOR_CLICAVEL = 'a, button, label, select, summary, [role="button"], [role="option"]:not([aria-disabled="true"]), input[type="checkbox"], input[type="radio"], input[type="submit"]';

    document.addEventListener('mousemove', function (evento) {
      mouseX = evento.clientX;
      mouseY = evento.clientY;
      if (!raizCursor.classList.contains('cursor-visivel')) {
        borraoX = mouseX;
        borraoY = mouseY;
        velX = velY = 0;
        raizCursor.classList.add('cursor-visivel');
      }
      raizCursor.classList.toggle('cursor-sobre-link', !!evento.target.closest(SELETOR_CLICAVEL));
    });
    document.documentElement.addEventListener('mouseleave', function () {
      raizCursor.classList.remove('cursor-visivel');
    });
    document.addEventListener('mousedown', function () { raizCursor.classList.add('cursor-pressionado'); });
    document.addEventListener('mouseup', function () { raizCursor.classList.remove('cursor-pressionado'); });

    (function animar() {
      // Um <dialog> aberto fica numa camada acima de tudo; o cursor vai para
      // dentro dele para não sumir atrás da janela de confirmação.
      // (espera a animação de entrada acabar: durante ela o transform do
      // dialog deslocaria o cursor)
      var destino = document.querySelector('dialog[open]') || document.body;
      var animando = destino.getAnimations && destino.getAnimations().length > 0;
      if (borrao.parentNode !== destino && !animando) {
        destino.appendChild(borrao);
      }
      velX = (velX + (mouseX - borraoX) * MOLA) * ATRITO;
      velY = (velY + (mouseY - borraoY) * MOLA) * ATRITO;
      borraoX += velX;
      borraoY += velY;

      // Esticar: quanto mais rápido, mais alongado (com limite, para não
      // virar um risco). Parado, mantém o último ângulo para não tremer.
      var velocidade = Math.sqrt(velX * velX + velY * velY);
      if (velocidade > 0.6) { angulo = Math.atan2(velY, velX) * 180 / Math.PI; }
      var estica = Math.min(velocidade / 40, 0.45);
      borrao.style.transform =
        'translate3d(' + borraoX + 'px,' + borraoY + 'px,0)' +
        ' rotate(' + angulo + 'deg)' +
        ' scale(' + (1 + estica) + ',' + (1 - estica * 0.45) + ')';
      window.requestAnimationFrame(animar);
    })();
  }

  // Configurações → Aparência: aplica o tema na hora (sem esperar o servidor)
  // e envia o formulário para o PHP gravar a escolha no cookie.
  var formTema = document.getElementById('form-tema');
  if (formTema) {
    formTema.querySelectorAll('input[name="tema"]').forEach(function (opcao) {
      opcao.addEventListener('change', function () {
        var raiz = document.documentElement;
        if (opcao.value === 'sistema') {
          raiz.removeAttribute('data-theme');
        } else {
          raiz.setAttribute('data-theme', opcao.value === 'escuro' ? 'dark' : 'light');
        }
        formTema.submit();
      });
    });
  }
});
