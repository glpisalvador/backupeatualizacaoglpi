<?php

/**
 * Plugin Backup e Atualização GLPI - origem dos dados: outro servidor (SSH), pasta deste servidor ou backup do plugin
 * A análise roda um script de reconhecimento na origem (compatível com PHP 7.4, para GLPI 9.5/10) que lê a versão,
 * as pastas (inclusive local_define.php/downstream.php), o banco, os plugins e as contagens de registros.
 */
class PluginBackupeatualizacaoglpiOrigem
{
    public static function tipos(): array
    {
        return ['ssh' => 'Outro servidor (SSH)', 'local' => 'Pasta neste servidor', 'arquivo' => 'Backup feito por este plugin'];
    }

    /** Origem a partir de um formulário */
    public static function deEntrada(array $in): array
    {
        $tipo = array_key_exists((string) ($in['tipo'] ?? ''), self::tipos()) ? (string) $in['tipo'] : 'ssh';
        return [
            'tipo'    => $tipo,
            'nome'    => mb_substr(trim((string) ($in['nome'] ?? '')), 0, 100),
            'host'    => trim((string) ($in['host'] ?? '')),
            'porta'   => max(1, min(65535, (int) ($in['porta'] ?? 22) ?: 22)),
            'usuario' => trim((string) ($in['usuario'] ?? '')),
            'caminho' => rtrim(trim((string) ($in['caminho'] ?? '')), '/'),
            'backup'  => basename(trim((string) ($in['backup'] ?? ''))),
        ];
    }

    public static function descrever(array $o): string
    {
        return match ($o['tipo']) {
            'ssh'     => $o['usuario'] . '@' . $o['host'] . ($o['porta'] != 22 ? ':' . $o['porta'] : '') . ':' . $o['caminho'],
            'local'   => 'este servidor: ' . $o['caminho'],
            'arquivo' => 'backup ' . $o['backup'],
            default   => '?',
        };
    }

    public static function pastaBackup(array $o): string
    {
        return PluginBackupeatualizacaoglpiConfig::pasta('backups/' . basename((string) $o['backup']));
    }

    // ------------------------------------------------------------------ reconhecimento

