<?php

/**
 * Plugin Backup e Atualização GLPI - configurações, pastas de trabalho, ambiente deste GLPI e utilitários de shell
 */
class PluginBackupeatualizacaoglpiConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_backupeatualizacaoglpi_configs';
    public const PLUGIN = 'backupeatualizacaoglpi';

    public static function getTypeName($nb = 0): string
    {
        return 'Backup e Atualização GLPI';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::podeUsar();
    }

    public static function canCreate(): bool
    {
        return self::podeUsar();
    }

    public static function canUpdate(): bool
    {
        return self::podeUsar();
    }

    public static function canDelete(): bool
    {
        return self::podeUsar();
    }

    public static function canPurge(): bool
    {
        return self::podeUsar();
    }

    /** A ferramenta substitui o banco inteiro: só quem administra o GLPI */
    public static function podeUsar(): bool
    {
        return (bool) Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'origens'          => '[]',
            'exclusoes_files'  => '_cache,_sessions,_tmp,_lock,_cron,_graphs,_rss,_dumps,_log',
            'backup_files'     => '1',
            'backup_retencao'  => '7',
            'php_cli'          => '',
        ];
    }

    private static ?array $bkCache = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$bkCache === null) {
            self::$bkCache = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$bkCache[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$bkCache)) {
            return self::$bkCache[$name];
        }
        return $default ?? (self::padroes()[$name] ?? null);
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$bkCache = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function exclusoes(): array
    {
        $lista = array_filter(array_map(static fn($x) => trim($x, " /\t"), explode(',', (string) self::getConfig('exclusoes_files'))));
        return array_values(array_unique(array_filter($lista, static fn($x) => preg_match('/^[A-Za-z0-9_.-]+$/', $x))));
    }

    // =====================================================================
    // Pastas de trabalho (fora do banco: sobrevivem à troca do banco numa migração)
    // =====================================================================

    public static function pasta(string $sub = ''): string
    {
        $base = GLPI_PLUGIN_DOC_DIR . '/' . self::PLUGIN;
        return $sub === '' ? $base : $base . '/' . $sub;
    }

    public static function pastas(): void
    {
        foreach (['', 'ssh', 'tarefas', 'backups'] as $sub) {
            $p = self::pasta($sub);
            if (!is_dir($p)) {
                @mkdir($p, 0750, true);
            }
        }
        @chmod(self::pasta('ssh'), 0700);
        if (!is_file(self::pasta('.htaccess'))) {
            @file_put_contents(self::pasta('.htaccess'), "Require all denied\nOrder Deny,Allow\nDeny from all\n");
        }
    }

    /** Guarda a configuração do plugin num arquivo (antes de o banco ser substituído) */
    public static function salvarInstantaneo(): void
    {
        self::pastas();
        @file_put_contents(self::pasta('instantaneo.json'), json_encode(self::getAllConfigs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function restaurarInstantaneo(): void
    {
        $arq = self::pasta('instantaneo.json');
        if (!is_file($arq)) {
            return;
        }
        $dados = json_decode((string) file_get_contents($arq), true);
        if (is_array($dados)) {
            foreach ($dados as $nome => $valor) {
                self::setConfig((string) $nome, $valor);
            }
        }
        @unlink($arq);
    }

    // =====================================================================
    // Este GLPI (destino)
    // =====================================================================

    /** Credenciais do banco a partir de um config_db.php (a senha fica gravada com rawurlencode) */
    public static function lerConfigDb(string $arquivo): array
    {
        $t = is_file($arquivo) ? (string) file_get_contents($arquivo) : '';
        $v = [];
        foreach (['dbhost', 'dbuser', 'dbpassword', 'dbdefault'] as $c) {
            $v[$c] = preg_match('/\$' . $c . '\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $t, $m) ? stripcslashes($m[1]) : '';
        }
        $v['dbpassword'] = rawurldecode($v['dbpassword']);
        [$host, $porta] = array_pad(explode(':', $v['dbhost'], 2), 2, '');
        return ['host' => $host, 'porta' => (int) ($porta ?: 0), 'usuario' => $v['dbuser'], 'senha' => $v['dbpassword'], 'banco' => $v['dbdefault']];
    }

    public static function phpCli(): string
    {
        $conf = trim((string) self::getConfig('php_cli'));
        if ($conf !== '' && is_executable($conf)) {
            return $conf;
        }
        $versao = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach (['/usr/bin/php' . $versao, '/usr/local/bin/php' . $versao, '/usr/bin/php', '/usr/local/bin/php'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        return 'php';
    }

    public static function ferramenta(array $nomes): string
    {
        foreach ($nomes as $n) {
            $s = trim((string) self::executar('command -v ' . escapeshellarg($n) . ' 2>/dev/null')[1]);
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    /** Ambiente deste GLPI: versão, pastas, banco e ferramentas */
    public static function ambiente(): array
    {
        global $DB, $CFG_GLPI;
        $db = self::lerConfigDb(GLPI_CONFIG_DIR . '/config_db.php');
        $versaoBanco = '';
        $r = $DB->doQuery('SELECT VERSION() AS v');
        if ($r && ($l = $DB->fetchAssoc($r))) {
            $versaoBanco = (string) $l['v'];
        }
        return [
            'versao'       => GLPI_VERSION,
            'raiz'         => GLPI_ROOT,
            'config'       => GLPI_CONFIG_DIR,
            'files'        => GLPI_VAR_DIR,
            'documentos'   => defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR : GLPI_VAR_DIR,
            'plugins'      => GLPI_ROOT . '/plugins',
            'marketplace'  => defined('GLPI_MARKETPLACE_DIR') ? GLPI_MARKETPLACE_DIR : GLPI_ROOT . '/marketplace',
            'php'          => PHP_VERSION,
            'php_cli'      => self::phpCli(),
            'banco'        => $db,
            'versao_banco' => $versaoBanco,
            'dump'         => self::ferramenta(['mariadb-dump', 'mysqldump']),
            'cliente'      => self::ferramenta(['mariadb', 'mysql']),
            'ssh'          => self::ferramenta(['ssh']),
            'tar'          => self::ferramenta(['tar']),
            'gzip'         => self::ferramenta(['gzip']),
            'livre'        => (int) @disk_free_space(GLPI_VAR_DIR),
            'instavel'     => (bool) preg_match('/(dev|alpha|beta|rc)/i', GLPI_VERSION),
            'url_base'     => (string) ($CFG_GLPI['url_base'] ?? ''),
            'url_base_api' => (string) ($CFG_GLPI['url_base_api'] ?? ''),
            'servidor'     => php_uname('n'),
        ];
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    /** Executa um comando de shell e devolve [código, saída padrão, erro] */
    public static function executar(string $cmd, ?string $entrada = null, int $limite = 0, ?callable $aoEsperar = null): array
    {
        $ultimoAviso = time();
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open(['/bin/bash', '-o', 'pipefail', '-c', $cmd], $desc, $pipes);
        if (!is_resource($p)) {
            return [127, '', 'Não foi possível executar o comando.'];
        }
        if ($entrada !== null) {
            fwrite($pipes[0], $entrada);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $inicio = time();
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                break;
            }
            if ($aoEsperar !== null && time() - $ultimoAviso >= 3) {
                $ultimoAviso = time();
                $aoEsperar();
            }
            if (strlen($out) > 4000000) {
                $out = substr($out, -2000000);
            }
            if ($limite > 0 && time() - $inicio > $limite) {
                proc_terminate($p, 9);
                $err .= "\n(tempo esgotado)";
                break;
            }
            usleep(100000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($p);
        if (isset($st) && !$st['running'] && $st['exitcode'] >= 0) {
            $codigo = $st['exitcode'];
        }
        return [(int) $codigo, $out, $err];
    }

    /** Arquivo de opções do cliente MySQL/MariaDB (a senha não aparece na linha de comando) */
    public static function arquivoCredenciais(array $db, string $pasta): string
    {
        $arq = $pasta . '/.cred_' . bin2hex(random_bytes(6)) . '.cnf';
        $linhas = "[client]\nuser=\"" . addcslashes((string) $db['usuario'], "\"\\") . "\"\npassword=\"" . addcslashes((string) $db['senha'], "\"\\") . "\"\nhost=\"" . addcslashes((string) $db['host'], "\"\\") . "\"\n";
        if ((int) $db['porta'] > 0) {
            $linhas .= 'port=' . (int) $db['porta'] . "\n";
        }
        file_put_contents($arq, $linhas);
        @chmod($arq, 0600);
        return $arq;
    }

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/backupeatualizacaoglpi/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    public static function tokenCsrf(): string
    {
        return (version_compare(GLPI_VERSION, '12.0.0-dev', '<') && session_status() === PHP_SESSION_ACTIVE) ? Session::getNewCSRFToken() : '';
    }

    public static function tamanho(float $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($u) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $i === 0 ? 0 : 1, ',', '.') . ' ' . $u[$i];
    }

    public static function duracao(int $s): string
    {
        return $s >= 3600 ? sprintf('%dh%02dmin', intdiv($s, 3600), intdiv($s % 3600, 60)) : ($s >= 60 ? sprintf('%dmin %02ds', intdiv($s, 60), $s % 60) : $s . 's');
    }

    /** Versão "10.0.18" em [10, 0, 18] para comparação */
    public static function serie(string $versao): string
    {
        return preg_match('/^(\d+)\.(\d+)/', $versao, $m) ? $m[1] . '.' . $m[2] : $versao;
    }
}
