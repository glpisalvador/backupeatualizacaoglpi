<?php

/**
 * Plugin Backup e Atualização GLPI - tarefas em segundo plano (migração, backup, reversão)
 * O estado fica em arquivos (files/_plugins/backupeatualizacaoglpi/tarefas/<id>/), porque a migração substitui o banco.
 */
class PluginBackupeatualizacaoglpiTarefa
{
    public static function tipos(): array
    {
        return ['migracao' => 'Migração / atualização', 'backup' => 'Backup', 'reversao' => 'Reversão'];
    }

    public static function situacoes(): array
    {
        return [
            'aguardando' => ['Aguardando', 'neutro'],
            'executando' => ['Executando', 'andamento'],
            'concluida'  => ['Concluída', 'ok'],
            'aviso'      => ['Concluída com avisos', 'aviso'],
            'erro'       => ['Falhou', 'erro'],
            'cancelada'  => ['Cancelada', 'neutro'],
            'revertida'  => ['Revertida', 'neutro'],
        ];
    }

    public static function pasta(string $id): string
    {
        return PluginBackupeatualizacaoglpiConfig::pasta('tarefas/' . preg_replace('/[^a-z0-9_-]/i', '', $id));
    }

    public static function criar(string $tipo, array $dados): array
    {
        PluginBackupeatualizacaoglpiConfig::pastas();
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        @mkdir(self::pasta($id), 0750, true);
        $t = $dados + [
            'id'        => $id,
            'tipo'      => $tipo,
            'situacao'  => 'aguardando',
            'criada'    => date('Y-m-d H:i:s'),
            'inicio'    => null,
            'fim'       => null,
            'usuario'   => getUserName((int) Session::getLoginUserID()),
            'pid'       => 0,
            'atual'     => '',
            'etapas'    => [],
            'resultado' => [],
        ];
        self::salvar($t);
        return $t;
    }

    public static function carregar(string $id): ?array
    {
        $arq = self::pasta($id) . '/estado.json';
        if (!is_file($arq)) {
            return null;
        }
        $t = json_decode((string) file_get_contents($arq), true);
        return is_array($t) ? $t : null;
    }

    public static function salvar(array $t): void
    {
        $arq = self::pasta($t['id']) . '/estado.json';
        $tmp = $arq . '.tmp';
        file_put_contents($tmp, json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename($tmp, $arq);
    }

    public static function log(array $t, string $msg): void
    {
        @file_put_contents(self::pasta($t['id']) . '/log.txt', '[' . date('H:i:s') . '] ' . rtrim($msg) . "\n", FILE_APPEND);
    }

    public static function lerLog(string $id, int $desde = 0, int $max = 200000): array
    {
        $arq = self::pasta($id) . '/log.txt';
        if (!is_file($arq)) {
            return ['', 0];
        }
        $tam = filesize($arq);
        if ($desde > $tam) {
            $desde = 0;
        }
        $h = fopen($arq, 'r');
        fseek($h, max($desde, $tam - $max));
        $txt = (string) stream_get_contents($h);
        fclose($h);
        return [$txt, $tam];
    }

    /** Lista as tarefas (mais recentes primeiro) */
    public static function listar(int $limite = 50): array
    {
        $lista = [];
        foreach ((array) @scandir(PluginBackupeatualizacaoglpiConfig::pasta('tarefas'), SCANDIR_SORT_DESCENDING) as $id) {
            if ($id === '.' || $id === '..') {
                continue;
            }
            $t = self::carregar($id);
            if ($t) {
                $lista[] = $t;
            }
            if (count($lista) >= $limite) {
                break;
            }
        }
        return $lista;
    }

    public static function ativa(): ?array
    {
        foreach (self::listar(20) as $t) {
            if (in_array($t['situacao'], ['aguardando', 'executando'], true) && self::vivo($t)) {
                return $t;
            }
        }
        return null;
    }

    public static function vivo(array $t): bool
    {
        if ($t['situacao'] === 'aguardando') {
            return strtotime((string) $t['criada']) > time() - 120;
        }
        return (int) $t['pid'] > 0 && (function_exists('posix_kill') ? @posix_kill((int) $t['pid'], 0) : is_dir('/proc/' . (int) $t['pid']));
    }

    /** Dispara o processo de linha de comando, desligado da requisição web */
    public static function iniciar(array $t): array
    {
        $php = PluginBackupeatualizacaoglpiConfig::phpCli();
        $script = dirname(__DIR__) . '/inc/trabalhador.php';
        $saida = self::pasta($t['id']) . '/saida.txt';
        $cmd = 'cd ' . escapeshellarg(GLPI_ROOT) . ' && setsid nohup ' . escapeshellarg($php) . ' -d memory_limit=-1 -d max_execution_time=0 ' . escapeshellarg($script) . ' ' . escapeshellarg($t['id']) . ' > ' . escapeshellarg($saida) . ' 2>&1 < /dev/null & echo $!';
        [, $out] = PluginBackupeatualizacaoglpiConfig::executar($cmd);
        $t['pid'] = (int) trim($out);
        self::salvar($t);
        self::log($t, 'Tarefa ' . self::tipos()[$t['tipo']] . ' iniciada por ' . $t['usuario'] . '.');
        return $t;
    }

    public static function cancelar(string $id): bool
    {
        $t = self::carregar($id);
        if (!$t || !in_array($t['situacao'], ['aguardando', 'executando'], true)) {
            return false;
        }
        @touch(self::pasta($id) . '/cancelar');
        self::log($t, 'Cancelamento pedido: a tarefa para ao fim da etapa atual.');
        return true;
    }

    public static function pedidoCancelamento(string $id): bool
    {
        return is_file(self::pasta($id) . '/cancelar');
    }

    public static function excluir(string $id): bool
    {
        $t = self::carregar($id);
        if (!$t || in_array($t['situacao'], ['aguardando', 'executando'], true)) {
            return false;
        }
        PluginBackupeatualizacaoglpiConfig::executar('rm -rf ' . escapeshellarg(self::pasta($id)));
        return !is_dir(self::pasta($id));
    }

    public static function tamanho(string $id): int
    {
        return (int) trim((string) PluginBackupeatualizacaoglpiConfig::executar('du -sb ' . escapeshellarg(self::pasta($id)) . ' | cut -f1')[1]);
    }
}
