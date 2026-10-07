// Relatórios em PDF da tela de Relatórios (só admin), em formato de documento:
// título, dados de emissão, seções numeradas com resumo em texto corrido e a
// relação dos itens em parágrafos (nada de tabela), fechando com assinatura.
// Busca os dados em relatorios.php?dados_pdf=1 (JSON) e monta o PDF aqui no
// navegador com a jsPDF (assets/js/vendor) — funciona igual no XAMPP e na Vercel.
document.addEventListener('DOMContentLoaded', function () {
  var painel = document.querySelector('.pdf-painel');
  if (!painel) { return; }
  var aviso = painel.querySelector('.pdf-aviso');

  var COR = {
    roxo: [108, 99, 232],
    texto: [32, 36, 90],
    corpo: [52, 58, 84],
    cinza: [102, 112, 133],
    linha: [215, 219, 236],
    alerta: [190, 52, 72],
    ok: [36, 128, 88]
  };

  // ---------- textos ----------
  // As fontes padrão do PDF só têm os caracteres do português "comum":
  // troca aspas curvas, travessões etc. por equivalentes simples.
  function limpar(texto) {
    return String(texto == null ? '' : texto)
      .replace(/[“”„]/g, '"')
      .replace(/[‘’‚]/g, "'")
      .replace(/[–—]/g, '-')
      .replace(/…/g, '...')
      .replace(/[^\x00-\xFF]/g, '');
  }

  function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

  // "A", "A e B", "A, B e C"
  function juntar(lista) {
    if (lista.length <= 1) { return lista.join(''); }
    return lista.slice(0, -1).join(', ') + ' e ' + lista[lista.length - 1];
  }

  function quem(item) {
    var extra = [item.turma ? 'turma ' + item.turma : '', item.contato].filter(Boolean).join(', ');
    return item.leitor + (extra ? ' (' + extra + ')' : '');
  }

  function hojeIso() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  // ---------- conteúdo de cada relatório ----------
  // Cada um devolve: título, resumo (parágrafos), título da relação, itens
  // ({ titulo, texto, situacao: { texto, cor } }) e um fechamento opcional.
  var RELATORIOS = {
    abertos: function (d) {
      var lista = d.abertos;
      var atrasados = lista.filter(function (e) { return e.atrasado; }).length;
      var leitores = {};
      lista.forEach(function (e) { leitores[e.contato] = true; });
      var nLeitores = Object.keys(leitores).length;
      var noPrazo = lista.length - atrasados;
      var resumo = lista.length
        ? ['Na data de referência (' + d.hoje + '), a biblioteca registra ' + plural(lista.length, 'empréstimo em aberto', 'empréstimos em aberto') +
            ', ' + (nLeitores === 1 ? 'todos com o mesmo leitor' : 'distribuídos entre ' + nLeitores + ' leitores') + '. ' +
            (atrasados === 0
              ? 'Todos estão dentro do prazo de devolução.'
              : noPrazo + ' ' + (noPrazo === 1 ? 'está' : 'estão') + ' dentro do prazo e ' +
                atrasados + ' ' + (atrasados === 1 ? 'encontra-se' : 'encontram-se') + ' em atraso.'),
           'A relação abaixo está organizada pela data prevista de devolução, da mais antiga para a mais recente.']
        : ['Na data de referência (' + d.hoje + '), não há livros emprestados: todo o acervo está disponível na biblioteca.'];
      return {
        titulo: 'Livros emprestados',
        tituloDoc: 'Relatório de livros emprestados',
        resumo: resumo,
        tituloLista: 'Relação dos empréstimos em aberto',
        itens: lista.map(function (e) {
          return {
            titulo: e.livro + (e.codigo ? ' (código ' + e.codigo + ')' : ''),
            texto: 'Emprestado a ' + quem(e) + ' em ' + e.emprestimo + ', com devolução prevista para ' + e.prazo + '.' +
              (e.renovacoes ? ' Empréstimo renovado ' + plural(e.renovacoes, 'vez', 'vezes') + '.' : ''),
            situacao: e.atrasado
              ? { texto: 'Situação: em atraso há ' + plural(e.dias, 'dia', 'dias') + '.', cor: COR.alerta }
              : { texto: 'Situação: dentro do prazo.', cor: COR.ok }
          };
        })
      };
    },

    atrasados: function (d) {
      var lista = d.abertos.filter(function (e) { return e.atrasado; })
        .sort(function (a, b) { return b.dias - a.dias; });
      var leitores = {};
      var soma = 0, maior = 0;
      lista.forEach(function (e) { leitores[e.contato] = true; soma += e.dias; maior = Math.max(maior, e.dias); });
      var nLeitores = Object.keys(leitores).length;
      var resumo = lista.length
        ? ['Em ' + d.hoje + ', ' + plural(lista.length, 'empréstimo está', 'empréstimos estão') + ' com o prazo de devolução vencido, ' +
            'envolvendo ' + plural(nLeitores, 'leitor', 'leitores') + '. O maior atraso é de ' + plural(maior, 'dia', 'dias') +
            (lista.length > 1 ? ' e o atraso médio é de ' + plural(Math.round(soma / lista.length), 'dia', 'dias') : '') + '.',
           'Recomenda-se entrar em contato com os leitores relacionados abaixo para solicitar a devolução dos livros. ' +
            'A relação está ordenada do maior atraso para o menor.']
        : ['Em ' + d.hoje + ', não há empréstimos em atraso: todos os livros emprestados estão dentro do prazo de devolução.'];
      return {
        titulo: 'Livros atrasados',
        tituloDoc: 'Relatório de livros atrasados',
        resumo: resumo,
        tituloLista: 'Relação dos empréstimos em atraso',
        itens: lista.map(function (e) {
          return {
            titulo: e.livro + (e.codigo ? ' (código ' + e.codigo + ')' : ''),
            texto: 'Está com ' + quem(e) + ' desde ' + e.emprestimo + '. O prazo de devolução era ' + e.prazo +
              '. Renovações utilizadas: ' + e.renovacoes + ' de 2.',
            situacao: { texto: 'Atraso: ' + plural(e.dias, 'dia', 'dias') + '.', cor: COR.alerta }
          };
        })
      };
    },

    livros: function (d) {
      var lista = d.por_livro;
      var total = 0, foraAgora = 0;
      lista.forEach(function (l) { total += l.total; foraAgora += l.abertos; });
      var nunca = Math.max(0, d.total_livros - lista.length);
      var resumo = lista.length
        ? ['Do início dos registros até ' + d.ate + ', foram realizados ' + plural(total, 'empréstimo', 'empréstimos') + ', abrangendo ' +
            lista.length + ' dos ' + plural(d.total_livros, 'título', 'títulos') + ' do acervo. ' +
            (nunca ? plural(nunca, 'título ainda não foi emprestado', 'títulos ainda não foram emprestados') + '. ' : 'Todos os títulos já foram emprestados ao menos uma vez. ') +
            'No momento, ' + plural(foraAgora, 'exemplar está', 'exemplares estão') + ' com leitores.',
           'O título mais procurado é "' + lista[0].livro + '"' + (lista[0].autor ? ', de ' + lista[0].autor : '') + ', com ' +
            plural(lista[0].total, 'empréstimo', 'empréstimos') + '. A relação abaixo segue do mais emprestado para o menos emprestado.']
        : ['Até ' + d.ate + ', nenhum empréstimo foi registrado no sistema.'];
      return {
        titulo: 'Empréstimos por livro',
        tituloDoc: 'Relatório de empréstimos por livro',
        resumo: resumo,
        tituloLista: 'Relação dos títulos emprestados',
        itens: lista.map(function (l) {
          return {
            titulo: l.livro + (l.autor ? ', de ' + l.autor : ''),
            texto: (l.codigo ? 'Código ' + l.codigo + '. ' : '') + 'Emprestado ' + plural(l.total, 'vez', 'vezes') + ' até ' + d.ate + '. ' +
              (l.abertos ? plural(l.abertos, 'exemplar está', 'exemplares estão') + ' com leitores no momento.' : 'Nenhum exemplar está emprestado no momento.')
          };
        })
      };
    },

    leitores: function (d) {
      var comLivros = d.por_leitor.filter(function (l) { return l.total > 0; });
      var semLivros = d.por_leitor.filter(function (l) { return l.total === 0; });
      var total = 0, comAtraso = 0;
      comLivros.forEach(function (l) { total += l.total; if (l.atrasados > 0) { comAtraso++; } });
      var resumo = comLivros.length
        ? ['Até ' + d.ate + ', ' + comLivros.length + ' dos ' + plural(d.por_leitor.length, 'leitor cadastrado', 'leitores cadastrados') +
            ' já ' + (comLivros.length === 1 ? 'realizou' : 'realizaram') + ' empréstimos, somando ' + plural(total, 'livro emprestado', 'livros emprestados') + '. ' +
            (comAtraso ? 'No momento, ' + plural(comAtraso, 'leitor possui', 'leitores possuem') + ' livros em atraso.' : 'No momento, nenhum leitor possui livros em atraso.'),
           'O leitor com mais empréstimos é ' + comLivros[0].leitor + ', com ' + plural(comLivros[0].total, 'livro', 'livros') +
            '. A relação abaixo segue do leitor com mais empréstimos para o com menos.']
        : ['Até ' + d.ate + ', nenhum dos ' + plural(d.por_leitor.length, 'leitor cadastrado', 'leitores cadastrados') + ' realizou empréstimos.'];
      return {
        titulo: 'Empréstimos por leitor',
        tituloDoc: 'Relatório de empréstimos por leitor',
        resumo: resumo,
        tituloLista: 'Relação dos leitores',
        itens: comLivros.map(function (l) {
          var agora = l.abertos
            ? 'Está com ' + plural(l.abertos, 'livro', 'livros') + ' no momento' + (l.atrasados ? ', sendo ' + l.atrasados + ' em atraso.' : ', todos dentro do prazo.')
            : 'Não está com nenhum livro no momento.';
          return {
            titulo: l.leitor + (l.bloqueado ? ' (cadastro bloqueado)' : ''),
            texto: [l.turma ? 'Turma ' + l.turma : '', l.contato].filter(Boolean).join(' - ') + '. ' +
              'Pegou ' + plural(l.total, 'livro emprestado', 'livros emprestados') + ' até ' + d.ate + '. ' + agora,
            situacao: l.atrasados ? { texto: 'Possui livros em atraso.', cor: COR.alerta } : null
          };
        }),
        fechamento: semLivros.length && comLivros.length
          ? 'Leitores cadastrados que ainda não realizaram empréstimos até ' + d.ate + ': ' +
            juntar(semLivros.map(function (l) { return l.leitor; })) + '.'
          : ''
      };
    }
  };

  var NOMES_ARQUIVO = {
    abertos: 'livros-emprestados', atrasados: 'livros-atrasados', livros: 'emprestimos-por-livro',
    leitores: 'emprestimos-por-leitor', completo: 'relatorio-geral'
  };

  // ---------- montagem do documento ----------
  function Documento(dados, nomeDoc) {
    var doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    var L = doc.internal.pageSize.getWidth();
    var A = doc.internal.pageSize.getHeight();
    var M = 22;                 // margem lateral
    var TOPO = 28, BASE = A - 22;
    var larg = L - M * 2;
    var y = TOPO;

    function cor(c) { doc.setTextColor(c[0], c[1], c[2]); }
    function fonte(estilo, tam) { doc.setFont('times', estilo); doc.setFontSize(tam); }

    // faixa fina no topo e rodapé com data de emissão e página, em toda página
    function moldura() {
      doc.setDrawColor(COR.roxo[0], COR.roxo[1], COR.roxo[2]);
      doc.setLineWidth(0.5);
      doc.line(M, 17, L - M, 17);
      doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); cor(COR.roxo);
      doc.text('Bibliotech', M, 14);
      var x = M + doc.getTextWidth('Bibliotech') + 2;
      doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5); cor(COR.cinza);
      doc.text('Estante Digital', x, 14);
      doc.text(limpar(nomeDoc), L - M, 14, { align: 'right' });

      doc.setDrawColor(COR.linha[0], COR.linha[1], COR.linha[2]);
      doc.setLineWidth(0.2);
      doc.line(M, A - 15, L - M, A - 15);
      doc.setFontSize(7.5); cor(COR.cinza);
      doc.text(limpar('Emitido em ' + dados.gerado_em), M, A - 10);
      doc.text('Página ' + doc.internal.getCurrentPageInfo().pageNumber + ' de {total}', L - M, A - 10, { align: 'right' });
    }

    function garantir(altura) {
      if (y + altura > BASE) {
        doc.addPage();
        moldura();
        y = TOPO + 4;
      }
    }

    function linhasDe(texto, tam, recuo, estilo) {
      fonte(estilo || 'normal', tam);
      return doc.splitTextToSize(limpar(texto), larg - (recuo || 0));
    }

    function paragrafo(texto, opcoes) {
      opcoes = opcoes || {};
      var tam = opcoes.tam || 11.5;
      var entre = tam * 0.47;   // altura da linha em mm
      var recuo = opcoes.recuo || 0;
      var linhas = linhasDe(texto, tam, recuo, opcoes.estilo);
      cor(opcoes.cor || COR.corpo);
      linhas.forEach(function (linha, i) {
        garantir(entre);
        fonte(opcoes.estilo || 'normal', tam);
        cor(opcoes.cor || COR.corpo);
        var ultima = i === linhas.length - 1;
        if (opcoes.justificar && !ultima) {
          doc.text(linha, M + recuo, y, { align: 'justify', maxWidth: larg - recuo });
        } else {
          doc.text(linha, M + recuo, y);
        }
        y += entre;
      });
      y += opcoes.depois == null ? 2.5 : opcoes.depois;
    }

    return {
      cabecalho: function (titulo) {
        moldura();
        y = 34;
        doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); cor(COR.roxo);
        doc.text('RELATÓRIO DA BIBLIOTECA', M, y, { charSpace: 0.6 });
        y += 9;
        linhasDe(titulo, 22, 0, 'bold').forEach(function (l) {
          fonte('bold', 22); cor(COR.texto);
          doc.text(l, M, y);
          y += 9;
        });
        y += 1;
        var meta = [
          ['Data de referência', dados.hoje],
          ['Totais acumulados até', dados.ate],
          ['Emitido em', dados.gerado_em]
        ];
        if (dados.emitido_por) { meta.push(['Emitido por', dados.emitido_por]); }
        meta.forEach(function (m) {
          doc.setFont('helvetica', 'bold'); doc.setFontSize(9); cor(COR.cinza);
          doc.text(limpar(m[0] + ':'), M, y);
          doc.setFont('helvetica', 'normal'); cor(COR.corpo);
          doc.text(limpar(m[1]), M + 42, y);
          y += 5;
        });
        y += 3;
        doc.setDrawColor(COR.linha[0], COR.linha[1], COR.linha[2]);
        doc.setLineWidth(0.3);
        doc.line(M, y, L - M, y);
        y += 10;
      },

      secao: function (numero, titulo, nivel) {
        var tam = nivel === 2 ? 12.5 : 15;
        garantir(tam * 0.5 + 16);   // não deixa o título sozinho no fim da página
        y += nivel === 2 ? 1 : 3;
        fonte('bold', tam);
        cor(COR.roxo);
        doc.text(numero, M, y);
        var x = M + doc.getTextWidth(numero) + 3;
        cor(COR.texto);
        doc.text(limpar(titulo), x, y);
        y += nivel === 2 ? 6.5 : 8;
      },

      paragrafo: function (texto) { paragrafo(texto, { justificar: true }); },

      item: function (numero, item) {
        var recuo = 9;
        var titulo = linhasDe(item.titulo, 11.5, recuo, 'bold');
        var altura = titulo.length * 5.3 + linhasDe(item.texto, 11, recuo).length * 5.2 + (item.situacao ? 5.2 : 0) + 4;
        garantir(Math.min(altura, BASE - TOPO));   // mantém o item inteiro na mesma página
        fonte('bold', 11.5);
        cor(COR.roxo);
        doc.text(numero + '.', M, y);
        titulo.forEach(function (l) {
          fonte('bold', 11.5); cor(COR.texto);
          doc.text(l, M + recuo, y);
          y += 5.3;
        });
        y += 0.3;
        paragrafo(item.texto, { tam: 11, recuo: recuo, justificar: true, depois: item.situacao ? 0.6 : 4 });
        if (item.situacao) {
          paragrafo(item.situacao.texto, { tam: 11, recuo: recuo, estilo: 'bold', cor: item.situacao.cor, depois: 4 });
        }
      },

      assinatura: function () {
        garantir(42);
        y = Math.min(BASE - 16, y + 26);
        var meio = L / 2;
        doc.setDrawColor(COR.corpo[0], COR.corpo[1], COR.corpo[2]);
        doc.setLineWidth(0.3);
        doc.line(meio - 40, y, meio + 40, y);
        fonte('normal', 10.5); cor(COR.corpo);
        doc.text('Responsável pela biblioteca', meio, y + 5.5, { align: 'center' });
        doc.setFont('helvetica', 'normal'); doc.setFontSize(8); cor(COR.cinza);
        doc.text('Bibliotech - Estante Digital', meio, y + 10.5, { align: 'center' });
      },

      salvar: function (arquivo) {
        doc.putTotalPages('{total}');
        doc.setProperties({ title: limpar('Bibliotech - ' + nomeDoc), author: limpar(dados.emitido_por || 'Bibliotech') });
        doc.save(arquivo);
      }
    };
  }

  function relacao(pdf, rel) {
    rel.itens.forEach(function (item, i) { pdf.item(String(i + 1), item); });
    if (rel.fechamento) { pdf.paragrafo(rel.fechamento); }
  }

  function gerarPdf(tipo, dados) {
    if (tipo === 'completo') {
      var geral = Documento(dados, 'Relatório geral');
      geral.cabecalho('Relatório geral da biblioteca');
      geral.paragrafo('Este documento reúne a situação dos empréstimos da biblioteca em ' + dados.hoje +
        ' e o histórico acumulado de empréstimos até ' + dados.ate + ', organizado em quatro partes: livros emprestados, ' +
        'livros atrasados, empréstimos por livro e empréstimos por leitor.');
      ['abertos', 'atrasados', 'livros', 'leitores'].forEach(function (t, i) {
        var rel = RELATORIOS[t](dados);
        var n = String(i + 1);
        geral.secao(n + '.', rel.titulo);
        rel.resumo.forEach(geral.paragrafo);
        if (rel.itens.length) {
          geral.secao(n + '.1', rel.tituloLista, 2);
          relacao(geral, rel);
        }
      });
      geral.assinatura();
      geral.salvar(NOMES_ARQUIVO.completo + '-' + hojeIso() + '.pdf');
      return;
    }

    var r = RELATORIOS[tipo](dados);
    var pdf = Documento(dados, r.titulo);
    pdf.cabecalho(r.tituloDoc);
    pdf.secao('1.', 'Resumo');
    r.resumo.forEach(pdf.paragrafo);
    if (r.itens.length) {
      pdf.secao('2.', r.tituloLista);
      relacao(pdf, r);
    }
    pdf.assinatura();
    pdf.salvar(NOMES_ARQUIVO[tipo] + '-' + hojeIso() + '.pdf');
  }

  // ---------- botões ----------
  function mostrarAviso(texto, erro) {
    aviso.textContent = texto;
    aviso.classList.toggle('erro', !!erro);
    aviso.hidden = false;
  }

  painel.querySelectorAll('[data-pdf]').forEach(function (botao) {
    botao.addEventListener('click', function () {
      if (!window.jspdf || !window.jspdf.jsPDF) {
        mostrarAviso('Não foi possível carregar o gerador de PDF. Recarregue a página e tente de novo.', true);
        return;
      }
      var textoOriginal = botao.textContent;
      botao.disabled = true;
      botao.textContent = 'Gerando...';
      aviso.hidden = true;

      fetch(painel.dataset.pdfUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (resposta) {
          if (!resposta.ok) { throw new Error('HTTP ' + resposta.status); }
          return resposta.json();
        })
        .then(function (dados) {
          gerarPdf(botao.dataset.pdf, dados);
          mostrarAviso('PDF gerado. Confira a pasta de downloads.', false);
        })
        .catch(function () {
          mostrarAviso('Não foi possível gerar o PDF agora. Verifique a conexão e tente de novo.', true);
        })
        .then(function () {
          botao.disabled = false;
          botao.textContent = textoOriginal;
        });
    });
  });
});
