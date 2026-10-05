<?php

/**
 * Plugin Backup e Atualização GLPI - download de uma parte de um backup (somente administradores)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginBackupeatualizacaoglpiConfig::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$nome = (string) ($_GET['backup'] ?? '');
$arq = (string) ($_GET['arquivo'] ?? '');
$B = PluginBackupeatualizacaoglpiBackup::class;
if (!$B::nomeValido($nome) || !array_key_exists($arq, $B::ARQUIVOS) || !is_file($B::pasta($nome) . '/' . $arq)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$caminho = $B::pasta($nome) . '/' . $arq;

while (ob_get_level() > 0) {
    ob_end_clean();
}
session_write_close();
header('Content-Type: ' . (str_ends_with($arq, '.json') ? 'application/json' : 'application/gzip'));
header('Content-Disposition: attachment; filename="' . $nome . '_' . $arq . '"');
header('Content-Length: ' . filesize($caminho));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$h = fopen($caminho, 'rb');
while (!feof($h)) {
    echo fread($h, 1048576);
    flush();
}
fclose($h);
exit;
