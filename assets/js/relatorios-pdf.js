// Relatórios em PDF da tela de Relatórios (só admin).
// Busca os dados em relatorios.php?dados_pdf=1 (JSON) e monta o PDF aqui no
// navegador com a jsPDF + AutoTable (assets/js/vendor), sem nada instalado no
// servidor — funciona igual no XAMPP e na Vercel.
document.addEventListener('DOMContentLoaded', function () {
  var painel = document.querySelector('.pdf-painel');
  if (!painel) { return; }
  var aviso = painel.querySelector('.pdf-aviso');

  var COR = {
    roxo: [108, 99, 232],
    azul: [63, 127, 232],
    texto: [32, 36, 90],
    cinza: [102, 112, 133],
    lavanda: [240, 237, 255],
    linha: [228, 231, 245],
    alerta: [209, 73, 91],
    ok: [47, 158, 110]
  };
  var MARGEM = 14;

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

  function hojeIso() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  // ---------- conteúdo de cada relatório ----------
  var RELATORIOS = {
    abertos: function (dados) {
      var lista = dados.abertos;
      var atrasados = lista.filter(function (e) { return e.atrasado; }).length;
      var leitores = {};
      lista.forEach(function (e) { leitores[e.contato] = true; });
      return {
        titulo: 'Livros emprestados',
        subtitulo: 'Empréstimos em aberto na data de hoje (' + dados.hoje + ').',
        cartoes: [
          ['Em aberto', lista.length],
          ['No prazo', lista.length - atrasados],
          ['Atrasados', atrasados],
          ['Leitores com livros', Object.keys(leitores).length]
        ],
        cabecalho: ['Livro', 'Leitor', 'Turma', 'Emprestado em', 'Prazo', 'Situação'],
        linhas: lista.map(function (e) {
          return [e.livro, e.leitor + '\n' + e.contato, e.turma || '-', e.emprestimo, e.prazo,
            e.atrasado ? 'Atrasado (' + plural(e.dias, 'dia', 'dias') + ')' : 'No prazo'];
        }),
        destaque: function (linha) { return /^Atrasado/.test(linha[5]) ? 5 : -1; },
        larguras: { 0: 46, 1: 48, 3: 22, 4: 20 },
        vazio: 'Nenhum livro emprestado no momento.'
      };
    },

    atrasados: function (dados) {
      var lista = dados.abertos.filter(function (e) { return e.atrasado; })
        .sort(function (a, b) { return b.dias - a.dias; });
      var leitores = {};
      var soma = 0, maior = 0;
      lista.forEach(function (e) { leitores[e.contato] = true; soma += e.dias; maior = Math.max(maior, e.dias); });
      return {
        titulo: 'Livros atrasados',
        subtitulo: 'Empréstimos com o prazo vencido na data de hoje (' + dados.hoje + '), do maior atraso para o menor.',
        cartoes: [
          ['Livros atrasados', lista.length],
          ['Leitores com atraso', Object.keys(leitores).length],
          ['Maior atraso', lista.length ? plural(maior, 'dia', 'dias') : '-'],
          ['Atraso médio', lista.length ? plural(Math.round(soma / lista.length), 'dia', 'dias') : '-']
        ],
        cabecalho: ['Livro', 'Leitor', 'Turma', 'Prazo', 'Atraso', 'Renovações'],
        linhas: lista.map(function (e) {
          return [e.livro, e.leitor + '\n' + e.contato, e.turma || '-', e.prazo, plural(e.dias, 'dia', 'dias'), e.renovacoes + '/2'];
        }),
        destaque: function () { return 4; },
        larguras: { 0: 50, 1: 50, 3: 20, 4: 18, 5: 24 },
        vazio: 'Nenhum livro em atraso no momento.'
      };
    },

    livros: function (dados) {
      var lista = dados.por_livro;
      var total = 0, foraAgora = 0;
      lista.forEach(function (l) { total += l.total; foraAgora += l.abertos; });
      return {
        titulo: 'Empréstimos por livro',
        subtitulo: 'Quantas vezes cada título foi emprestado, do início até ' + dados.ate + '.',
        cartoes: [
          ['Empréstimos até ' + dados.ate, total],
          ['Títulos já emprestados', lista.length],
          ['Títulos nunca emprestados', Math.max(0, dados.total_livros - lista.length)],
          ['Com leitores agora', foraAgora]
        ],
        cabecalho: ['#', 'Livro', 'Autor', 'Código', 'Vezes emprestado', 'Com leitor agora'],
        linhas: lista.map(function (l, i) {
          return [String(i + 1), l.livro, l.autor || '-', l.codigo || '-', String(l.total), String(l.abertos)];
        }),
        destaque: function () { return -1; },
        larguras: { 0: 9, 1: 60, 3: 20, 4: 24, 5: 24 },
        alinharDireita: [4, 5],
        vazio: 'Nenhum empréstimo registrado até esta data.'
      };
    },

    leitores: function (dados) {
      var lista = dados.por_leitor;
      var total = 0, comLivros = 0, comAtraso = 0;
      lista.forEach(function (l) {
        total += l.total;
        if (l.total > 0) { comLivros++; }
        if (l.atrasados > 0) { comAtraso++; }
      });
      return {
        titulo: 'Empréstimos por leitor',
        subtitulo: 'Livros emprestados por cada usuário, do início até ' + dados.ate + ', e a situação de hoje.',
        cartoes: [
          ['Leitores cadastrados', lista.length],
          ['Já pegaram livros', comLivros],
          ['Empréstimos até ' + dados.ate, total],
          ['Com atraso agora', comAtraso]
        ],
        cabecalho: ['Leitor', 'Turma', 'Total emprestado', 'Com ele agora', 'Atrasados'],
        linhas: lista.map(function (l) {
          return [l.leitor + (l.bloqueado ? ' (bloqueado)' : '') + '\n' + l.contato, l.turma || '-',
            String(l.total), String(l.abertos), String(l.atrasados)];
        }),
        destaque: function (linha) { return linha[4] !== '0' ? 4 : -1; },
        larguras: { 0: 82, 2: 28, 3: 26, 4: 22 },
        alinharDireita: [2, 3, 4],
        vazio: 'Nenhum leitor cadastrado.'
      };
    }
  };

  var NOMES_ARQUIVO = {
    abertos: 'livros-emprestados', atrasados: 'livros-atrasados', livros: 'emprestimos-por-livro',
    leitores: 'emprestimos-por-leitor', completo: 'relatorio-completo'
  };

  // ---------- desenho ----------
  function faixaTopo(doc, dados) {
    var larg = doc.internal.pageSize.getWidth();
    doc.setFillColor.apply(doc, COR.roxo);
    doc.rect(0, 0, larg, 20, 'F');
    doc.setFillColor.apply(doc, COR.azul);
    doc.rect(larg * 0.62, 0, larg * 0.38, 20, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(15);
    doc.text('Bibliotech', MARGEM, 10.5);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(6.5);
    doc.text('E S T A N T E   D I G I T A L', MARGEM, 15);
    doc.setFontSize(8);
    doc.text(limpar('Gerado em ' + dados.gerado_em), larg - MARGEM, 11.5, { align: 'right' });
  }

  function rodape(doc, nome) {
    var larg = doc.internal.pageSize.getWidth();
    var alt = doc.internal.pageSize.getHeight();
    doc.setDrawColor.apply(doc, COR.linha);
    doc.line(MARGEM, alt - 12, larg - MARGEM, alt - 12);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7.5);
    doc.setTextColor.apply(doc, COR.cinza);
    doc.text(limpar('Bibliotech - ' + nome), MARGEM, alt - 7);
    doc.text('Página ' + doc.internal.getCurrentPageInfo().pageNumber + ' de {total}', larg - MARGEM, alt - 7, { align: 'right' });
  }

  function cartoes(doc, lista, y) {
    var larg = doc.internal.pageSize.getWidth() - MARGEM * 2;
    var espaco = 4;
    var w = (larg - espaco * (lista.length - 1)) / lista.length;
    lista.forEach(function (c, i) {
      var x = MARGEM + i * (w + espaco);
      doc.setFillColor.apply(doc, COR.lavanda);
      doc.roundedRect(x, y, w, 17, 2.5, 2.5, 'F');
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(14);
      doc.setTextColor.apply(doc, COR.roxo);
      doc.text(limpar(String(c[1])), x + 4, y + 8);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(7.5);
      doc.setTextColor.apply(doc, COR.cinza);
      doc.text(limpar(c[0]), x + 4, y + 13.5, { maxWidth: w - 6 });
    });
    return y + 17;
  }

  function secao(doc, dados, rel) {
    var larg = doc.internal.pageSize.getWidth();
    var y = 31;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.setTextColor.apply(doc, COR.texto);
    doc.text(limpar(rel.titulo), MARGEM, y);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor.apply(doc, COR.cinza);
    var sub = doc.splitTextToSize(limpar(rel.subtitulo), larg - MARGEM * 2);
    doc.text(sub, MARGEM, y + 6);
    y = cartoes(doc, rel.cartoes, y + 6 + sub.length * 4 + 2);

    if (!rel.linhas.length) {
      // sem tabela, a faixa e o rodapé não são desenhados por ela
      faixaTopo(doc, dados);
      rodape(doc, rel.titulo);
      doc.setFontSize(10);
      doc.setTextColor.apply(doc, COR.cinza);
      doc.text(limpar(rel.vazio), MARGEM, y + 12);
      return;
    }

    var estilosColuna = {};
    Object.keys(rel.larguras || {}).forEach(function (k) { estilosColuna[k] = { cellWidth: rel.larguras[k] }; });
    (rel.alinharDireita || []).forEach(function (k) {
      estilosColuna[k] = Object.assign(estilosColuna[k] || {}, { halign: 'right' });
    });

    doc.autoTable({
      startY: y + 6,
      head: [rel.cabecalho.map(limpar)],
      body: rel.linhas.map(function (l) { return l.map(limpar); }),
      margin: { top: 28, left: MARGEM, right: MARGEM, bottom: 18 },
      theme: 'plain',
      styles: { font: 'helvetica', fontSize: 8.5, cellPadding: 2.4, textColor: COR.texto, lineColor: COR.linha, valign: 'middle' },
      headStyles: { fillColor: COR.roxo, textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 8.5 },
      alternateRowStyles: { fillColor: [248, 247, 255] },
      bodyStyles: { lineWidth: { bottom: 0.2 } },
      columnStyles: estilosColuna,
      didParseCell: function (d) {
        if (d.section === 'body' && d.column.index === rel.destaque(d.row.raw)) {
          d.cell.styles.textColor = COR.alerta;
          d.cell.styles.fontStyle = 'bold';
        }
      },
      didDrawPage: function () {
        faixaTopo(doc, dados);
        rodape(doc, rel.titulo);
      }
    });
  }

  function gerarPdf(tipo, dados) {
    var doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    var tipos = tipo === 'completo' ? ['abertos', 'atrasados', 'livros', 'leitores'] : [tipo];
    tipos.forEach(function (t, i) {
      if (i > 0) { doc.addPage(); }
      // a faixa do topo e o rodapé são desenhados pela tabela em cada página
      secao(doc, dados, RELATORIOS[t](dados));
    });
    doc.putTotalPages('{total}');
    doc.setProperties({ title: 'Bibliotech - ' + (tipo === 'completo' ? 'Relatório completo' : RELATORIOS[tipo](dados).titulo) });
    doc.save(NOMES_ARQUIVO[tipo] + '-' + hojeIso() + '.pdf');
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
