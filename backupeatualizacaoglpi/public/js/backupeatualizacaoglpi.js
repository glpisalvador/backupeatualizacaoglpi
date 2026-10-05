/**
 * Plugin Backup e Atualização GLPI - origem e análise, início da migração, acompanhamento ao vivo e backups
 */
(function () {
    'use strict';

    if (window.BackupAtualizacaoGlpi) {
        return;
    }
    window.BackupAtualizacaoGlpi = true;

    var raiz = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
    var urlAjax = raiz + '/plugins/backupeatualizacaoglpi/front/ajax.php';

    function $(sel, base) { return (base || document).querySelector(sel); }
    function $$(sel, base) { return Array.prototype.slice.call((base || document).querySelectorAll(sel)); }

    function aviso(texto, erro) {
        if (erro && typeof window.glpi_toast_error === 'function') {
            window.glpi_toast_error(texto);
        } else if (!erro && typeof window.glpi_toast_info === 'function') {
            window.glpi_toast_info(texto);
        }
    }

    function lerJson(texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = texto.match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try { return JSON.parse(m[0]); } catch (e2) { /* segue */ }
            }
        }
        return { success: false, invalida: true, message: 'Resposta inválida do servidor.' };
    }

    function pedir(acao, dados, metodo) {
        var opcoes = { method: metodo || 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        var url = urlAjax + '?action=' + encodeURIComponent(acao);
        if (opcoes.method === 'GET') {
            Object.keys(dados).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(dados[k]); });
        } else {
            var fd = new FormData();
            Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
            var m = document.querySelector('meta[property="glpi:csrf_token"]');
            var t = m ? m.getAttribute('content') : '';
            if (t) {
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
                fd.append('_glpi_csrf_token', t);
            }
            opcoes.body = fd;
        }
        return fetch(url, opcoes).then(function (r) {
            return r.text().then(function (txt) {
                var j = lerJson(txt);
                j.http = r.status;
                return j;
            });
        }).then(function (j) {
            var m = document.querySelector('meta[property="glpi:csrf_token"]');
            if (j.new_token && m) { m.setAttribute('content', j.new_token); }
            return j;
        });
    }

    function retorno(el, texto, ok) {
        if (!el) { return; }
        el.textContent = texto || '';
        el.className = 'bkg-retorno ' + (ok ? 'ok' : 'erro');
    }

    function ocupado(btn, sim) {
        if (!btn) { return; }
        if (sim) {
            btn.dataset.html = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="ti ti-loader bkg-gira"></i> Aguarde…';
        } else {
            btn.disabled = false;
            if (btn.dataset.html) { btn.innerHTML = btn.dataset.html; }
        }
    }

    // ------------------------------------------------------------------ confirmação dentro do próprio botão
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-confirmar]');
        if (!btn || btn.disabled) { return; }
        if (btn.dataset.armado === '1') {
            // Segundo clique: desarma e deixa a ação seguir
            clearTimeout(parseInt(btn.dataset.tempo || '0', 10));
            btn.innerHTML = btn.dataset.original;
            btn.classList.remove('bkg-confirmando');
            btn.dataset.armado = '';
            return;
        }
        ev.preventDefault();
        ev.stopImmediatePropagation();
        btn.dataset.original = btn.innerHTML;
        btn.innerHTML = '<i class="ti ti-alert-triangle"></i> ' + btn.getAttribute('data-confirmar');
        btn.classList.add('bkg-confirmando');
        btn.dataset.armado = '1';
        btn.dataset.tempo = String(setTimeout(function () {
            btn.innerHTML = btn.dataset.original;
            btn.classList.remove('bkg-confirmando');
            btn.dataset.armado = '';
        }, 4000));
    }, true);

    // ------------------------------------------------------------------ copiar
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copiar]');
        if (!btn) { return; }
        var alvo = $(btn.getAttribute('data-copiar'));
        if (!alvo) { return; }
        var texto = alvo.textContent.trim();
        var ok = function () { aviso('Copiado.'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(ok);
        } else {
            var ta = document.createElement('textarea');
            ta.value = texto;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); ok(); } catch (e) { /* sem cópia */ }
            ta.remove();
        }
    });

    // ------------------------------------------------------------------ migração
    function iniciarMigrar() {
        var raizM = $('#bkg-migrar');
        if (!raizM) { return; }

        function tipo() {
            var r = $('input[name="bkg_tipo"]:checked', raizM);
            return r ? r.value : 'ssh';
        }
        function mostrarCampos() {
            var t = tipo();
            $$('[data-tipos]', raizM).forEach(function (el) {
                var tipos = el.getAttribute('data-tipos').split(' ');
                el.classList.toggle('d-none', tipos.indexOf(t) === -1);
            });
            limparPlano();
        }
        function origem() {
            return {
                tipo: tipo(),
                nome: ($('#bkg-nome') || {}).value || '',
                host: ($('#bkg-host') || {}).value || '',
                porta: ($('#bkg-porta') || {}).value || '22',
                usuario: ($('#bkg-usuario') || {}).value || '',
                caminho: ($('#bkg-caminho') || {}).value || '',
                backup: ($('#bkg-backup') || {}).value || ''
            };
        }
        function limparPlano() {
            $('#bkg-card-plano').classList.add('d-none');
            $('#bkg-card-opcoes').classList.add('d-none');
            $('#bkg-plano').innerHTML = '';
            $('#bkg-iniciar').disabled = true;
        }
        function atualizarIniciar() {
            var bloqueado = $('#bkg-plano').dataset.bloqueado === '1';
            $('#bkg-iniciar').disabled = bloqueado || !$('#bkg-entendi').checked;
        }

        $$('input[name="bkg_tipo"]', raizM).forEach(function (r) { r.addEventListener('change', mostrarCampos); });
        ['#bkg-host', '#bkg-porta', '#bkg-usuario', '#bkg-caminho', '#bkg-backup'].forEach(function (s) {
            var el = $(s);
            if (el) { el.addEventListener('change', limparPlano); }
        });
        mostrarCampos();

        var salva = $('#bkg-salva');
        if (salva) {
            salva.addEventListener('change', function () {
                var op = salva.options[salva.selectedIndex];
                if (!op || !op.dataset.origem) { return; }
                var o = JSON.parse(op.dataset.origem);
                var r = $('input[name="bkg_tipo"][value="' + o.tipo + '"]', raizM);
                if (r) { r.checked = true; }
                ['nome', 'host', 'porta', 'usuario', 'caminho', 'backup'].forEach(function (k) {
                    var el = $('#bkg-' + k);
                    if (el && o[k] !== undefined) { el.value = o[k]; }
                });
                mostrarCampos();
            });
            $('#bkg-remover-salva').addEventListener('click', function () {
                if (salva.value === '') { return; }
                pedir('remover_origem', { indice: salva.value }).then(function (j) {
                    aviso(j.message, !j.success);
                    if (j.success) { salva.options[salva.selectedIndex].remove(); salva.value = ''; }
                });
            });
        }

        $('#bkg-testar').addEventListener('click', function () {
            var btn = this;
            ocupado(btn, true);
            retorno($('#bkg-retorno'), '', true);
            pedir('testar', origem()).then(function (j) {
                ocupado(btn, false);
                retorno($('#bkg-retorno'), j.message, j.success);
            }).catch(function () { ocupado(btn, false); retorno($('#bkg-retorno'), 'Falha de comunicação.', false); });
        });

        $('#bkg-salvar-origem').addEventListener('click', function () {
            pedir('salvar_origem', origem()).then(function (j) { retorno($('#bkg-retorno'), j.message, j.success); });
        });

        $('#bkg-analisar').addEventListener('click', function () {
            var btn = this;
            ocupado(btn, true);
            limparPlano();
            retorno($('#bkg-retorno'), 'Lendo a origem (pode levar alguns segundos)…', true);
            pedir('analisar', origem()).then(function (j) {
                ocupado(btn, false);
                retorno($('#bkg-retorno'), j.message, j.success);
                if (!j.html) { return; }
                var plano = $('#bkg-plano');
                plano.innerHTML = j.html;
                plano.dataset.bloqueado = j.bloqueado ? '1' : '0';
                $('#bkg-card-plano').classList.remove('d-none');
                if (j.success) { $('#bkg-card-opcoes').classList.remove('d-none'); }
                atualizarIniciar();
                $('#bkg-card-plano').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }).catch(function () { ocupado(btn, false); retorno($('#bkg-retorno'), 'Falha de comunicação.', false); });
        });

        $('#bkg-entendi').addEventListener('change', atualizarIniciar);

        $('#bkg-iniciar').addEventListener('click', function () {
            var btn = this;
            var dados = origem();
            $$('[data-opcao]', raizM).forEach(function (c) { if (c.checked) { dados[c.getAttribute('data-opcao')] = '1'; } });
            dados.exclusoes = $('#bkg-exclusoes').value;
            dados.entendi = $('#bkg-entendi').checked ? '1' : '';
            ocupado(btn, true);
            retorno($('#bkg-retorno-iniciar'), 'Preparando…', true);
            pedir('iniciar_migracao', dados).then(function (j) {
                if (j.success && j.url) {
                    window.location.href = j.url;
                    return;
                }
                ocupado(btn, false);
                retorno($('#bkg-retorno-iniciar'), j.message, false);
            }).catch(function () { ocupado(btn, false); retorno($('#bkg-retorno-iniciar'), 'Falha de comunicação.', false); });
        });
    }

    // ------------------------------------------------------------------ acompanhamento da tarefa
    function iniciarTarefa() {
        var card = $('#bkg-tarefa');
        if (!card) { return; }
        var id = card.getAttribute('data-id');
        var log = $('#bkg-log');
        var tamanho = parseInt(log.getAttribute('data-tamanho') || '0', 10);
        var icones = { pendente: 'ti-circle', executando: 'ti-loader bkg-gira', ok: 'ti-circle-check', aviso: 'ti-alert-triangle', erro: 'ti-circle-x', pulada: 'ti-player-skip-forward' };
        var falhas = 0;

        function rolar() {
            if ($('#bkg-rolar') && $('#bkg-rolar').checked) { log.scrollTop = log.scrollHeight; }
        }
        rolar();

        function aplicar(j) {
            j.etapas.forEach(function (e) {
                var li = $('.bkg-etapa[data-chave="' + e.chave + '"]', card);
                if (!li) { return; }
                li.className = 'bkg-etapa bkg-etapa-' + e.situacao;
                li.querySelector('i').className = 'ti ' + (icones[e.situacao] || 'ti-circle');
                li.querySelector('.bkg-etapa-detalhe').textContent = e.detalhe || '';
            });
            $('#bkg-progresso').textContent = j.progresso || '';
            $('#bkg-duracao').textContent = j.duracao;
            $('#bkg-situacao').innerHTML = '<span class="bkg-pill bkg-pill-' + j.classe + '">' + j.rotulo.replace(/</g, '&lt;') + '</span>';
            if (j.log) {
                log.textContent += j.log;
                tamanho = j.tamanho;
                rolar();
            }
        }

        function ciclo() {
            pedir('tarefa', { id: id, desde: tamanho }, 'GET').then(function (j) {
                if (!j.success) {
                    falhas++;
                    // Durante a troca do banco o plugin fica inativo e a sessão pode cair: continua tentando
                    $('#bkg-sem-sessao').classList.remove('d-none');
                    setTimeout(ciclo, 5000);
                    return;
                }
                falhas = 0;
                $('#bkg-sem-sessao').classList.add('d-none');
                aplicar(j);
                if (j.rodando) {
                    setTimeout(ciclo, 2000);
                } else {
                    setTimeout(function () { window.location.reload(); }, 1200);
                }
            }).catch(function () {
                falhas++;
                $('#bkg-sem-sessao').classList.remove('d-none');
                setTimeout(ciclo, 5000);
            });
        }
        if (card.getAttribute('data-rodando') === '1') {
            setTimeout(ciclo, 1500);
        }

        var acao = function (sel, nome, recarregar) {
            var btn = $(sel);
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                ocupado(btn, true);
                pedir(nome, { id: id }).then(function (j) {
                    ocupado(btn, false);
                    retorno($('#bkg-retorno'), j.message, j.success);
                    if (j.success && j.url) { window.location.href = j.url; } else if (j.success && recarregar) { setTimeout(function () { window.location.reload(); }, 800); }
                }).catch(function () { ocupado(btn, false); retorno($('#bkg-retorno'), 'Falha de comunicação.', false); });
            });
        };
        acao('#bkg-cancelar', 'cancelar', false);
        acao('#bkg-reverter', 'reverter', true);
        acao('#bkg-excluir-tarefa', 'excluir_tarefa', false);
    }

    // ------------------------------------------------------------------ backups
    function iniciarBackups() {
        var raizB = $('#bkg-backups');
        if (!raizB) { return; }
        var btn = $('#bkg-fazer-backup');
        if (btn) {
            btn.addEventListener('click', function () {
                ocupado(btn, true);
                pedir('backup_iniciar', { rotulo: $('#bkg-rotulo').value }).then(function (j) {
                    if (j.success && j.url) { window.location.href = j.url; return; }
                    ocupado(btn, false);
                    retorno($('#bkg-retorno'), j.message, j.success);
                }).catch(function () { ocupado(btn, false); retorno($('#bkg-retorno'), 'Falha de comunicação.', false); });
            });
        }
        $$('.bkg-excluir-backup', raizB).forEach(function (b) {
            b.addEventListener('click', function () {
                var tr = b.closest('tr');
                pedir('backup_excluir', { backup: tr.getAttribute('data-backup') }).then(function (j) {
                    aviso(j.message, !j.success);
                    if (j.success) { tr.remove(); }
                });
            });
        });
    }

    function iniciar() {
        iniciarMigrar();
        iniciarTarefa();
        iniciarBackups();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
