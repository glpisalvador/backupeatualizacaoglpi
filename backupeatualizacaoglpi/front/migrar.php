<?php

/**
 * Plugin Backup e Atualização GLPI - nova migração: origem, análise, plano, opções e início
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginBackupeatualizacaoglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$C = PluginBackupeatualizacaoglpiConfig::class;
$amb = $C::ambiente();
$ativa = PluginBackupeatualizacaoglpiTarefa::ativa();
$salvas = $C::getArrayConfig('origens');
$backups = PluginBackupeatualizacaoglpiBackup::listar();
$pedido = (string) ($_GET['backup'] ?? '');
$inicial = ['tipo' => 'ssh', 'nome' => '', 'host' => '', 'porta' => 22, 'usuario' => 'root', 'caminho' => '/var/www/glpi', 'backup' => ''];
if ($pedido !== '' && PluginBackupeatualizacaoglpiBackup::nomeValido($pedido)) {
    $inicial['tipo'] = 'arquivo';
    $inicial['backup'] = $pedido;
}

Html::header('Migrar / atualizar', $_SERVER['PHP_SELF'], 'config', 'PluginBackupeatualizacaoglpiMenu', 'migrar');

echo '<div class="bkg-pagina" id="bkg-migrar">';
PluginBackupeatualizacaoglpiMenu::barra('migrar');

if ($ativa) {
    echo '<div class="bkg-alerta bkg-alerta-aviso"><i class="ti ti-loader bkg-gira"></i> <span>Já existe uma tarefa em andamento. Aguarde ela terminar para iniciar outra.</span> <a class="btn btn-sm btn-outline-secondary" href="' . $C::url('tarefa.php', ['id' => $ativa['id']]) . '">Acompanhar</a></div>';
}

// ------------------------------------------------------------------ 1. origem
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-database-import"></i> 1. De onde vêm os dados</h5></div><div class="card-body">';
echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> Os dados deste GLPI (' . $C::e($amb['versao']) . ') serão substituídos pelos da origem. A origem só é lida, nunca alterada.</p>';

if ($salvas) {
    echo '<div class="bkg-linha"><label class="bkg-rotulo" for="bkg-salva">Origens salvas</label><select id="bkg-salva" class="form-select form-select-sm bkg-campo-medio"><option value="">— escolher —</option>';
    foreach ($salvas as $i => $s) {
        echo '<option value="' . (int) $i . '" data-origem="' . $C::e(json_encode($s, JSON_UNESCAPED_UNICODE)) . '">' . $C::e(($s['nome'] ?: PluginBackupeatualizacaoglpiOrigem::descrever(PluginBackupeatualizacaoglpiOrigem::deEntrada($s)))) . '</option>';
    }
    echo '</select><button type="button" class="btn btn-sm btn-outline-secondary" id="bkg-remover-salva" data-confirmar="Clique de novo para remover"><i class="ti ti-trash"></i> Remover</button></div>';
}

echo '<div class="bkg-tipos" role="radiogroup">';
foreach (PluginBackupeatualizacaoglpiOrigem::tipos() as $k => $t) {
    $ic = ['ssh' => 'ti-server', 'local' => 'ti-folder', 'arquivo' => 'ti-archive'][$k];
    echo '<label class="bkg-tipo"><input type="radio" name="bkg_tipo" value="' . $k . '"' . ($inicial['tipo'] === $k ? ' checked' : '') . '><span><i class="ti ' . $ic . '"></i> ' . $C::e($t) . '</span></label>';
}
echo '</div>';

echo '<div class="bkg-form" id="bkg-origem">';
echo '<div class="bkg-linha" data-tipos="ssh"><label class="bkg-rotulo" for="bkg-host">Servidor</label><input type="text" id="bkg-host" class="form-control form-control-sm bkg-campo-medio" placeholder="endereço IP ou nome" value="' . $C::e($inicial['host']) . '">';
echo '<label class="bkg-rotulo-curto" for="bkg-porta">Porta</label><input type="number" id="bkg-porta" class="form-control form-control-sm bkg-campo-curto" min="1" max="65535" value="' . (int) $inicial['porta'] . '">';
echo '<label class="bkg-rotulo-curto" for="bkg-usuario">Usuário</label><input type="text" id="bkg-usuario" class="form-control form-control-sm bkg-campo-curto" value="' . $C::e($inicial['usuario']) . '"></div>';
echo '<div class="bkg-linha" data-tipos="ssh local"><label class="bkg-rotulo" for="bkg-caminho">Pasta do GLPI</label><input type="text" id="bkg-caminho" class="form-control form-control-sm bkg-campo-medio" value="' . $C::e($inicial['caminho']) . '"><span class="text-muted bkg-dica-inline">onde ficam inc/, front/ e config/ (ou o config_db.php)</span></div>';
echo '<div class="bkg-linha" data-tipos="arquivo"><label class="bkg-rotulo" for="bkg-backup">Backup</label>';
if ($backups) {
    echo '<select id="bkg-backup" class="form-select form-select-sm bkg-campo-medio">';
    foreach ($backups as $b) {
        echo '<option value="' . $C::e($b['nome']) . '"' . ($inicial['backup'] === $b['nome'] ? ' selected' : '') . '>' . $C::e($b['nome'] . ' · GLPI ' . $b['versao'] . ' · ' . $C::tamanho((float) $b['tamanho'])) . '</option>';
    }
    echo '</select>';
} else {
    echo '<span class="text-muted">Nenhum backup neste servidor. Copie a pasta de um backup (feito por este plugin em outro GLPI) para <code>' . $C::e(PluginBackupeatualizacaoglpiBackup::pasta()) . '</code>.</span><input type="hidden" id="bkg-backup" value="">';
}
echo '</div>';
echo '<div class="bkg-linha"><label class="bkg-rotulo" for="bkg-nome">Nome (opcional)</label><input type="text" id="bkg-nome" class="form-control form-control-sm bkg-campo-medio" maxlength="100" placeholder="para salvar e reutilizar esta origem" value=""></div>';

echo '<div class="bkg-chave" data-tipos="ssh"><div><strong><i class="ti ti-key"></i> Chave pública deste GLPI</strong>';
echo '<p class="text-muted bkg-dica">Adicione esta linha ao arquivo <code>~/.ssh/authorized_keys</code> do usuário acima no servidor de origem. O plugin não guarda senha. Use um usuário que consiga ler a pasta do GLPI e rodar o PHP (normalmente root).</p></div>';
echo '<div class="bkg-chave-linha"><code id="bkg-chave-publica">' . $C::e(PluginBackupeatualizacaoglpiSsh::chavePublica()) . '</code><button type="button" class="btn btn-sm btn-outline-secondary" data-copiar="#bkg-chave-publica"><i class="ti ti-copy"></i> Copiar</button></div></div>';

echo '<div class="bkg-acoes">';
echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="bkg-testar" data-tipos="ssh local"><i class="ti ti-plug-connected"></i> Testar acesso</button>';
echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="bkg-salvar-origem"><i class="ti ti-device-floppy"></i> Salvar origem</button>';
echo '<button type="button" class="btn btn-sm bkg-btn-principal" id="bkg-analisar"><i class="ti ti-search"></i> Analisar origem</button>';
echo '<span id="bkg-retorno" class="bkg-retorno"></span>';
echo '</div>';
echo '</div></div></div>';

// ------------------------------------------------------------------ 2. plano e opções (preenchido pela análise)
echo '<div class="card bkg-card d-none" id="bkg-card-plano"><div class="card-header"><h5><i class="ti ti-clipboard-list"></i> 2. Plano</h5></div><div class="card-body"><div id="bkg-plano"></div></div></div>';

echo '<div class="card bkg-card d-none" id="bkg-card-opcoes"><div class="card-header"><h5><i class="ti ti-adjustments"></i> 3. Opções e início</h5></div><div class="card-body">';
$opcoes = [
    'manter_url'          => [true, 'Manter o endereço deste GLPI', 'Os links dos e-mails continuam apontando para ' . ($amb['url_base'] ?: 'este servidor') . ' (e não para o servidor antigo).'],
    'limpar_fila'         => [true, 'Descartar e-mails ainda não enviados da origem', 'Evita que este servidor reenvie notificações que o servidor antigo ainda vai enviar.'],
    'desativar_coletores' => [true, 'Desativar os coletores de e-mail', 'Evita que os dois servidores leiam a mesma caixa ao mesmo tempo. Reative em Configurar > Coletores quando desligar o antigo.'],
];
foreach ($opcoes as $k => [$padrao, $rot, $dica]) {
    echo '<div class="form-check form-switch bkg-switch"><input class="form-check-input" type="checkbox" id="bkg-op-' . $k . '" data-opcao="' . $k . '"' . ($padrao ? ' checked' : '') . '><label class="form-check-label" for="bkg-op-' . $k . '">' . $C::e($rot) . '</label><div class="text-muted bkg-dica">' . $C::e($dica) . '</div></div>';
}
echo '<div class="bkg-linha"><label class="bkg-rotulo" for="bkg-exclusoes">Não copiar de files/</label><input type="text" id="bkg-exclusoes" class="form-control form-control-sm" value="' . $C::e(implode(',', $C::exclusoes())) . '"><span class="text-muted bkg-dica-inline">pastas separadas por vírgula (cache, sessões e temporários)</span></div>';
echo '<div class="form-check bkg-check bkg-confirmacao"><input class="form-check-input" type="checkbox" id="bkg-entendi"><label class="form-check-label" for="bkg-entendi">Entendo que <strong>todos os dados deste GLPI</strong> serão substituídos pelos da origem (fica um backup para reverter) e que a sessão atual pode terminar durante a atualização.</label></div>';
echo '<div class="bkg-acoes"><button type="button" class="btn btn-sm bkg-btn-principal" id="bkg-iniciar" disabled data-confirmar="Clique de novo para iniciar a migração"><i class="ti ti-player-play"></i> Iniciar migração</button><span id="bkg-retorno-iniciar" class="bkg-retorno"></span></div>';
echo '</div></div>';

echo '</div>';
Html::footer();
