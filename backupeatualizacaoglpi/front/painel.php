<?php

/**
 * Plugin Backup e Atualização GLPI - painel: este GLPI, requisitos por versão, tarefas e backups recentes
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginBackupeatualizacaoglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$C = PluginBackupeatualizacaoglpiConfig::class;
$amb = $C::ambiente();
$tarefas = PluginBackupeatualizacaoglpiTarefa::listar(8);
$ativa = PluginBackupeatualizacaoglpiTarefa::ativa();
$backups = PluginBackupeatualizacaoglpiBackup::listar();

Html::header('Backup e Atualização', $_SERVER['PHP_SELF'], 'config', 'PluginBackupeatualizacaoglpiMenu', 'painel');

echo '<div class="bkg-pagina">';
PluginBackupeatualizacaoglpiMenu::barra('painel');

if ($ativa) {
    echo '<div class="bkg-alerta bkg-alerta-info"><i class="ti ti-loader bkg-gira"></i> <span>Há uma tarefa em andamento: <strong>' . $C::e(PluginBackupeatualizacaoglpiTarefa::tipos()[$ativa['tipo']] ?? $ativa['tipo']) . '</strong> iniciada em ' . $C::e(Html::convDateTime($ativa['criada'])) . '.</span> <a class="btn btn-sm btn-outline-secondary" href="' . $C::url('tarefa.php', ['id' => $ativa['id']]) . '">Acompanhar</a></div>';
}

echo '<div class="bkg-grade">';

// ------------------------------------------------------------------ este GLPI
$ok = static fn($v) => $v !== '' ? '<span class="bkg-pill bkg-pill-ok">' . PluginBackupeatualizacaoglpiConfig::e(basename($v)) . '</span>' : '<span class="bkg-pill bkg-pill-erro">ausente</span>';
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-server"></i> Este GLPI (destino)</h5></div><div class="card-body">';
echo '<table class="bkg-dados">';
echo '<tr><th>Versão</th><td><strong>' . $C::e($amb['versao']) . '</strong>' . ($amb['instavel'] ? ' <span class="bkg-pill bkg-pill-aviso">versão de testes</span>' : '') . '</td></tr>';
echo '<tr><th>Servidor</th><td>' . $C::e($amb['servidor']) . '</td></tr>';
echo '<tr><th>Endereço</th><td>' . $C::e($amb['url_base'] ?: '—') . '</td></tr>';
echo '<tr><th>PHP</th><td>' . $C::e($amb['php']) . ' <span class="text-muted">(linha de comando: ' . $C::e($amb['php_cli']) . ')</span></td></tr>';
echo '<tr><th>Banco</th><td>' . $C::e($amb['versao_banco']) . ' · <code>' . $C::e($amb['banco']['banco']) . '</code> em ' . $C::e($amb['banco']['host']) . '</td></tr>';
echo '<tr><th>Pastas</th><td><code>' . $C::e($amb['raiz']) . '</code><br><span class="text-muted">files:</span> <code>' . $C::e($amb['files']) . '</code></td></tr>';
echo '<tr><th>Espaço livre</th><td>' . $C::e($C::tamanho((float) $amb['livre'])) . '</td></tr>';
echo '<tr><th>Ferramentas</th><td class="bkg-pills">' . $ok($amb['dump']) . $ok($amb['cliente']) . $ok($amb['ssh']) . $ok($amb['tar']) . $ok($amb['gzip']) . '</td></tr>';
echo '</table></div></div>';

// ------------------------------------------------------------------ como funciona
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-route"></i> Como a migração funciona</h5></div><div class="card-body">';
echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> Instale um GLPI novo (11 ou 12) num servidor, instale este plugin nele e traga tudo do GLPI antigo. A origem nunca é alterada.</p>';
echo '<ol class="bkg-passos">';
foreach ([
    'Backup completo deste GLPI, para poder reverter com um clique.',
    'Cópia do banco inteiro da origem: usuários, grupos, perfis, entidades, regras, SLAs, categorias, formulários, ações automáticas, e-mail, tudo.',
    'Chaves de criptografia (senhas de e-mail/LDAP continuam válidas), documentos, imagens e dados de plugins.',
    'Pastas dos plugins e do marketplace, que chegam desativados para você conferir a compatibilidade.',
    'Atualização oficial do GLPI (db:update) direto para esta versão, sem passar pelas intermediárias.',
    'No GLPI 11 ou superior: Generic Objects vira ativo nativo e Formcreator vira formulário nativo.',
    'Conferência de registros tabela a tabela, antes × depois.',
] as $p) {
    echo '<li>' . $C::e($p) . '</li>';
}
echo '</ol>';
echo '<a class="btn btn-sm bkg-btn-principal" href="' . $C::url('migrar.php') . '"><i class="ti ti-arrows-right-left"></i> Nova migração</a>';
echo '</div></div>';

echo '</div>';

// ------------------------------------------------------------------ requisitos por versão
$req = [
    ['10.0', '7.4 a 8.3', 'MySQL 5.7+ / MariaDB 10.2+', 'Raiz do site pode ser a pasta do GLPI'],
    ['11.0', '8.2 a 8.5', 'MySQL 8.0+ / MariaDB 10.6+', 'Raiz do site obrigatoriamente em public/; bcmath, mbstring, openssl'],
    ['12.0', '8.3 a 8.5', 'MySQL 8.0+ / MariaDB 10.11+', 'Raiz em public/; base de conhecimento reorganizada (sem categorias)'],
];
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-list-details"></i> Requisitos de cada versão</h5></div><div class="card-body p-0">';
echo '<table class="table table-sm table-hover bkg-tabela mb-0"><thead><tr><th>GLPI</th><th>PHP</th><th>Banco</th><th>Observações</th></tr></thead><tbody>';
foreach ($req as [$v, $php, $db, $obs]) {
    $esta = $C::serie($amb['versao']) === $v;
    echo '<tr' . ($esta ? ' class="bkg-linha-atual"' : '') . '><td><strong>' . $v . '</strong>' . ($esta ? ' <span class="bkg-pill bkg-pill-ok">esta</span>' : '') . '</td><td>' . $php . '</td><td>' . $db . '</td><td class="text-muted">' . $obs . '</td></tr>';
}
echo '</tbody></table></div></div>';

// ------------------------------------------------------------------ tarefas
echo '<div class="bkg-grade">';
echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-list-check"></i> Últimas tarefas</h5></div><div class="card-body p-0">';
if (!$tarefas) {
    echo '<p class="text-muted bkg-vazio"><i class="ti ti-mood-empty"></i> Nenhuma tarefa ainda.</p>';
} else {
    echo '<table class="table table-sm table-hover bkg-tabela mb-0"><thead><tr><th>Tarefa</th><th>Situação</th><th>Quando</th></tr></thead><tbody>';
    foreach ($tarefas as $t) {
        [$rot, $cls] = PluginBackupeatualizacaoglpiTarefa::situacoes()[$t['situacao']] ?? [$t['situacao'], 'neutro'];
        $desc = PluginBackupeatualizacaoglpiTarefa::tipos()[$t['tipo']] ?? $t['tipo'];
        if ($t['tipo'] === 'migracao') {
            $desc .= ' ' . ($t['analise']['versao'] ?? '?') . ' → ' . ($t['ambiente']['versao'] ?? '?');
        }
        echo '<tr><td><a href="' . $C::url('tarefa.php', ['id' => $t['id']]) . '">' . $C::e($desc) . '</a></td><td><span class="bkg-pill bkg-pill-' . $cls . '">' . $C::e($rot) . '</span></td><td>' . $C::e(Html::convDateTime($t['criada'])) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

echo '<div class="card bkg-card"><div class="card-header"><h5><i class="ti ti-archive"></i> Backups</h5></div><div class="card-body">';
if (!$backups) {
    echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> Nenhum backup guardado neste servidor.</p>';
} else {
    $total = array_sum(array_column($backups, 'tamanho'));
    echo '<p><strong>' . count($backups) . '</strong> backup(s), ' . $C::e($C::tamanho((float) $total)) . ' no total. Último: ' . $C::e(Html::convDateTime($backups[0]['criado'])) . ' (GLPI ' . $C::e($backups[0]['versao']) . ').</p>';
}
echo '<a class="btn btn-sm btn-outline-secondary" href="' . $C::url('backups.php') . '"><i class="ti ti-archive"></i> Ver backups</a>';
echo '</div></div>';
echo '</div>';

echo '</div>';
Html::footer();
