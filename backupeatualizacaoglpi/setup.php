<?php

/**
 * Plugin Backup e Atualização GLPI - GLPI 11 e 12
 * Roda no GLPI novo e traz tudo de um GLPI antigo (9.5, 10, 11...): banco inteiro, chaves de criptografia,
 * arquivos, plugins (desativados) e marketplace; executa a atualização oficial do GLPI, converte Generic Objects
 * e Formcreator para o núcleo, confere as contagens e permite reverter. Também faz backups completos.
 */

define('PLUGIN_BACKUPEATUALIZACAOGLPI_VERSION', '2.0.0');
define('PLUGIN_BACKUPEATUALIZACAOGLPI_MIN_GLPI', '11.0.0');
define('PLUGIN_BACKUPEATUALIZACAOGLPI_MAX_GLPI', '12.99.99');

function plugin_init_backupeatualizacaoglpi(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['backupeatualizacaoglpi'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('backupeatualizacaoglpi')) {
        return;
    }

    Plugin::registerClass('PluginBackupeatualizacaoglpiMenu');
    Plugin::registerClass('PluginBackupeatualizacaoglpiBackup');

    $PLUGIN_HOOKS['config_page']['backupeatualizacaoglpi'] = 'front/config.form.php';

    if (Session::getLoginUserID() && PluginBackupeatualizacaoglpiConfig::podeUsar()) {
        $PLUGIN_HOOKS['menu_toadd']['backupeatualizacaoglpi'] = ['config' => 'PluginBackupeatualizacaoglpiMenu'];
        $PLUGIN_HOOKS['add_css']['backupeatualizacaoglpi'] = ['css/backupeatualizacaoglpi.css'];
        $PLUGIN_HOOKS['add_javascript']['backupeatualizacaoglpi'] = ['js/backupeatualizacaoglpi.js'];
    }
}

function plugin_version_backupeatualizacaoglpi(): array
{
    return [
        'name'         => 'Backup e Atualização GLPI',
        'version'      => PLUGIN_BACKUPEATUALIZACAOGLPI_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BACKUPEATUALIZACAOGLPI_MIN_GLPI,
                'max' => PLUGIN_BACKUPEATUALIZACAOGLPI_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_backupeatualizacaoglpi_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_BACKUPEATUALIZACAOGLPI_MIN_GLPI, '>=');
}

function plugin_backupeatualizacaoglpi_check_config($verbose = false): bool
{
    return true;
}
