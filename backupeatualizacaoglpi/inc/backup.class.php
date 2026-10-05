<?php

/**
 * Plugin Backup e Atualização GLPI - backups completos deste GLPI (banco, config/chaves, files, plugins, marketplace)
 * Cada backup é uma pasta em files/_plugins/backupeatualizacaoglpi/backups/<nome>/ com um manifesto; ele também
 * serve de origem para uma migração (inclusive copiado de outro servidor para essa pasta).
 */
class PluginBackupeatualizacaoglpiBackup extends CommonGLPI
{
    public const ARQUIVOS = [
        'banco.sql.gz'       => 'Banco de dados',
        'config.tar.gz'      => 'Configuração e chaves',
        'files.tar.gz'       => 'Arquivos',
        'plugins.tar.gz'     => 'Plugins',
        'marketplace.tar.gz' => 'Marketplace',
        'manifesto.json'     => 'Manifesto',
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Backups';
    }

    public static function canView(): bool
    {
        return PluginBackupeatualizacaoglpiConfig::podeUsar();
    }

    public static function pasta(string $nome = ''): string
    {
        return PluginBackupeatualizacaoglpiConfig::pasta('backups' . ($nome !== '' ? '/' . basename($nome) : ''));
    }

    public static function nomeValido(string $nome): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $nome) && !str_starts_with($nome, '.');
    }

    /** Backups prontos (com manifesto), mais recentes primeiro */
    public static function listar(): array
    {
        $lista = [];
        foreach ((array) @scandir(self::pasta()) as $nome) {
            if (!self::nomeValido((string) $nome) || !is_dir(self::pasta($nome))) {
                continue;
            }
            $arq = self::pasta($nome) . '/manifesto.json';
            $m = is_file($arq) ? json_decode((string) file_get_contents($arq), true) : null;
            if (!is_array($m)) {
                continue;
            }
            $lista[] = [
                'nome'       => $nome,
                'criado'     => (string) ($m['criado'] ?? ''),
                'por'        => (string) ($m['por'] ?? ''),
                'versao'     => (string) ($m['versao'] ?? ''),
                'servidor'   => (string) ($m['servidor'] ?? ''),
                'com_files'  => !empty($m['com_files']),
                'automatico' => str_starts_with($nome, 'auto-'),
                'tamanho'    => self::tamanho($nome),
                'tabelas'    => (int) ($m['total_tabelas'] ?? 0),
            ];
        }
        usort($lista, static fn($a, $b) => strcmp($b['criado'], $a['criado']));
        return $lista;
    }

    public static function tamanho(string $nome): int
    {
        $total = 0;
        foreach (array_keys(self::ARQUIVOS) as $a) {
            $total += (int) @filesize(self::pasta($nome) . '/' . $a);
        }
        return $total;
    }

    /** Cria a tarefa de backup e dispara o processo em segundo plano */
    public static function iniciar(bool $automatico = false, string $rotulo = ''): array
    {
        if ($ativa = PluginBackupeatualizacaoglpiTarefa::ativa()) {
            return ['ok' => false, 'mensagem' => 'Já existe uma tarefa em andamento (' . $ativa['id'] . ').'];
        }
        $amb = PluginBackupeatualizacaoglpiConfig::ambiente();
        foreach (['dump' => 'mariadb-dump/mysqldump', 'tar' => 'tar', 'gzip' => 'gzip'] as $k => $n) {
            if ($amb[$k] === '') {
                return ['ok' => false, 'mensagem' => 'Este servidor não tem "' . $n . '".'];
            }
        }
        $rotulo = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rotulo) ?: ''), '-');
        $nome = ($automatico ? 'auto-' : 'glpi-') . preg_replace('/[^0-9.]/', '', PluginBackupeatualizacaoglpiConfig::serie(GLPI_VERSION)) . '-' . date('Ymd-His') . ($rotulo !== '' ? '-' . mb_substr($rotulo, 0, 40) : '');
        $t = PluginBackupeatualizacaoglpiTarefa::criar('backup', [
            'ambiente' => $amb,
            'backup'   => $nome,
            'opcoes'   => [
                'files'     => PluginBackupeatualizacaoglpiConfig::getConfig('backup_files') === '1',
                'exclusoes' => PluginBackupeatualizacaoglpiConfig::exclusoes(),
                'retencao'  => $automatico ? (int) PluginBackupeatualizacaoglpiConfig::getConfig('backup_retencao') : 0,
            ],
            'usuario'  => $automatico ? 'Ação automática' : getUserName((int) Session::getLoginUserID()),
        ]);
        $t = PluginBackupeatualizacaoglpiTarefa::iniciar($t);
        return ['ok' => true, 'mensagem' => 'Backup iniciado.', 'tarefa' => $t['id']];
    }

    public static function excluir(string $nome): bool
    {
        if (!self::nomeValido($nome) || !is_dir(self::pasta($nome))) {
            return false;
        }
        PluginBackupeatualizacaoglpiConfig::executar('rm -rf ' . escapeshellarg(self::pasta($nome)));
        return !is_dir(self::pasta($nome));
    }

    /** Mantém só os N backups automáticos mais recentes (os manuais nunca são apagados) */
    public static function aplicarRetencao(int $manter): array
    {
        if ($manter <= 0) {
            return [];
        }
        $auto = array_values(array_filter(self::listar(), static fn($b) => $b['automatico']));
        $removidos = [];
        foreach (array_slice($auto, $manter) as $b) {
            if (self::excluir($b['nome'])) {
                $removidos[] = $b['nome'];
            }
        }
        return $removidos;
    }

    // ------------------------------------------------------------------ ação automática

    public static function cronInfo($name): array
    {
        return ['description' => 'Backup completo do GLPI (banco, configuração, arquivos e plugins) com retenção'];
    }

    public static function cronBackupeatualizacaoglpiBackup($task): int
    {
        $r = self::iniciar(true);
        if ($task) {
            $task->log($r['mensagem']);
        }
        return $r['ok'] ? 1 : 0;
    }
}
