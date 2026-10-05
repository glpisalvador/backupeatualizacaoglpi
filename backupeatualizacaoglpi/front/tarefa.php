<?php

/**
 * Plugin Backup e Atualização GLPI - tarefas: lista e acompanhamento (etapas, log ao vivo, conferência, reversão)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginBackupeatualizacaoglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
$C = PluginBackupeatualizacaoglpiConfig::class;
$T =PluginBackupeatualizacaoglpiTarefa::class;
$id = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['id'] ?? ''));
$t = $id !== '' ? $T::carregar($id) : null;

Html::header('Tarefas', $_SERVER['PHP_SELF'], 'config', 'PluginBackupeatualizacaoglpiMenu', 'tarefa');
echo '<div class="bkg-pagina">';
PluginBackupeatualizacaoglpiMenu::barra('tarefa');

$pill = static function (string $sit) use ($T, $C): string {
    [$rot, $cls] = $T::situacoes()[$sit] ?? [$sit, 'neutro'];
    return '<span class="bkg-pill bkg-pill-' . $cls . '">' . $C::e($rot) . '</span>';
};
$descrever = static function (array $t) use ($T): string {
    $d = $T::tipos()[$t['tipo']] ?? $t['tipo'];
    if ($t['tipo'] === 'migracao') {
        $d .= ': GLPI ' . ($t['analise']['versao'] ?? '?') . ' → ' . ($t['ambiente']['versao'] ?? '?');
    } elseif ($t['tipo'] === 'backup') {
        $d .= ' ' . ($t['backup'] ?? '');
    } elseif ($t['tipo'] === 'reversao') {
        $d .= ' da migração ' . ($t['alvo'] ?? '');
    }
    return $d;
};
$duracao = static function (array $t) use ($C): string {
    if (empty($t['inicio'])) {
        return '—';
    }
    return $C::duracao(max(0, (empty($t['fim']) ? time() : strtotime($t['fim'])) - strtotime($t['inicio'])));
};

if (!$t) {
    // ------------------------------------------------------------------ lista
    if ($id !== '') {
        echo '<div class="bkg-alerta bkg-alerta-aviso"><i class="ti ti-alert-triangle"></i> <span>Tarefa não encontrada.</span></div>';
    }
    $lista = $T::listar(100);
    echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-list-check"></i> Tarefas</h5></div><div class="card-body p-0">';
    if (!$lista) {
        echo '<p class="text-muted bkg-vazio"><i class="ti ti-mood-empty"></i> Nenhuma tarefa ainda.</p>';
    } else {
        echo '<table class="table table-sm table-hover bkg-tabela mb-0"><thead><tr><th>Tarefa</th><th>Situação</th><th>Criada</th><th>Duração</th><th>Por</th><th class="text-end">Espaço</th></tr></thead><tbody>';
        foreach ($lista as $x) {
            echo '<tr><td><a href="' . $C::url('tarefa.php', ['id' => $x['id']]) . '">' . $C::e($descrever($x)) . '</a></td><td>' . $pill($x['situacao']) . '</td><td>' . $C::e(Html::convDateTime($x['criada'])) . '</td><td>' . $C::e($duracao($x)) . '</td><td>' . $C::e($x['usuario']) . '</td><td class="text-end">' . $C::e($C::tamanho((float) $T::tamanho($x['id']))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
    echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> Cada migração guarda o backup deste GLPI feito antes dela (para reverter). Exclua as tarefas antigas para liberar espaço.</p>';
    echo '</div>';
    Html::footer();
    return;
}

// ------------------------------------------------------------------ detalhe
$rodando = in_array($t['situacao'], ['aguardando', 'executando'], true);
echo '<div class="card bkg-card" id="bkg-tarefa" data-id="' . $C::e($t['id']) . '" data-rodando="' . ($rodando ? '1' : '0') . '">';
echo '<div class="card-header bkg-cabecalho"><h5><i class="ti ti-list-check"></i> ' . $C::e($descrever($t)) . '</h5><span id="bkg-situacao">' . $pill($t['situacao']) . '</span></div><div class="card-body">';
echo '<div class="bkg-resumo"><span>Criada em <strong>' . $C::e(Html::convDateTime($t['criada'])) . '</strong> por ' . $C::e($t['usuario']) . '</span><span>Duração: <strong id="bkg-duracao">' . $C::e($duracao($t)) . '</strong></span>';
if ($t['tipo'] === 'migracao') {
    echo '<span>Origem: ' . $C::e(PluginBackupeatualizacaoglpiOrigem::descrever($t['origem'])) . '</span>';
}
echo '</div>';

if (!empty($t['resultado']['erro'])) {
    echo '<div class="bkg-alerta bkg-alerta-erro"><i class="ti ti-alert-triangle"></i> <div><strong>Falha</strong><br>' . $C::e($t['resultado']['erro']) . (!empty($t['resultado']['revertido']) ? '<br>Este GLPI foi revertido automaticamente ao estado anterior em ' . $C::e(Html::convDateTime($t['resultado']['revertido'])) . '.' : '') . (!empty($t['resultado']['erro_reversao']) ? '<br><strong>A reversão automática também falhou:</strong> ' . $C::e($t['resultado']['erro_reversao']) : '') . '</div></div>';
}

echo '<ol class="bkg-etapas" id="bkg-etapas">';
foreach ($t['etapas'] as $e) {
    $s = $e['situacao'] ?? 'pendente';
    $ic = ['pendente' => 'ti-circle', 'executando' => 'ti-loader bkg-gira', 'ok' => 'ti-circle-check', 'aviso' => 'ti-alert-triangle', 'erro' => 'ti-circle-x', 'pulada' => 'ti-player-skip-forward'][$s] ?? 'ti-circle';
    echo '<li class="bkg-etapa bkg-etapa-' . $C::e($s) . '" data-chave="' . $C::e($e['chave']) . '"><i class="ti ' . $ic . '"></i><div><strong>' . $C::e($e['titulo']) . '</strong><small class="bkg-etapa-detalhe">' . $C::e($e['detalhe'] ?? '') . '</small></div></li>';
}
echo '</ol>';
echo '<div class="bkg-progresso" id="bkg-progresso">' . $C::e($t['progresso'] ?? '') . '</div>';
echo '<div class="bkg-alerta bkg-alerta-info d-none" id="bkg-sem-sessao"><i class="ti ti-info-circle"></i> <span>Este GLPI está trocando de banco e sendo atualizado: a página não responde por alguns instantes e a sua sessão pode terminar. A tarefa continua no servidor. Se for pedido login, entre com um administrador do GLPI de origem e volte a esta página.</span></div>';

echo '<div class="bkg-acoes">';
if ($rodando && $t['tipo'] === 'migracao') {
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="bkg-cancelar" data-confirmar="Clique de novo para cancelar"><i class="ti ti-player-stop"></i> Cancelar</button>';
}
if ($t['tipo'] === 'migracao' && in_array($t['situacao'], ['concluida', 'aviso'], true) && !empty($t['resultado']['reversao_disponivel']) && empty($t['resultado']['revertido'])) {
    echo '<button type="button" class="btn btn-sm btn-outline-danger" id="bkg-reverter" data-confirmar="Clique de novo: este GLPI volta a ser como antes da migração"><i class="ti ti-arrow-back-up"></i> Reverter esta migração</button>';
}
if (!$rodando) {
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="bkg-excluir-tarefa" data-confirmar="Clique de novo para excluir (inclui o backup de reversão)"><i class="ti ti-trash"></i> Excluir tarefa</button>';
}
if ($t['tipo'] === 'backup' && $t['situacao'] === 'concluida') {
    echo '<a class="btn btn-sm btn-outline-secondary" href="' . $C::url('backups.php') . '"><i class="ti ti-archive"></i> Ver backups</a>';
}
echo '<span id="bkg-retorno" class="bkg-retorno"></span></div>';
echo '</div></div>';

// ------------------------------------------------------------------ resultado da migração
if ($t['tipo'] === 'migracao' && !$rodando && empty($t['resultado']['revertido'])) {
    $r = $t['resultado'];
    if (!empty($r['verificacao'])) {
        echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-scale"></i> Conferência dos registros</h5></div><div class="card-body">';
        PluginBackupeatualizacaoglpiVerificacao::exibir($r['verificacao']);
        echo '</div></div>';
    }
    if (in_array($t['situacao'], ['concluida', 'aviso'], true)) {
        echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-checklist"></i> Próximos passos</h5></div><div class="card-body"><ol class="bkg-passos">';
        $copiados = array_merge($r['plugins_copiados']['plugins'] ?? [], $r['plugins_copiados']['marketplace'] ?? []);
        echo '<li>Plugins: ' . ($copiados ? count($copiados) . ' pasta(s) copiada(s) e desativada(s) (' . $C::e(implode(', ', $copiados)) . '). Em <a href="' . $C::e($CFG_GLPI['root_doc'] . '/front/plugin.php') . '">Configurar &gt; Plugins</a>, atualize cada um para uma versão compatível com o GLPI ' . $C::e($t['ambiente']['versao']) . ' e ative.' : 'nenhuma pasta copiada.') . '</li>';
        if (!empty($t['plano']['flags']['formcreator'])) {
            echo '<li>Formulários: confira em Administração &gt; Formulários. Os do Formcreator agora são nativos; o plugin Formcreator não é mais necessário.</li>';
        }
        if (!empty($t['plano']['flags']['genericobject'])) {
            echo '<li>Ativos personalizados: os tipos do Generic Objects agora são ativos nativos. Preferências de exibição e buscas salvas deles precisam ser refeitas.</li>';
        }
        if (!empty($r['coletores_desativados'])) {
            echo '<li>' . (int) $r['coletores_desativados'] . ' coletor(es) de e-mail ficaram desativados: reative em Configurar &gt; Coletores quando o servidor antigo parar de coletar.</li>';
        }
        echo '<li>Ações automáticas: confira em Configurar &gt; Ações automáticas e configure o cron deste servidor (<code>php ' . $C::e($t['ambiente']['raiz']) . '/front/cron.php</code> a cada minuto).</li>';
        echo '<li>Teste o envio de e-mail em Configurar &gt; Notificações &gt; Configuração dos e-mails.</li>';
        foreach ($r['avisos'] ?? [] as $a) {
            echo '<li>' . $C::e($a) . '</li>';
        }
        if (is_file($T::pasta($t['id']) . '/origem_local_define.php.txt')) {
            echo '<li>A origem tinha <code>config/local_define.php</code>; uma cópia está em <code>' . $C::e($T::pasta($t['id'])) . '/origem_local_define.php.txt</code> para você conferir se algo precisa ser repetido aqui.</li>';
        }
        echo '</ol></div></div>';
    }
}

[$log, $tam] = $T::lerLog($t['id']);
echo '<div class="card bkg-card"><div class="card-header bkg-cabecalho"><h5><i class="ti ti-file-text"></i> Registro</h5><label class="form-check form-switch bkg-switch-curto"><input class="form-check-input" type="checkbox" id="bkg-rolar" checked> <span class="form-check-label">acompanhar</span></label></div><div class="card-body p-0">';
echo '<pre class="bkg-log" id="bkg-log" data-tamanho="' . (int) $tam . '">' . $C::e($log) . '</pre></div></div>';

echo '</div>';
Html::footer();
