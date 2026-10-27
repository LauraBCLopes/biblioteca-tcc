// Relatórios em PDF da tela de Relatórios (só admin), no modelo de documento
// oficial da escola (como o boletim): preto e branco, título centralizado,
// quadro de identificação, tabela com cabeçalho cinza e bordas finas, quadro
// de resumo, observações em texto, legenda das siglas e "cidade, data".
// Busca os dados em relatorios.php?dados_pdf=1 (JSON) e monta o PDF aqui no
// navegador com a jsPDF + AutoTable (assets/js/vendor) — funciona igual no
// XAMPP e na Vercel. Antes de baixar, o PDF é mostrado numa pré-visualização
// (<dialog id="pdf-previa"> em relatorios.php).
document.addEventListener('DOMContentLoaded', function () {
  var painel = document.querySelector('.pdf-painel');
  if (!painel) { return; }
  var aviso = painel.querySelector('.pdf-aviso');

  var CIDADE = 'Votuporanga';   // aparece na linha "Cidade, dd de mês de aaaa."
  var PRETO = [0, 0, 0];
  var CINZA_CABECALHO = [217, 217, 217];
  var VERMELHO = [200, 30, 30];
  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
    'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

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

  function juntar(lista) {
    if (lista.length <= 1) { return lista.join(''); }
    return lista.slice(0, -1).join(', ') + ' e ' + lista[lista.length - 1];
  }

  // "06/10/2026" -> "06 de outubro de 2026"
  function porExtenso(dataBr) {
    var p = String(dataBr).split('/');
    return p.length === 3 ? p[0] + ' de ' + MESES[parseInt(p[1], 10) - 1] + ' de ' + p[2] : dataBr;
  }

  function hojeIso() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  // ---------- conteúdo de cada relatório ----------
  // Cada um devolve: título, resumo (pares rótulo/valor), observações (texto),
  // colunas e linhas da tabela, estilos por coluna, destaque e legenda.
  var RELATORIOS = {
    abertos: function (d) {
      var lista = d.abertos;
      var atrasados = lista.filter(function (e) { return e.atrasado; }).length;
      var leitores = {};
      lista.forEach(function (e) { leitores[e.contato] = true; });
      var nLeitores = Object.keys(leitores).length;
      return {
        titulo: 'Relatório de livros emprestados',
        nome: 'Livros emprestados',
        resumo: [['Empréstimos em aberto', lista.length], ['Dentro do prazo', lista.length - atrasados],
                 ['Em atraso', atrasados], ['Leitores com livros', nLeitores]],
        observacoes: lista.length
          ? 'Situação dos empréstimos em ' + d.hoje + ': ' + plural(lista.length, 'livro emprestado', 'livros emprestados') +
            ' para ' + plural(nLeitores, 'leitor', 'leitores') + ', sendo ' + plural(atrasados, 'em atraso', 'em atraso') +
            '. Relação ordenada pela data prevista de devolução.'
          : 'Não há livros emprestados em ' + d.hoje + '.',
        colunas: ['Nº', 'Livro', 'Código', 'Leitor', 'Turma', 'Empréstimo', 'Prazo', 'Renov.', 'Situação', 'Dias de atraso'],
        linhas: lista.map(function (e, i) {
          return [i + 1, e.livro, e.codigo || '-', e.leitor, e.turma || '-', e.emprestimo, e.prazo,
            e.renovacoes + '/2', e.atrasado ? 'AT' : 'NP', e.atrasado ? e.dias : '-'];
        }),
        estilos: { 0: { cellWidth: 8 }, 1: { halign: 'left' }, 3: { halign: 'left' }, 5: { cellWidth: 20 }, 6: { cellWidth: 19 } },
        destacar: function (linha, col) { return linha[8] === 'AT' && (col === 8 || col === 9); },
        legenda: ['NP - No prazo', 'AT - Atrasado', 'Renov. - Renovações usadas / permitidas']
      };
    },

    atrasados: function (d) {
      var lista = d.abertos.filter(function (e) { return e.atrasado; })
        .sort(function (a, b) { return b.dias - a.dias; });
      var leitores = {};
      var soma = 0, maior = 0;
      lista.forEach(function (e) { leitores[e.contato] = true; soma += e.dias; maior = Math.max(maior, e.dias); });
      var nLeitores = Object.keys(leitores).length;
      return {
        titulo: 'Relatório de livros atrasados',
        nome: 'Livros atrasados',
        resumo: [['Livros em atraso', lista.length], ['Leitores com atraso', nLeitores],
                 ['Maior atraso (dias)', lista.length ? maior : '-'], ['Atraso médio (dias)', lista.length ? Math.round(soma / lista.length) : '-']],
        observacoes: lista.length
          ? 'Em ' + d.hoje + ', ' + plural(lista.length, 'empréstimo está', 'empréstimos estão') + ' com o prazo vencido. ' +
            'Recomenda-se entrar em contato com os leitores relacionados para solicitar a devolução. Relação ordenada do maior atraso para o menor.'
          : 'Não há empréstimos em atraso em ' + d.hoje + '.',
        colunas: ['Nº', 'Livro', 'Código', 'Leitor', 'Turma', 'Contato', 'Prazo', 'Dias de atraso', 'Renov.'],
        linhas: lista.map(function (e, i) {
          return [i + 1, e.livro, e.codigo || '-', e.leitor, e.turma || '-', e.contato, e.prazo, e.dias, e.renovacoes + '/2'];
        }),
        estilos: { 0: { cellWidth: 8 }, 1: { halign: 'left' }, 3: { halign: 'left' }, 5: { halign: 'left' }, 6: { cellWidth: 18 } },
        destacar: function (linha, col) { return col === 7; },
        legenda: ['Renov. - Renovações usadas / permitidas']
      };
    },

    livros: function (d) {
      var lista = d.por_livro;
      var total = 0, foraAgora = 0;
      lista.forEach(function (l) { total += l.total; foraAgora += l.abertos; });
      var nunca = Math.max(0, d.total_livros - lista.length);
      return {
        titulo: 'Relatório de empréstimos por livro',
        nome: 'Empréstimos por livro',
        resumo: [['Empréstimos até ' + d.ate, total], ['Títulos já emprestados', lista.length],
                 ['Títulos nunca emprestados', nunca], ['Exemplares com leitores', foraAgora]],
        observacoes: lista.length
          ? 'Total acumulado do início dos registros até ' + d.ate + '. Título mais procurado: "' + lista[0].livro + '"' +
            (lista[0].autor ? ', de ' + lista[0].autor : '') + ', com ' + plural(lista[0].total, 'empréstimo', 'empréstimos') +
            '. Relação ordenada do mais emprestado para o menos emprestado.'
          : 'Nenhum empréstimo registrado até ' + d.ate + '.',
        colunas: ['Nº', 'Livro', 'Autor', 'Código', 'Total emprestado', 'Com leitor agora'],
        linhas: lista.map(function (l, i) {
          return [i + 1, l.livro, l.autor || '-', l.codigo || '-', l.total, l.abertos];
        }),
        estilos: { 0: { cellWidth: 8 }, 1: { halign: 'left' }, 2: { halign: 'left' }, 4: { cellWidth: 24 }, 5: { cellWidth: 24 } },
        destacar: function () { return false; },
        legenda: []
      };
    },

    leitores: function (d) {
      var lista = d.por_leitor;
      var total = 0, comLivros = 0, comAtraso = 0;
      lista.forEach(function (l) {
        total += l.total;
        if (l.total > 0) { comLivros++; }
        if (l.atrasados > 0) { comAtraso++; }
      });
      var semLivros = lista.filter(function (l) { return l.total === 0; }).map(function (l) { return l.leitor; });
      return {
        titulo: 'Relatório de empréstimos por leitor',
        nome: 'Empréstimos por leitor',
        resumo: [['Leitores cadastrados', lista.length], ['Já pegaram livros', comLivros],
                 ['Empréstimos até ' + d.ate, total], ['Com atraso agora', comAtraso]],
        observacoes: 'Total de livros emprestados por leitor do início dos registros até ' + d.ate + '; as colunas "Em aberto" e "Atrasados" mostram a situação de ' + d.hoje + '.' +
          (semLivros.length && semLivros.length < lista.length
            ? ' Ainda não realizaram empréstimos: ' + juntar(semLivros) + '.' : ''),
        colunas: ['Nº', 'Leitor', 'Turma', 'E-mail', 'Total emprestado', 'Em aberto', 'Atrasados', 'Cadastro'],
        linhas: lista.map(function (l, i) {
          return [i + 1, l.leitor, l.turma || '-', l.contato, l.total, l.abertos, l.atrasados, l.bloqueado ? 'B' : 'A'];
        }),
        estilos: { 0: { cellWidth: 8 }, 1: { halign: 'left' }, 3: { halign: 'left' }, 4: { cellWidth: 20 }, 5: { cellWidth: 16 }, 6: { cellWidth: 18 }, 7: { cellWidth: 17 } },
        destacar: function (linha, col) { return (col === 6 && linha[6] > 0) || (col === 7 && linha[7] === 'B'); },
        legenda: ['A - Ativo', 'B - Bloqueado']
      };
    }
  };

  var NOMES_ARQUIVO = {
    abertos: 'livros-emprestados', atrasados: 'livros-atrasados', livros: 'emprestimos-por-livro',
    leitores: 'emprestimos-por-leitor', completo: 'relatorio-geral'
  };

  // ---------- montagem do documento ----------
  var M = 12;   // margem lateral, como no boletim

  function base() {
    return {
      theme: 'grid',
      margin: { left: M, right: M, top: 14, bottom: 16 },
      styles: { font: 'helvetica', fontSize: 8, textColor: PRETO, lineColor: PRETO, lineWidth: 0.2, cellPadding: 1.3, valign: 'middle' },
      headStyles: { fillColor: CINZA_CABECALHO, textColor: PRETO, fontStyle: 'bold', halign: 'center', lineColor: PRETO, lineWidth: 0.2 },
      bodyStyles: { fillColor: [255, 255, 255] }
    };
  }

  function mesclar(a, b) {
    var r = {};
    Object.keys(a).forEach(function (k) { r[k] = a[k]; });
    Object.keys(b).forEach(function (k) { r[k] = b[k]; });
    return r;
  }

  function tabela(doc, opcoes) {
    doc.autoTable(mesclar(base(), opcoes));
    return doc.lastAutoTable.finalY;
  }

  function tituloCentral(doc, texto, y, tam) {
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(tam || 9);
    doc.setTextColor(0, 0, 0);
    doc.text(limpar(texto), doc.internal.pageSize.getWidth() / 2, y, { align: 'center' });
  }

  // quadro de identificação: linhas de "Rótulo: valor" com bordas, como o topo do boletim
  function identificacao(doc, linhas, y) {
    var corpo = linhas.map(function (l) {
      return l.map(function (c) { return { content: limpar(c[0] + ': ') + limpar(c[1]), rotulo: limpar(c[0] + ': ') }; });
    });
    return tabela(doc, {
      startY: y,
      body: corpo.map(function (l) { return l.map(function (c) { return c.content; }); }),
      columnStyles: { 0: { cellWidth: 118 } },
      styles: mesclar(base().styles, { fontSize: 8.5, cellPadding: 1.4 }),
      // rótulo em negrito e valor normal na mesma célula
      didParseCell: function (h) { h.cell.text = ['']; },
      didDrawCell: function (h) {
        var c = corpo[h.row.index][h.column.index];
        var x = h.cell.x + 1.4, yy = h.cell.y + h.cell.height / 2 + 1.1;
        doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); doc.setTextColor(0, 0, 0);
        doc.text(c.rotulo, x, yy);
        var w = doc.getTextWidth(c.rotulo);
        doc.setFont('helvetica', 'normal');
        doc.text(c.content.slice(c.rotulo.length), x + w, yy, { maxWidth: h.cell.width - w - 3 });
      }
    });
  }

  function cabecalho(doc, dados, rel) {
    tituloCentral(doc, rel.titulo.toUpperCase(), 14, 12);
    tituloCentral(doc, 'Bibliotech - Estante Digital  |  Sistema de gestão da biblioteca', 19, 8.5);
    return identificacao(doc, [
      [['Relatório', rel.nome], ['Nº de registros', String(rel.linhas.length)]],
      [['Data de referência', dados.hoje], ['Totais acumulados até', dados.ate]],
      [['Emitido por', dados.emitido_por || '-'], ['Emitido em', dados.gerado_em]]
    ], 22);
  }

  function rodapes(doc) {
    var total = doc.internal.getNumberOfPages();
    var L = doc.internal.pageSize.getWidth(), A = doc.internal.pageSize.getHeight();
    for (var p = 1; p <= total; p++) {
      doc.setPage(p);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(90, 90, 90);
      doc.text('Bibliotech - Estante Digital', M, A - 8);
      doc.text('Página ' + p + ' de ' + total, L - M, A - 8, { align: 'right' });
    }
  }

  function garantir(doc, y, altura) {
    if (y + altura > doc.internal.pageSize.getHeight() - 18) {
      doc.addPage();
      return 16;
    }
    return y;
  }

  function formulario(doc, dados, rel) {
    var L = doc.internal.pageSize.getWidth();
    var y = cabecalho(doc, dados, rel) + 4;

    // tabela principal
    if (rel.linhas.length) {
      y = tabela(doc, {
        startY: y,
        head: [rel.colunas.map(limpar)],
        body: rel.linhas.map(function (l) { return l.map(function (c) { return limpar(c); }); }),
        styles: mesclar(base().styles, { halign: 'center' }),
        columnStyles: rel.estilos,
        didParseCell: function (h) {
          if (h.section === 'body' && rel.destacar(rel.linhas[h.row.index], h.column.index)) {
            h.cell.styles.textColor = VERMELHO;
            h.cell.styles.fontStyle = 'bold';
          }
        }
      });
    } else {
      y = tabela(doc, {
        startY: y,
        head: [rel.colunas.map(limpar)],
        body: [[{ content: 'Nenhum registro na data de referência.', colSpan: rel.colunas.length, styles: { halign: 'center', fontStyle: 'italic' } }]],
        styles: mesclar(base().styles, { halign: 'center' })
      });
    }

    // resumo, no estilo do quadro "Assiduidade" do boletim
    y = garantir(doc, y + 5, 22);
    tituloCentral(doc, 'Resumo', y + 3);
    var r = rel.resumo.map(function (p) { return [limpar(p[0] + ':'), limpar(p[1])]; });
    y = tabela(doc, {
      startY: y + 5,
      body: [[r[0][0], r[0][1], r[1][0], r[1][1]], [r[2][0], r[2][1], r[3][0], r[3][1]]],
      styles: mesclar(base().styles, { fontSize: 8.5 }),
      columnStyles: {
        0: { fontStyle: 'bold', halign: 'right', cellWidth: 60 }, 1: { halign: 'center', cellWidth: 33 },
        2: { fontStyle: 'bold', halign: 'right', cellWidth: 60 }, 3: { halign: 'center' }
      }
    });

    // observações, como "Outras Observações" do boletim
    y = garantir(doc, y + 5, 20);
    y = tabela(doc, {
      startY: y + 3,
      body: [[{ content: 'Observações', styles: { fontStyle: 'bold', halign: 'center', cellWidth: 45 } }, limpar(rel.observacoes)]],
      styles: mesclar(base().styles, { fontSize: 8.5, cellPadding: 3, minCellHeight: 16 })
    });

    // legenda das siglas, como a faixa de baixo do boletim
    if (rel.legenda.length) {
      y = garantir(doc, y + 5, 12);
      y = tabela(doc, {
        startY: y + 3,
        body: [rel.legenda.map(limpar)],
        styles: mesclar(base().styles, { fontStyle: 'bold', halign: 'center', fontSize: 8, cellPadding: 2 })
      });
    }

    // local e data + assinatura
    y = garantir(doc, y + 8, 30);
    tituloCentral(doc, CIDADE + ', ' + porExtenso(dados.hoje) + '.', y + 3, 9);
    var ya = y + 20;
    doc.setDrawColor(0, 0, 0); doc.setLineWidth(0.25);
    doc.line(L / 2 - 38, ya, L / 2 + 38, ya);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5);
    doc.text('Responsável pela biblioteca', L / 2, ya + 4.5, { align: 'center' });
  }

  // monta o documento e devolve { doc, arquivo, titulo }; quem chama decide se mostra ou baixa
  function gerarPdf(tipo, dados) {
    var doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    var tipos = tipo === 'completo' ? ['abertos', 'atrasados', 'livros', 'leitores'] : [tipo];
    tipos.forEach(function (t, i) {
      if (i > 0) { doc.addPage(); }
      formulario(doc, dados, RELATORIOS[t](dados));
    });
    rodapes(doc);
    var titulo = tipo === 'completo' ? 'Relatório geral' : RELATORIOS[tipo](dados).titulo;
    doc.setProperties({
      title: limpar('Bibliotech - ' + titulo),
      author: limpar(dados.emitido_por || 'Bibliotech')
    });
    return { doc: doc, arquivo: NOMES_ARQUIVO[tipo] + '-' + hojeIso() + '.pdf', titulo: titulo };
  }

  // ---------- pré-visualização ----------
  // O PDF aparece numa janela dentro da página; só vai para os downloads se
  // o admin clicar em "Baixar PDF". As páginas são desenhadas em <canvas>
  // pela pdf.js (assets/js/vendor), e não num <iframe> com o leitor de PDF do
  // navegador: assim o cursor personalizado continua valendo em cima delas e
  // a prévia funciona também no celular.
  var previa = document.getElementById('pdf-previa');
  var previaTitulo = previa.querySelector('.pdf-previa-titulo');
  var previaPaginas = previa.querySelector('.pdf-previa-paginas');
  var previaAbrir = previa.querySelector('[data-pdf-abrir]');
  var atual = null;   // { doc, arquivo, titulo, url }

  if (window.pdfjsLib) {
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/js/vendor/pdf.worker.min.js';
  }

  function desenharPaginas(pdf) {
    previaPaginas.innerHTML = '<p class="pdf-previa-carregando">Carregando pré-visualização...</p>';
    if (!window.pdfjsLib) {
      previaPaginas.innerHTML = '<p class="pdf-previa-carregando">Não foi possível mostrar a prévia aqui. Use "Abrir em nova aba" ou "Baixar PDF".</p>';
      return;
    }
    window.pdfjsLib.getDocument({ data: pdf.doc.output('arraybuffer') }).promise
      .then(function (arquivo) {
        if (atual !== pdf) { return; }   // a janela foi fechada ou trocada no meio
        previaPaginas.innerHTML = '';
        var largura = Math.min(previaPaginas.clientWidth - 32, 820);
        var nitidez = window.devicePixelRatio || 1;
        var fila = Promise.resolve();
        for (var n = 1; n <= arquivo.numPages; n++) {
          fila = fila.then(desenharPagina.bind(null, arquivo, n, pdf, largura, nitidez));
        }
        return fila;
      })
      .catch(function () {
        if (atual === pdf) {
          previaPaginas.innerHTML = '<p class="pdf-previa-carregando">Não foi possível mostrar a prévia aqui. Use "Abrir em nova aba" ou "Baixar PDF".</p>';
        }
      });
  }

  function desenharPagina(arquivo, n, pdf, largura, nitidez) {
    return arquivo.getPage(n).then(function (pagina) {
      if (atual !== pdf) { return; }
      var escala = largura / pagina.getViewport({ scale: 1 }).width;
      var vista = pagina.getViewport({ scale: escala * nitidez });
      var canvas = document.createElement('canvas');
      canvas.width = vista.width;
      canvas.height = vista.height;
      canvas.style.width = largura + 'px';
      canvas.setAttribute('aria-label', 'Página ' + n + ' de ' + arquivo.numPages);
      previaPaginas.appendChild(canvas);
      return pagina.render({ canvasContext: canvas.getContext('2d'), viewport: vista }).promise;
    });
  }

  function abrirPrevia(pdf) {
    fecharPrevia();
    pdf.url = URL.createObjectURL(pdf.doc.output('blob'));
    atual = pdf;
    previaTitulo.textContent = pdf.titulo;
    previaAbrir.href = pdf.url;
    previa.showModal();
    previaPaginas.scrollTop = 0;
    desenharPaginas(pdf);
  }

  function fecharPrevia() {
    if (previa.open) { previa.close(); }
  }

  previa.addEventListener('close', function () {
    previaPaginas.innerHTML = '';
    if (atual) { URL.revokeObjectURL(atual.url); atual = null; }
  });
  previa.querySelector('[data-pdf-fechar]').addEventListener('click', fecharPrevia);
  previa.querySelector('[data-pdf-baixar]').addEventListener('click', function () {
    if (!atual) { return; }
    atual.doc.save(atual.arquivo);
    fecharPrevia();
    mostrarAviso('PDF baixado. Confira a pasta de downloads.', false);
  });
  // clique no fundo escuro fecha
  previa.addEventListener('click', function (ev) {
    if (ev.target === previa) { fecharPrevia(); }
  });

  // ---------- botões ----------
  function mostrarAviso(texto, erro) {
    aviso.textContent = texto;
    aviso.classList.toggle('erro', !!erro);
    aviso.hidden = false;
  }

  // botões do painel e o "Baixar relatório em PDF" do topo da página
  document.querySelectorAll('[data-pdf]').forEach(function (botao) {
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
          abrirPrevia(gerarPdf(botao.dataset.pdf, dados));
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
