<?php

/**
 * Plugin Backup e Atualização GLPI - processo de linha de comando que executa uma tarefa
 * Uso: php plugins/backupeatualizacaoglpi/inc/trabalhador.php <id-da-tarefa>
 * É iniciado pela tela (em segundo plano) ou pela tarefa automática de backup. Lê tudo o que precisa do GLPI
 * no começo; depois que o banco é substituído, só usa comandos de shell, conexão própria e o bin/console.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente pela linha de comando.');
}

$id = (string) ($argv[1] ?? '');
if (!preg_match('/^[a-z0-9_-]+$/i', $id)) {
    fwrite(STDERR, "Informe o id da tarefa.\n");
    exit(2);
}

$raiz = dirname(__DIR__, 3);
chdir($raiz);
require $raiz . '/vendor/autoload.php';
try {
    $kernel = new \Glpi\Kernel\Kernel('production');
    $kernel->boot();
} catch (\Throwable $e) {
    // Banco em transição (ex.: reversão depois de uma falha): segue só com as pastas guardadas na tarefa
    $base = dirname(__DIR__, 3) . '/files/_plugins/backupeatualizacaoglpi/tarefas/' . $id . '/estado.json';
    $amb = is_file($base) ? (json_decode((string) file_get_contents($base), true)['ambiente'] ?? []) : [];
    foreach (['GLPI_ROOT' => $raiz, 'GLPI_CONFIG_DIR' => $amb['config'] ?? $raiz . '/config', 'GLPI_VAR_DIR' => $amb['files'] ?? $raiz . '/files'] as $c => $v) {
        if (!defined($c)) {
            define($c, $v);
        }
    }
    if (!defined('GLPI_PLUGIN_DOC_DIR')) {
        define('GLPI_PLUGIN_DOC_DIR', GLPI_VAR_DIR . '/_plugins');
    }
}
// As classes do plugin são carregadas aqui: depois da troca do banco o plugin não aparece como ativo
foreach (glob(__DIR__ . '/*.class.php') as $classe) {
    require_once $classe;
}

$t = PluginBackupeatualizacaoglpiTarefa::carregar($id);
if (!$t) {
    fwrite(STDERR, "Tarefa $id não encontrada.\n");
    exit(2);
}
$t['pid'] = getmypid();
PluginBackupeatualizacaoglpiTarefa::salvar($t);

try {
    $ex = new PluginBackupeatualizacaoglpiExecutor($t);
    $ex->rodar();
} catch (\Throwable $e) {
    $t = PluginBackupeatualizacaoglpiTarefa::carregar($id) ?? $t;
    $t['situacao'] = 'erro';
    $t['fim'] = date('Y-m-d H:i:s');
    $t['resultado']['erro'] = $e->getMessage();
    PluginBackupeatualizacaoglpiTarefa::salvar($t);
    PluginBackupeatualizacaoglpiTarefa::log($t, 'ERRO inesperado: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    exit(1);
}
exit(0);
