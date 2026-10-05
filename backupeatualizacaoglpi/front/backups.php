<?php

/**
 * Plugin Backup e Atualização GLPI - backups completos deste GLPI: criar, baixar, usar como origem, excluir
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginBackupeatualizacaoglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
$C = PluginBackupeatualizacaoglpiConfig::class;
$B = PluginBackupeatualizacaoglpiBackup::class;
$lista = $B::listar();
$ativa = PluginBackupeatualizacaoglpiTarefa::ativa();

Html::header('Backups', $_SERVER['PHP_SELF'], 'config', 'PluginBackupeatualizacaoglpiMenu', 'backups');
echo '<div class="bkg-pagina" id="bkg-backups">';
PluginBackupeatualizacaoglpiMenu::barra('backups');

echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-archive"></i> Novo backup</h5></div><div class="card-body">';
echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> Guarda o banco inteiro, a pasta config (com as chaves de criptografia), ' . ($C::getConfig('backup_files') === '1' ? 'a pasta files (documentos e imagens), ' : '') . 'os plugins e o marketplace. Um backup daqui pode ser restaurado em outro servidor, inclusive com versão mais nova do GLPI (Migrar / atualizar &gt; Backup feito por este plugin).</p>';
echo '<div class="bkg-linha"><label class="bkg-rotulo" for="bkg-rotulo">Identificação</label><input type="text" id="bkg-rotulo" class="form-control form-control-sm bkg-campo-medio" maxlength="40" placeholder="opcional, ex.: antes-da-atualizacao">';
echo '<button type="button" class="btn btn-sm bkg-btn-principal" id="bkg-fazer-backup"' . ($ativa ? ' disabled' : '') . '><i class="ti ti-player-play"></i> Fazer backup agora</button><span id="bkg-retorno" class="bkg-retorno"></span></div>';
if ($ativa) {
    echo '<div class="bkg-alerta bkg-alerta-aviso"><i class="ti ti-loader bkg-gira"></i> <span>Há uma tarefa em andamento.</span> <a class="btn btn-sm btn-outline-secondary" href="' . $C::url('tarefa.php', ['id' => $ativa['id']]) . '">Acompanhar</a></div>';
}
echo '</div></div>';

echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-database"></i> Backups guardados (' . count($lista) . ')</h5></div><div class="card-body p-0">';
if (!$lista) {
    echo '<p class="text-muted bkg-vazio"><i class="ti ti-mood-empty"></i> Nenhum backup ainda.</p>';
} else {
    echo '<table class="table table-sm table-hover bkg-tabela mb-0"><thead><tr><th>Backup</th><th>GLPI</th><th>Criado</th><th>Por</th><th class="text-end">Tamanho</th><th>Baixar</th><th class="text-end">Ações</th></tr></thead><tbody>';
    foreach ($lista as $b) {
        echo '<tr data-backup="' . $C::e($b['nome']) . '"><td><strong>' . $C::e($b['nome']) . '</strong>' . ($b['automatico'] ? ' <span class="bkg-pill bkg-pill-neutro">automático</span>' : '') . (!$b['com_files'] ? ' <span class="bkg-pill bkg-pill-aviso">sem files</span>' : '') . '<br><small class="text-muted">' . $C::e($b['servidor']) . ' · ' . (int) $b['tabelas'] . ' tabelas</small></td>';
        echo '<td>' . $C::e($b['versao']) . '</td><td>' . $C::e(Html::convDateTime($b['criado'])) . '</td><td>' . $C::e($b['por']) . '</td><td class="text-end">' . $C::e($C::tamanho((float) $b['tamanho'])) . '</td><td><span class="bkg-botoes">';
        foreach ($B::ARQUIVOS as $arq => $rot) {
            if (is_file($B::pasta($b['nome']) . '/' . $arq)) {
                echo '<a class="btn btn-sm btn-outline-secondary bkg-btn-icone" title="' . $C::e($rot . ' (' . $C::tamanho((float) filesize($B::pasta($b['nome']) . '/' . $arq)) . ')') . '" href="' . $C::url('baixar.php', ['backup' => $b['nome'], 'arquivo' => $arq]) . '">' . $C::e(explode('.', $arq)[0]) . '</a>';
            }
        }
        echo '</span></td><td class="text-end"><span class="bkg-botoes">';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . $C::url('migrar.php', ['backup' => $b['nome']]) . '" title="Restaurar este backup aqui"><i class="ti ti-database-import"></i> Restaurar</a>';
        echo '<button type="button" class="btn btn-sm btn-outline-danger bkg-excluir-backup" data-confirmar="Excluir?"><i class="ti ti-trash"></i></button>';
        echo '</span></td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> Pasta dos backups: <code>' . $C::e($B::pasta()) . '</code>. Para trazer um backup de outro servidor, copie a pasta dele para cá (mantendo o manifesto.json). Backups automáticos: ação automática <strong>BackupeatualizacaoglpiBackup</strong> em <a href="' . $C::e($CFG_GLPI['root_doc'] . '/front/crontask.php') . '">Configurar &gt; Ações automáticas</a>; a retenção (' . (int) $C::getConfig('backup_retencao') . ') vale só para eles.</p>';

echo '</div>';
Html::footer();
