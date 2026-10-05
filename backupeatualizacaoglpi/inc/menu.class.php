<?php

/**
 * Plugin Backup e Atualização GLPI - menu em Configurar
 */
class PluginBackupeatualizacaoglpiMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Backup e Atualização';
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getIcon(): string
    {
        return 'ti ti-database-export';
    }

    public static function canView(): bool
    {
        return PluginBackupeatualizacaoglpiConfig::podeUsar();
    }

    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }
        $base = '/plugins/backupeatualizacaoglpi/front/';
        return [
            'title'   => self::getMenuName(),
            'page'    => $base . 'painel.php',
            'icon'    => self::getIcon(),
            'options' => [
                'painel'  => ['title' => 'Painel', 'page' => $base . 'painel.php', 'icon' => 'ti ti-layout-dashboard'],
                'migrar'  => ['title' => 'Migrar / atualizar', 'page' => $base . 'migrar.php', 'icon' => 'ti ti-arrows-right-left'],
                'tarefa'  => ['title' => 'Tarefas', 'page' => $base . 'tarefa.php', 'icon' => 'ti ti-list-check'],
                'backups' => ['title' => 'Backups', 'page' => $base . 'backups.php', 'icon' => 'ti ti-archive'],
            ],
        ];
    }

    /** Barra de navegação comum às páginas do plugin */
    public static function barra(string $atual): void
    {
        $itens = [
            'painel'  => ['Painel', 'ti ti-layout-dashboard', 'painel.php'],
            'migrar'  => ['Migrar / atualizar', 'ti ti-arrows-right-left', 'migrar.php'],
            'tarefa'  => ['Tarefas', 'ti ti-list-check', 'tarefa.php'],
            'backups' => ['Backups', 'ti ti-archive', 'backups.php'],
            'config'  => ['Configuração', 'ti ti-settings', 'config.form.php'],
        ];
        echo '<ul class="nav nav-tabs bkg-nav">';
        foreach ($itens as $k => [$t, $i, $arq]) {
            echo '<li class="nav-item"><a class="nav-link' . ($k === $atual ? ' active' : '') . '" href="' . PluginBackupeatualizacaoglpiConfig::url($arq) . '"><i class="' . $i . '"></i> ' . $t . '</a></li>';
        }
        echo '</ul>';
    }
}
