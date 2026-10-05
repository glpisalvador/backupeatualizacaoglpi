<?php

/**
 * Plugin Backup e Atualização GLPI - configuração: chave SSH, backups e opções avançadas
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginBackupeatualizacaoglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
$C = PluginBackupeatualizacaoglpiConfig::class;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_action'])) {
    switch ($_POST['save_action']) {
        case 'salvar_backups':
            $C::setConfig('backup_files', !empty($_POST['backup_files']) ? '1' : '0');
            $C::setConfig('backup_retencao', (string) max(0, min(365, (int) ($_POST['backup_retencao'] ?? 7))));
            Session::addMessageAfterRedirect('Configuração dos backups salva.', false, INFO);
            break;
        case 'salvar_avancado':
            $excl = array_values(array_filter(array_map(static fn($x) => trim($x, " /\t"), explode(',', (string) ($_POST['exclusoes_files'] ?? ''))), static fn($x) => (bool) preg_match('/^[A-Za-z0-9_.-]+$/', $x)));
            $C::setConfig('exclusoes_files', implode(',', $excl));
            $php = trim((string) ($_POST['php_cli'] ?? ''));
            if ($php !== '' && (!preg_match('#^/[A-Za-z0-9_./-]+$#', $php) || !is_executable($php))) {
                Session::addMessageAfterRedirect('O PHP informado não existe ou não é executável: ' . $php, false, ERROR);
            } else {
                $C::setConfig('php_cli', $php);
                Session::addMessageAfterRedirect('Opções avançadas salvas.', false, INFO);
            }
            break;
        case 'nova_chave':
            if (PluginBackupeatualizacaoglpiSsh::novaChave()) {
                Session::addMessageAfterRedirect('Nova chave SSH gerada. Atualize o authorized_keys dos servidores de origem.', false, WARNING);
            } else {
                Session::addMessageAfterRedirect('Não foi possível gerar a chave SSH (o ssh-keygen está instalado?).', false, ERROR);
            }
            break;
    }
}

Html::header('Backup e Atualização', $_SERVER['PHP_SELF'], 'config', 'PluginBackupeatualizacaoglpiMenu', 'painel');
echo '<div class="bkg-pagina">';
PluginBackupeatualizacaoglpiMenu::barra('config');

$acao = $C::url('config.form.php');
$abrir = static function (string $save) use ($acao): void {
    // O token CSRF vem do Html::closeForm()
    echo '<form method="post" action="' . $acao . '"><input type="hidden" name="save_action" value="' . $save . '">';
};

// ------------------------------------------------------------------ chave SSH
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-key"></i> Acesso por SSH</h5></div><div class="card-body">';
echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> O plugin acessa a origem com esta chave (gerada aqui, sem senha guardada). Adicione a linha abaixo ao <code>~/.ssh/authorized_keys</code> do usuário usado na origem.</p>';
echo '<div class="bkg-chave-linha"><code id="bkg-chave-publica">' . $C::e(PluginBackupeatualizacaoglpiSsh::chavePublica()) . '</code><button type="button" class="btn btn-sm btn-outline-secondary" data-copiar="#bkg-chave-publica"><i class="ti ti-copy"></i> Copiar</button></div>';
$abrir('nova_chave');
echo '<div class="bkg-acoes"><button type="submit" class="btn btn-sm btn-outline-secondary" data-confirmar="Clique de novo: a chave atual deixa de valer"><i class="ti ti-refresh"></i> Gerar nova chave</button></div>';
Html::closeForm();
echo '</div></div>';

// ------------------------------------------------------------------ backups
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-archive"></i> Backups</h5></div><div class="card-body">';
$abrir('salvar_backups');
echo '<div class="form-check form-switch bkg-switch"><input class="form-check-input" type="checkbox" name="backup_files" id="bkg-backup-files" value="1"' . ($C::getConfig('backup_files') === '1' ? ' checked' : '') . '><label class="form-check-label" for="bkg-backup-files">Incluir a pasta files (documentos, imagens e dados dos plugins)</label></div>';
echo '<div class="bkg-linha"><label class="bkg-rotulo" for="bkg-retencao">Manter backups automáticos</label><input type="number" min="0" max="365" name="backup_retencao" id="bkg-retencao" class="form-control form-control-sm bkg-campo-curto" value="' . (int) $C::getConfig('backup_retencao') . '"><span class="text-muted bkg-dica-inline">os mais recentes (0 = todos). Backups manuais nunca são apagados.</span></div>';
echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> O agendamento é a ação automática <strong>BackupeatualizacaoglpiBackup</strong> (desativada ao instalar) em <a href="' . $C::e($CFG_GLPI['root_doc'] . '/front/crontask.php') . '">Configurar &gt; Ações automáticas</a>.</p>';
echo '<div class="bkg-acoes"><button type="submit" class="btn btn-sm bkg-btn-principal"><i class="ti ti-device-floppy"></i> Salvar</button></div>';
Html::closeForm();
echo '</div></div>';

// ------------------------------------------------------------------ avançado
$amb = $C::ambiente();
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-adjustments"></i> Avançado</h5></div><div class="card-body">';
$abrir('salvar_avancado');
echo '<div class="bkg-linha"><label class="bkg-rotulo" for="bkg-excl">Não copiar de files/</label><input type="text" name="exclusoes_files" id="bkg-excl" class="form-control form-control-sm" value="' . $C::e(implode(',', $C::exclusoes())) . '"></div>';
echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> Vale para migrações e backups. Cache, sessões e temporários são recriados pelo GLPI; _log e _cron guardam só registros antigos.</p>';
echo '<div class="bkg-linha"><label class="bkg-rotulo" for="bkg-php">PHP de linha de comando</label><input type="text" name="php_cli" id="bkg-php" class="form-control form-control-sm bkg-campo-medio" placeholder="automático: ' . $C::e($amb['php_cli']) . '" value="' . $C::e((string) $C::getConfig('php_cli')) . '"><span class="text-muted bkg-dica-inline">deve ser a mesma versão do PHP do site (' . $C::e(PHP_VERSION) . ')</span></div>';
echo '<div class="bkg-acoes"><button type="submit" class="btn btn-sm bkg-btn-principal"><i class="ti ti-device-floppy"></i> Salvar</button></div>';
Html::closeForm();
echo '<p class="text-muted bkg-dica"><i class="ti ti-folder"></i> Dados do plugin (tarefas, backups, chave): <code>' . $C::e($C::pasta()) . '</code></p>';
echo '</div></div>';

echo '</div>';
Html::footer();