    /** Script executado na origem (PHP >= 7.4). Argumentos: raiz do GLPI e "contar" (opcional) */
    public static function script(): string
    {
        return <<<'PHP'
<?php
error_reporting(0);
$raiz = rtrim(isset($argv[1]) ? $argv[1] : '', '/');
$contar = isset($argv[2]) && $argv[2] === 'contar';
$r = array('ok' => false, 'erros' => array(), 'avisos' => array());
if ($raiz === '' || !is_dir($raiz)) { $r['erros'][] = 'Pasta do GLPI não encontrada: ' . $raiz; echo json_encode($r); exit(0); }
if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', $raiz); }
$versao = '';
foreach ((array) glob($raiz . '/version/*') as $f) { $versao = basename($f); }
if ($versao === '' && is_file($raiz . '/inc/define.php') && preg_match("/define\(\s*'GLPI_VERSION'\s*,\s*'([^']+)'/", file_get_contents($raiz . '/inc/define.php'), $m)) { $versao = $m[1]; }
if ($versao === '') { $r['erros'][] = 'Não parece ser uma instalação do GLPI (versão não encontrada).'; echo json_encode($r); exit(0); }
// Pastas personalizadas (pacotes de distribuição e local_define.php)
if (is_file($raiz . '/inc/downstream.php')) { @include $raiz . '/inc/downstream.php'; }
$cfg = defined('GLPI_CONFIG_DIR') ? GLPI_CONFIG_DIR : $raiz . '/config';
if (is_file($cfg . '/local_define.php')) { @include $cfg . '/local_define.php'; $r['avisos'][] = 'A origem tem config/local_define.php (configurações locais); ele é guardado no relatório para conferência.'; }
$files = defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR : $raiz . '/files';
$docs = defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR : $files;
$mkt = defined('GLPI_MARKETPLACE_DIR') ? GLPI_MARKETPLACE_DIR : $raiz . '/marketplace';
$r['versao'] = $versao;
$r['caminhos'] = array('raiz' => $raiz, 'config' => $cfg, 'files' => $files, 'documentos' => $docs, 'plugins' => $raiz . '/plugins', 'marketplace' => $mkt);
$r['php'] = PHP_VERSION;
$r['chaves'] = array();
foreach (array('glpicrypt.key', 'glpi.key', 'oauth.pem', 'oauth.pub') as $k) { $r['chaves'][$k] = is_file($cfg . '/' . $k); }
// Banco
$t = @file_get_contents($cfg . '/config_db.php');
if ($t === false) { $r['erros'][] = 'config_db.php não encontrado em ' . $cfg; echo json_encode($r); exit(0); }
$db = array();
foreach (array('dbhost', 'dbuser', 'dbpassword', 'dbdefault') as $c) { $db[$c] = preg_match('/\$' . $c . '\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $t, $m) ? stripcslashes($m[1]) : ''; }
$db['dbpassword'] = rawurldecode($db['dbpassword']);
$hp = explode(':', $db['dbhost'], 2);
$r['banco'] = array('host' => $hp[0], 'porta' => isset($hp[1]) ? (int) $hp[1] : 0, 'usuario' => $db['dbuser'], 'senha' => $db['dbpassword'], 'banco' => $db['dbdefault']);
$r['ferramentas'] = array();
foreach (array('dump' => 'mariadb-dump mysqldump', 'gzip' => 'gzip', 'tar' => 'tar') as $k => $nomes) {
  $r['ferramentas'][$k] = '';
  foreach (explode(' ', $nomes) as $n) { $p = trim((string) @shell_exec('command -v ' . escapeshellarg($n) . ' 2>/dev/null')); if ($p !== '') { $r['ferramentas'][$k] = $p; break; } }
}
if (!function_exists('mysqli_connect')) { $r['erros'][] = 'A extensão mysqli do PHP não está disponível na origem.'; echo json_encode($r); exit(0); }
mysqli_report(MYSQLI_REPORT_OFF);
$c = @mysqli_connect($r['banco']['host'], $db['dbuser'], $db['dbpassword'], $db['dbdefault'], $r['banco']['porta'] ?: 3306);
if (!$c) { $r['erros'][] = 'Não foi possível conectar ao banco da origem: ' . mysqli_connect_error(); echo json_encode($r); exit(0); }
mysqli_set_charset($c, 'utf8mb4');
$q = mysqli_query($c, 'SELECT VERSION()');
$r['versao_banco'] = $q ? (string) mysqli_fetch_row($q)[0] : '';
$q = mysqli_query($c, "SELECT COALESCE(SUM(data_length + index_length), 0), SUM(engine = 'MyISAM'), COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
$l = $q ? mysqli_fetch_row($q) : array(0, 0, 0);
$r['tamanho_banco'] = (int) $l[0];
$r['tabelas_myisam'] = (int) $l[1];
$r['total_tabelas'] = (int) $l[2];
$q = mysqli_query($c, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_collation LIKE '%0900%'");
$r['collation_mysql8'] = $q ? (int) mysqli_fetch_row($q)[0] : 0;
$q = mysqli_query($c, "SELECT value FROM glpi_configs WHERE context = 'core' AND name = 'version'");
$r['versao_no_banco'] = ($q && ($l = mysqli_fetch_row($q))) ? (string) $l[0] : '';
$r['plugins'] = array();
$q = mysqli_query($c, 'SELECT directory, name, version, state FROM glpi_plugins ORDER BY directory');
while ($q && ($l = mysqli_fetch_assoc($q))) { $r['plugins'][] = $l; }
$r['pastas_plugins'] = array();
foreach (array('plugins' => $raiz . '/plugins', 'marketplace' => $mkt) as $k => $d) { $r['pastas_plugins'][$k] = array(); foreach ((array) @scandir($d) as $n) { if ($n !== '.' && $n !== '..' && $n !== '' && is_dir($d . '/' . $n)) { $r['pastas_plugins'][$k][] = $n; } } }
$r['tamanho_files'] = (int) trim((string) @shell_exec('du -sb ' . escapeshellarg($files) . ' 2>/dev/null | cut -f1'));
$r['contagens'] = array();
if ($contar) {
  $q = mysqli_query($c, 'SHOW TABLES');
  $tabs = array();
  while ($q && ($l = mysqli_fetch_row($q))) { $tabs[] = $l[0]; }
  foreach ($tabs as $tb) { $q2 = mysqli_query($c, 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $tb) . '`'); if ($q2) { $r['contagens'][$tb] = (int) mysqli_fetch_row($q2)[0]; } }
}
$r['ok'] = true;
echo json_encode($r);
PHP;
    }

    /** Executa o reconhecimento na origem; para backups lê o manifesto */
    public static function analisar(array $o, bool $contar = false): array
    {
        if ($o['tipo'] === 'arquivo') {
            $arq = self::pastaBackup($o) . '/manifesto.json';
            $m = is_file($arq) ? json_decode((string) file_get_contents($arq), true) : null;
            if (!is_array($m)) {
                return ['ok' => false, 'erros' => ['Backup não encontrado ou sem manifesto.'], 'avisos' => []];
            }
            $m['ok'] = true;
            $m['erros'] = [];
            $m['avisos'] = $m['avisos'] ?? [];
            $m['ferramentas'] = ['dump' => 'arquivo', 'gzip' => 'gzip', 'tar' => 'tar'];
            return $m;
        }
        if ($o['caminho'] === '') {
            return ['ok' => false, 'erros' => ['Informe a pasta do GLPI na origem (ex.: /var/www/glpi).'], 'avisos' => []];
        }
        $cmd = 'php -d display_errors=0 -d memory_limit=-1 -- ' . escapeshellarg($o['caminho']) . ($contar ? ' contar' : '');
        [$cod, $out, $err] = PluginBackupeatualizacaoglpiSsh::executar($o, $cmd, self::script(), $contar ? 1800 : 300);
        $j = json_decode(trim($out), true);
        if (!is_array($j)) {
            $msg = trim($err) !== '' ? trim($err) : (trim($out) !== '' ? mb_strimwidth(trim($out), 0, 300, '…') : 'sem resposta');
            if (str_contains($msg, 'command not found') || str_contains($msg, 'php: not found')) {
                $msg = 'o PHP de linha de comando não está instalado na origem.';
            }
            return ['ok' => false, 'erros' => ['Falha no reconhecimento da origem: ' . $msg], 'avisos' => []];
        }
        return $j;
    }

    /** Versão da análise sem a senha do banco (para exibir e guardar) */
    public static function semSegredos(array $a): array
    {
        if (isset($a['banco']['senha'])) {
            $a['banco']['senha'] = $a['banco']['senha'] !== '' ? '••••' : '';
        }
        return $a;
    }

    // ------------------------------------------------------------------ fluxos de dados (para o executor)

    /** Comando que escreve na saída o dump do banco da origem já compactado (gzip) */
    public static function comandoDump(array $o, array $a): string
    {
        if ($o['tipo'] === 'arquivo') {
            return 'cat ' . escapeshellarg(self::pastaBackup($o) . '/banco.sql.gz');
        }
        $b = $a['banco'];
        $cnf = "[client]\nuser=\"" . addcslashes((string) $b['usuario'], "\"\\") . "\"\npassword=\"" . addcslashes((string) $b['senha'], "\"\\") . "\"\nhost=\"" . addcslashes((string) $b['host'], "\"\\") . "\"\n" . ((int) $b['porta'] > 0 ? 'port=' . (int) $b['porta'] . "\n" : '');
        $dump = (string) $a['ferramentas']['dump'];
        $remoto = 'umask 077; C=$(mktemp); printf %s ' . escapeshellarg(base64_encode($cnf)) . ' | base64 -d > "$C"; '
            . escapeshellarg($dump) . ' --defaults-extra-file="$C" --single-transaction --quick --hex-blob --no-tablespaces --default-character-set=utf8mb4 ' . escapeshellarg((string) $b['banco'])
            . ' | gzip -c; R=$?; rm -f "$C"; exit $R';
        return PluginBackupeatualizacaoglpiSsh::comando($o, $remoto);
    }

    /** Comando que escreve um .tar.gz de uma pasta da origem (files, documentos, plugins, marketplace, config) */
    public static function comandoPasta(array $o, array $a, string $tipo, array $excluir = [], array $somente = []): string
    {
        if ($o['tipo'] === 'arquivo') {
            return 'cat ' . escapeshellarg(self::pastaBackup($o) . '/' . $tipo . '.tar.gz');
        }
        $dir = (string) ($a['caminhos'][$tipo] ?? '');
        $args = '';
        foreach ($excluir as $x) {
            $args .= ' --exclude=' . escapeshellarg('./' . ltrim($x, './'));
        }
        $itens = $somente ? implode(' ', array_map(static fn($s) => escapeshellarg('./' . $s), $somente)) : '.';
        $remoto = 'test -d ' . escapeshellarg($dir) . ' || exit 0; tar -C ' . escapeshellarg($dir) . ' -czf -' . $args . ' ' . $itens;
        return PluginBackupeatualizacaoglpiSsh::comando($o, $remoto);
    }

    /** Conteúdo de um arquivo da pasta config da origem (chaves) */
    public static function arquivoConfig(array $o, array $a, string $nome): ?string
    {
        if ($o['tipo'] === 'arquivo') {
            [$cod, $out] = PluginBackupeatualizacaoglpiConfig::executar('tar -xzf ' . escapeshellarg(self::pastaBackup($o) . '/config.tar.gz') . ' -O ' . escapeshellarg('./' . $nome) . ' | base64 -w0');
            $bin = $cod === 0 ? base64_decode(trim($out), true) : false;
            return ($bin === false || $bin === '') ? null : $bin;
        }
        return PluginBackupeatualizacaoglpiSsh::lerArquivo($o, rtrim((string) $a['caminhos']['config'], '/') . '/' . $nome);
    }
}
