<?php

/**
 * Plugin Backup e Atualização GLPI - instalação e desinstalação (a desinstalação nunca remove tabelas nem backups)
 */

function plugin_backupeatualizacaoglpi_install(): bool
{
    global $DB;

    $t = 'glpi_plugin_backupeatualizacaoglpi_configs';
    if (!$DB->tableExists($t, false)) {
        $DB->doQuery("CREATE TABLE `$t` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }
    foreach (PluginBackupeatualizacaoglpiConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => $t, 'WHERE' => ['name' => $nome]])) === 0) {
            $DB->insert($t, ['name' => $nome, 'value' => $valor]);
        }
    }

    // Configuração guardada por uma migração (o banco foi substituído): é devolvida aqui
    PluginBackupeatualizacaoglpiConfig::restaurarInstantaneo();

    PluginBackupeatualizacaoglpiConfig::pastas();

    CronTask::register('PluginBackupeatualizacaoglpiBackup', 'BackupeatualizacaoglpiBackup', DAY_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_DISABLE,
        'hourmin' => 1,
        'hourmax' => 5,
        'comment' => 'Backup e Atualização GLPI: backup completo agendado e limpeza pela retenção',
    ]);
    return true;
}

/** Desinstalar mantém a tabela, os backups e a chave SSH */
function plugin_backupeatualizacaoglpi_uninstall(): bool
{
    return true;
}
