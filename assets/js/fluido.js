// Rastro de fluido atrás do mouse ("fluid motion cursor"): uma simulação de
// tinta em WebGL que o mouse empurra e faz girar, nas cores do site.
// Baseado na WebGL Fluid Simulation de Pavel Dobryakov (licença MIT).
// Só roda em computador com mouse, com WebGL, e para quem não pediu menos
// animação no sistema. Se algo não for suportado, o site segue normal.
(function () {
  if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches ||
      window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  // Ajustes do efeito (valores menores que o original para ficar discreto
  // num site de fundo claro).
  var CONFIG = {
    SIM_RESOLUCAO: 128,     // grade da física (mais = mais detalhe, mais pesado)
    TINTA_RESOLUCAO: 512,   // resolução da "tinta" desenhada
    TINTA_SOME: 1.8,        // quão rápido a cor some (maior = some antes)
    VELOCIDADE_SOME: 0.9,   // quão rápido o movimento para
    PRESSAO: 0.8,
    ITERACOES_PRESSAO: 20,
    GIRO: 18,               // redemoinhos (vorticidade)
    RAIO: 0.12,             // tamanho de cada "pincelada"
    FORCA: 3500,            // quanto o mouse empurra o fluido
    INTENSIDADE: 0.12,      // força da cor de cada pincelada
    PARADO_MS: 4000         // sem mexer o mouse por esse tempo, a simulação pausa
  };

  // Roxo, azul e magenta do site; a cor vai passando de uma para outra.
  var CORES = [
    [0.42, 0.39, 0.91],
    [0.25, 0.50, 0.91],
    [0.72, 0.30, 0.95]
  ];

  var canvas = document.createElement('canvas');
  canvas.className = 'cursor-fluido';
  canvas.setAttribute('aria-hidden', 'true');

  var params = { alpha: true, depth: false, stencil: false, antialias: false, premultipliedAlpha: true, preserveDrawingBuffer: false };
  var gl = canvas.getContext('webgl2', params);
  var webgl2 = !!gl;
  if (!gl) { gl = canvas.getContext('webgl', params) || canvas.getContext('experimental-webgl', params); }
  if (!gl) { return; }

  var tipoHalf, formatoInterno;
  if (webgl2) {
    if (!gl.getExtension('EXT_color_buffer_float')) { return; }
    tipoHalf = gl.HALF_FLOAT;
    formatoInterno = gl.RGBA16F;
  } else {
    var extHalf = gl.getExtension('OES_texture_half_float');
    if (!extHalf || !gl.getExtension('OES_texture_half_float_linear')) { return; }
    tipoHalf = extHalf.HALF_FLOAT_OES;
    formatoInterno = gl.RGBA;
  }

  // ---------- Shaders ----------
  var VERTEX = [
    'precision highp float;',
    'attribute vec2 aPosition;',
    'varying vec2 vUv, vL, vR, vT, vB;',
    'uniform vec2 texelSize;',
    'void main () {',
    '  vUv = aPosition * 0.5 + 0.5;',
    '  vL = vUv - vec2(texelSize.x, 0.0);',
    '  vR = vUv + vec2(texelSize.x, 0.0);',
    '  vT = vUv + vec2(0.0, texelSize.y);',
    '  vB = vUv - vec2(0.0, texelSize.y);',
    '  gl_Position = vec4(aPosition, 0.0, 1.0);',
    '}'
  ].join('\n');

  var CABECALHO = 'precision highp float; precision highp sampler2D; varying vec2 vUv, vL, vR, vT, vB;\n';

  var FRAG = {
    limpar: 'uniform sampler2D uTexture; uniform float value;' +
      'void main () { gl_FragColor = value * texture2D(uTexture, vUv); }',

    // fundo transparente: a opacidade de cada ponto é a cor mais forte dele.
    // Onde a tinta acumula demais, divide pela cor mais forte em vez de
    // cortar em 1 — assim mantém o tom roxo/azul em vez de estourar em branco.
    mostrar: 'uniform sampler2D uTexture;' +
      'void main () { vec3 c = max(texture2D(uTexture, vUv).rgb, 0.0);' +
      '  float a = max(c.r, max(c.g, c.b)); if (a > 1.0) { c /= a; a = 1.0; }' +
      '  gl_FragColor = vec4(c, a); }',

    pincelada: 'uniform sampler2D uTarget; uniform float aspectRatio; uniform vec3 color; uniform vec2 point; uniform float radius;' +
      'void main () { vec2 p = vUv - point; p.x *= aspectRatio;' +
      '  vec3 splat = exp(-dot(p, p) / radius) * color;' +
      '  gl_FragColor = vec4(texture2D(uTarget, vUv).xyz + splat, 1.0); }',

    adveccao: 'uniform sampler2D uVelocity; uniform sampler2D uSource; uniform vec2 texelSize; uniform float dt; uniform float dissipation;' +
      'void main () { vec2 coord = vUv - dt * texture2D(uVelocity, vUv).xy * texelSize;' +
      '  gl_FragColor = texture2D(uSource, coord) / (1.0 + dissipation * dt); }',

    divergencia: 'uniform sampler2D uVelocity;' +
      'void main () { float L = texture2D(uVelocity, vL).x; float R = texture2D(uVelocity, vR).x;' +
      '  float T = texture2D(uVelocity, vT).y; float B = texture2D(uVelocity, vB).y;' +
      '  vec2 C = texture2D(uVelocity, vUv).xy;' +
      '  if (vL.x < 0.0) { L = -C.x; } if (vR.x > 1.0) { R = -C.x; }' +
      '  if (vT.y > 1.0) { T = -C.y; } if (vB.y < 0.0) { B = -C.y; }' +
      '  gl_FragColor = vec4(0.5 * (R - L + T - B), 0.0, 0.0, 1.0); }',

    rotacional: 'uniform sampler2D uVelocity;' +
      'void main () { float L = texture2D(uVelocity, vL).y; float R = texture2D(uVelocity, vR).y;' +
      '  float T = texture2D(uVelocity, vT).x; float B = texture2D(uVelocity, vB).x;' +
      '  gl_FragColor = vec4(0.5 * (R - L - T + B), 0.0, 0.0, 1.0); }',

    vorticidade: 'uniform sampler2D uVelocity; uniform sampler2D uCurl; uniform float curl; uniform float dt;' +
      'void main () { float L = texture2D(uCurl, vL).x; float R = texture2D(uCurl, vR).x;' +
      '  float T = texture2D(uCurl, vT).x; float B = texture2D(uCurl, vB).x; float C = texture2D(uCurl, vUv).x;' +
      '  vec2 force = 0.5 * vec2(abs(T) - abs(B), abs(R) - abs(L));' +
      '  force /= length(force) + 0.0001; force *= curl * C; force.y *= -1.0;' +
      '  vec2 vel = texture2D(uVelocity, vUv).xy + force * dt;' +
      '  gl_FragColor = vec4(clamp(vel, -1000.0, 1000.0), 0.0, 1.0); }',

    pressao: 'uniform sampler2D uPressure; uniform sampler2D uDivergence;' +
      'void main () { float L = texture2D(uPressure, vL).x; float R = texture2D(uPressure, vR).x;' +
      '  float T = texture2D(uPressure, vT).x; float B = texture2D(uPressure, vB).x;' +
      '  float div = texture2D(uDivergence, vUv).x;' +
      '  gl_FragColor = vec4((L + R + B + T - div) * 0.25, 0.0, 0.0, 1.0); }',

    gradiente: 'uniform sampler2D uPressure; uniform sampler2D uVelocity;' +
      'void main () { float L = texture2D(uPressure, vL).x; float R = texture2D(uPressure, vR).x;' +
      '  float T = texture2D(uPressure, vT).x; float B = texture2D(uPressure, vB).x;' +
      '  vec2 vel = texture2D(uVelocity, vUv).xy - vec2(R - L, T - B);' +
      '  gl_FragColor = vec4(vel, 0.0, 1.0); }'
  };

  function compilar(tipo, fonte) {
    var shader = gl.createShader(tipo);
    gl.shaderSource(shader, fonte);
    gl.compileShader(shader);
    return gl.getShaderParameter(shader, gl.COMPILE_STATUS) ? shader : null;
  }

  var vertexShader = compilar(gl.VERTEX_SHADER, VERTEX);
  if (!vertexShader) { return; }

  function programa(fonteFrag) {
    var frag = compilar(gl.FRAGMENT_SHADER, CABECALHO + fonteFrag);
    if (!frag) { return null; }
    var prog = gl.createProgram();
    gl.attachShader(prog, vertexShader);
    gl.attachShader(prog, frag);
    gl.bindAttribLocation(prog, 0, 'aPosition');
    gl.linkProgram(prog);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) { return null; }
    var uniforms = {};
    var total = gl.getProgramParameter(prog, gl.ACTIVE_UNIFORMS);
    for (var i = 0; i < total; i++) {
      var nome = gl.getActiveUniform(prog, i).name;
      uniforms[nome] = gl.getUniformLocation(prog, nome);
    }
    return { prog: prog, u: uniforms, usar: function () { gl.useProgram(prog); } };
  }

  var P = {};
  for (var chave in FRAG) {
    P[chave] = programa(FRAG[chave]);
    if (!P[chave]) { return; }
  }

  // Quadrado que cobre a tela toda; cada passo da simulação desenha nele.
  gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, -1, 1, 1, 1, 1, -1]), gl.STATIC_DRAW);
  gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, gl.createBuffer());
  gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, new Uint16Array([0, 1, 2, 0, 2, 3]), gl.STATIC_DRAW);
  gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
  gl.enableVertexAttribArray(0);

  function desenhar(alvo) {
    if (alvo) {
      gl.viewport(0, 0, alvo.largura, alvo.altura);
      gl.bindFramebuffer(gl.FRAMEBUFFER, alvo.fbo);
    } else {
      gl.viewport(0, 0, gl.drawingBufferWidth, gl.drawingBufferHeight);
      gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    }
    gl.drawElements(gl.TRIANGLES, 6, gl.UNSIGNED_SHORT, 0);
  }

  function criarFBO(largura, altura) {
    gl.activeTexture(gl.TEXTURE0);
    var textura = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, textura);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.texImage2D(gl.TEXTURE_2D, 0, formatoInterno, largura, altura, 0, gl.RGBA, tipoHalf, null);

    var fbo = gl.createFramebuffer();
    gl.bindFramebuffer(gl.FRAMEBUFFER, fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, textura, 0);
    gl.viewport(0, 0, largura, altura);
    gl.clear(gl.COLOR_BUFFER_BIT);

    return {
      fbo: fbo, textura: textura, largura: largura, altura: altura,
      texelX: 1 / largura, texelY: 1 / altura,
      ok: gl.checkFramebufferStatus(gl.FRAMEBUFFER) === gl.FRAMEBUFFER_COMPLETE,
      usarEm: function (unidade) {
        gl.activeTexture(gl.TEXTURE0 + unidade);
        gl.bindTexture(gl.TEXTURE_2D, textura);
        return unidade;
      }
    };
  }

  // Par de texturas: lê de uma e escreve na outra, depois troca.
  function criarDuplo(largura, altura) {
    var a = criarFBO(largura, altura), b = criarFBO(largura, altura);
    return {
      ok: a.ok && b.ok, largura: largura, altura: altura, texelX: a.texelX, texelY: a.texelY,
      ler: a, escrever: b, partes: [a, b],
      trocar: function () { var t = this.ler; this.ler = this.escrever; this.escrever = t; }
    };
  }

  function resolucao(base) {
    var proporcao = gl.drawingBufferWidth / gl.drawingBufferHeight;
    if (proporcao < 1) { proporcao = 1 / proporcao; }
    var menor = Math.round(base), maior = Math.round(base * proporcao);
    return gl.drawingBufferWidth > gl.drawingBufferHeight
      ? { l: maior, a: menor } : { l: menor, a: maior };
  }

  var tinta, velocidade, divergencia, giro, pressao;

  function todosFBOs() {
    return tinta ? tinta.partes.concat(velocidade.partes, pressao.partes, [divergencia, giro]) : [];
  }

  function criarTexturas() {
    todosFBOs().forEach(function (f) {
      gl.deleteTexture(f.textura);
      gl.deleteFramebuffer(f.fbo);
    });
    var sim = resolucao(CONFIG.SIM_RESOLUCAO);
    var cor = resolucao(CONFIG.TINTA_RESOLUCAO);
    tinta = criarDuplo(cor.l, cor.a);
    velocidade = criarDuplo(sim.l, sim.a);
    divergencia = criarFBO(sim.l, sim.a);
    giro = criarFBO(sim.l, sim.a);
    pressao = criarDuplo(sim.l, sim.a);
    return tinta.ok && velocidade.ok && divergencia.ok && giro.ok && pressao.ok;
  }

  function ajustarTamanho() {
    // resolução da tela sem multiplicar pelo zoom do monitor: o fluido é
    // desfocado de qualquer jeito e assim fica bem mais leve
    var l = window.innerWidth, a = window.innerHeight;
    if (canvas.width === l && canvas.height === a) { return true; }
    canvas.width = l;
    canvas.height = a;
    return criarTexturas();
  }

  document.body.appendChild(canvas);
  if (!ajustarTamanho()) { canvas.remove(); return; }
  window.addEventListener('resize', ajustarTamanho);

  // ---------- Simulação ----------
  function passo(dt) {
    gl.disable(gl.BLEND);

    P.rotacional.usar();
    gl.uniform2f(P.rotacional.u.texelSize, velocidade.texelX, velocidade.texelY);
    gl.uniform1i(P.rotacional.u.uVelocity, velocidade.ler.usarEm(0));
    desenhar(giro);

    P.vorticidade.usar();
    gl.uniform2f(P.vorticidade.u.texelSize, velocidade.texelX, velocidade.texelY);
    gl.uniform1i(P.vorticidade.u.uVelocity, velocidade.ler.usarEm(0));
    gl.uniform1i(P.vorticidade.u.uCurl, giro.usarEm(1));
    gl.uniform1f(P.vorticidade.u.curl, CONFIG.GIRO);
    gl.uniform1f(P.vorticidade.u.dt, dt);
    desenhar(velocidade.escrever);
    velocidade.trocar();

    P.divergencia.usar();
    gl.uniform2f(P.divergencia.u.texelSize, velocidade.texelX, velocidade.texelY);
    gl.uniform1i(P.divergencia.u.uVelocity, velocidade.ler.usarEm(0));
    desenhar(divergencia);

    P.limpar.usar();
    gl.uniform1i(P.limpar.u.uTexture, pressao.ler.usarEm(0));
    gl.uniform1f(P.limpar.u.value, CONFIG.PRESSAO);
    desenhar(pressao.escrever);
    pressao.trocar();

    P.pressao.usar();
    gl.uniform2f(P.pressao.u.texelSize, velocidade.texelX, velocidade.texelY);
    gl.uniform1i(P.pressao.u.uDivergence, divergencia.usarEm(0));
    for (var i = 0; i < CONFIG.ITERACOES_PRESSAO; i++) {
      gl.uniform1i(P.pressao.u.uPressure, pressao.ler.usarEm(1));
      desenhar(pressao.escrever);
      pressao.trocar();
    }

    P.gradiente.usar();
    gl.uniform2f(P.gradiente.u.texelSize, velocidade.texelX, velocidade.texelY);
    gl.uniform1i(P.gradiente.u.uPressure, pressao.ler.usarEm(0));
    gl.uniform1i(P.gradiente.u.uVelocity, velocidade.ler.usarEm(1));
    desenhar(velocidade.escrever);
    velocidade.trocar();

    P.adveccao.usar();
    gl.uniform2f(P.adveccao.u.texelSize, velocidade.texelX, velocidade.texelY);
    gl.uniform1i(P.adveccao.u.uVelocity, velocidade.ler.usarEm(0));
    gl.uniform1i(P.adveccao.u.uSource, velocidade.ler.usarEm(0));
    gl.uniform1f(P.adveccao.u.dt, dt);
    gl.uniform1f(P.adveccao.u.dissipation, CONFIG.VELOCIDADE_SOME);
    desenhar(velocidade.escrever);
    velocidade.trocar();

    gl.uniform1i(P.adveccao.u.uVelocity, velocidade.ler.usarEm(0));
    gl.uniform1i(P.adveccao.u.uSource, tinta.ler.usarEm(1));
    gl.uniform1f(P.adveccao.u.dissipation, CONFIG.TINTA_SOME);
    desenhar(tinta.escrever);
    tinta.trocar();
  }

  function mostrar() {
    gl.clearColor(0, 0, 0, 0);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    gl.viewport(0, 0, gl.drawingBufferWidth, gl.drawingBufferHeight);
    gl.clear(gl.COLOR_BUFFER_BIT);
    P.mostrar.usar();
    gl.uniform1i(P.mostrar.u.uTexture, tinta.ler.usarEm(0));
    desenhar(null);
  }

  function pincelada(x, y, dx, dy, cor) {
    var proporcao = canvas.width / canvas.height;
    var raio = CONFIG.RAIO / 100;
    if (proporcao > 1) { raio *= proporcao; }

    P.pincelada.usar();
    gl.uniform1i(P.pincelada.u.uTarget, velocidade.ler.usarEm(0));
    gl.uniform1f(P.pincelada.u.aspectRatio, proporcao);
    gl.uniform2f(P.pincelada.u.point, x, y);
    gl.uniform3f(P.pincelada.u.color, dx, dy, 0);
    gl.uniform1f(P.pincelada.u.radius, raio);
    desenhar(velocidade.escrever);
    velocidade.trocar();

    gl.uniform1i(P.pincelada.u.uTarget, tinta.ler.usarEm(0));
    gl.uniform3f(P.pincelada.u.color, cor[0], cor[1], cor[2]);
    desenhar(tinta.escrever);
    tinta.trocar();
  }

  // cor que passa devagar por roxo → azul → magenta → roxo...
  function corAtual(agora) {
    var t = (agora / 2500) % CORES.length;
    var i = Math.floor(t), f = t - i;
    var a = CORES[i], b = CORES[(i + 1) % CORES.length];
    return [0, 1, 2].map(function (k) {
      return (a[k] + (b[k] - a[k]) * f) * CONFIG.INTENSIDADE;
    });
  }

  // ---------- Mouse ----------
  var mouse = { x: 0, y: 0, dx: 0, dy: 0, moveu: false, primeiro: true };
  var ultimoMovimento = 0;

  document.addEventListener('mousemove', function (evento) {
    var x = evento.clientX / canvas.width;
    var y = 1 - evento.clientY / canvas.height;
    if (mouse.primeiro) {
      mouse.primeiro = false;
    } else {
      var proporcao = canvas.width / canvas.height;
      mouse.dx = (x - mouse.x) * (proporcao < 1 ? proporcao : 1);
      mouse.dy = (y - mouse.y) * (proporcao > 1 ? 1 / proporcao : 1);
      mouse.moveu = Math.abs(mouse.dx) > 0 || Math.abs(mouse.dy) > 0;
    }
    mouse.x = x;
    mouse.y = y;
    ultimoMovimento = performance.now();
  });
  document.documentElement.addEventListener('mouseleave', function () { mouse.primeiro = true; });

  var ultimoQuadro = performance.now();
  var limpo = true;

  (function quadro() {
    var agora = performance.now();
    var dt = Math.min((agora - ultimoQuadro) / 1000, 0.016666);
    ultimoQuadro = agora;

    if (agora - ultimoMovimento < CONFIG.PARADO_MS) {
      if (mouse.moveu) {
        pincelada(mouse.x, mouse.y, mouse.dx * CONFIG.FORCA, mouse.dy * CONFIG.FORCA, corAtual(agora));
        mouse.moveu = false;
      }
      passo(dt);
      mostrar();
      limpo = false;
    } else if (!limpo) {
      // mouse parado há um tempo: a tinta já sumiu, então pausa a simulação
      // (economiza bateria) e deixa o canvas vazio
      gl.clearColor(0, 0, 0, 0);
      todosFBOs().forEach(function (f) {
        gl.bindFramebuffer(gl.FRAMEBUFFER, f.fbo);
        gl.clear(gl.COLOR_BUFFER_BIT);
      });
      gl.bindFramebuffer(gl.FRAMEBUFFER, null);
      gl.clear(gl.COLOR_BUFFER_BIT);
      limpo = true;
    }
    window.requestAnimationFrame(quadro);
  })();
})();
