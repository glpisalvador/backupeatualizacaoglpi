<?php

/**
 * Plugin Backup e Atualização GLPI - endpoints AJAX (JSON): origem, análise, início, acompanhamento, backups
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > 0) {
    ob_end_clean();
}

register_shutdown_function(static function (): void {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'Erro interno ao processar a solicitação.']);
    }
});

function bkg_responder(array $dados): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = PluginBackupeatualizacaoglpiConfig::tokenCsrf();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Session::getLoginUserID()) {
    bkg_responder(['success' => false, 'sem_sessao' => true, 'message' => 'Sessão encerrada.']);
}
if (!PluginBackupeatualizacaoglpiConfig::podeUsar()) {
    bkg_responder(['success' => false, 'message' => 'Sem permissão.']);
}

$C = PluginBackupeatualizacaoglpiConfig::class;
$T = PluginBackupeatualizacaoglpiTarefa::class;
$O = PluginBackupeatualizacaoglpiOrigem::class;
$acao = (string) ($_REQUEST['action'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!$post && $acao !== 'tarefa') {
    bkg_responder(['success' => false, 'message' => 'Método não permitido.']);
}

$origemPost = static function () use ($O): array {
    $o = $O::deEntrada($_POST);
    if ($o['tipo'] === 'arquivo' && !PluginBackupeatualizacaoglpiBackup::nomeValido($o['backup'])) {
        bkg_responder(['success' => false, 'message' => 'Escolha um backup.']);
    }
    if ($o['tipo'] === 'ssh' && ($o['host'] === '' || $o['usuario'] === '' || !preg_match('/^[A-Za-z0-9_.:\[\]-]+$/', $o['host']) || !preg_match('/^[A-Za-z0-9_.-]+$/', $o['usuario']))) {
        bkg_responder(['success' => false, 'message' => 'Informe um servidor e um usuário válidos.']);
    }
    if ($o['tipo'] !== 'arquivo' && ($o['caminho'] === '' || $o['caminho'][0] !== '/')) {
        bkg_responder(['success' => false, 'message' => 'Informe a pasta do GLPI na origem (caminho absoluto).']);
    }
    return $o;
};

switch ($acao) {
    case 'testar':
        $o = $origemPost();
        [$ok, $msg] = PluginBackupeatualizacaoglpiSsh::testar($o);
        bkg_responder(['success' => $ok, 'message' => $msg]);

    case 'analisar':
        @set_time_limit(600);
        session_write_close();
        $o = $origemPost();
        $a = $O::analisar($o);
        $amb = $C::ambiente();
        $plano = $a['ok'] ?? false ? PluginBackupeatualizacaoglpiPlano::gerar($o, $a, $amb) : ['bloqueios' => $a['erros'] ?? [], 'avisos' => [], 'info' => [], 'etapas' => [], 'flags' => []];
        bkg_responder([
            'success'   => (bool) ($a['ok'] ?? false),
            'message'   => ($a['ok'] ?? false) ? 'Origem analisada: GLPI ' . $a['versao'] . '.' : implode(' ', $a['erros'] ?? ['Falha na análise.']),
            'html'      => PluginBackupeatualizacaoglpiPlano::exibir($o, $a, $plano, $amb),
            'bloqueado' => !empty($plano['bloqueios']) || !($a['ok'] ?? false),
        ]);

    case 'salvar_origem':
        $o = $origemPost();
        $lista = $C::getArrayConfig('origens');
        $chave = static fn($x) => ($x['tipo'] ?? '') . '|' . ($x['host'] ?? '') . '|' . ($x['porta'] ?? '') . '|' . ($x['usuario'] ?? '') . '|' . ($x['caminho'] ?? '') . '|' . ($x['backup'] ?? '');
        $lista = array_values(array_filter($lista, static fn($x) => $chave($x) !== $chave($o)));
        array_unshift($lista, $o);
        $C::setArrayConfig('origens', array_slice($lista, 0, 20));
        bkg_responder(['success' => true, 'message' => 'Origem salva.']);

    case 'remover_origem':
        $lista = $C::getArrayConfig('origens');
        unset($lista[(int) ($_POST['indice'] ?? -1)]);
        $C::setArrayConfig('origens', $lista);
        bkg_responder(['success' => true, 'message' => 'Origem removida.']);

    case 'iniciar_migracao':
        @set_time_limit(600);
        if ($ativa = $T::ativa()) {
            bkg_responder(['success' => false, 'message' => 'Já existe uma tarefa em andamento.', 'tarefa' => $ativa['id']]);
        }
        if (empty($_POST['entendi'])) {
            bkg_responder(['success' => false, 'message' => 'Confirme que entendeu que os dados deste GLPI serão substituídos.']);
        }
        $o = $origemPost();
        $a = $O::analisar($o);
        $amb = $C::ambiente();
        $plano = PluginBackupeatualizacaoglpiPlano::gerar($o, $a, $amb);
        if (!($a['ok'] ?? false) || $plano['bloqueios']) {
            bkg_responder(['success' => false, 'message' => 'A migração está bloqueada: ' . implode(' ', $plano['bloqueios'] ?: ($a['erros'] ?? []))]);
        }
        $exclusoes = array_values(array_filter(array_map(static fn($x) => trim($x, " /\t"), explode(',', (string) ($_POST['exclusoes'] ?? ''))), static fn($x) => (bool) preg_match('/^[A-Za-z0-9_.-]+$/', $x)));
        $etapas = array_map(static fn($e) => $e + ['situacao' => 'pendente'], $plano['etapas']);
        $C::salvarInstantaneo();
        $t = $T::criar('migracao', [
            'ambiente' => $amb,
            'origem'   => $o,
            'analise'  => $a,
            'plano'    => ['flags' => $plano['flags'], 'avisos' => $plano['avisos']],
            'etapas'   => $etapas,
            'opcoes'   => [
                'manter_url'          => !empty($_POST['manter_url']),
                'limpar_fila'         => !empty($_POST['limpar_fila']),
                'desativar_coletores' => !empty($_POST['desativar_coletores']),
                'exclusoes'           => $exclusoes,
            ],
        ]);
        $t = $T::iniciar($t);
        if ((int) $t['pid'] <= 0) {
            bkg_responder(['success' => false, 'message' => 'Não foi possível iniciar o processo em segundo plano. Veja ' . $T::pasta($t['id']) . '/saida.txt']);
        }
        bkg_responder(['success' => true, 'message' => 'Migração iniciada.', 'url' => $C::url('tarefa.php', ['id' => $t['id']])]);

    case 'tarefa':
        session_write_close();
        $id = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_REQUEST['id'] ?? ''));
        $t = $T::carregar($id);
        if (!$t) {
            bkg_responder(['success' => false, 'message' => 'Tarefa não encontrada.']);
        }
        if (in_array($t['situacao'], ['aguardando', 'executando'], true) && !$T::vivo($t)) {
            // O processo morreu sem registrar o fim (ex.: servidor reiniciado)
            $t['situacao'] = 'erro';
            $t['fim'] = date('Y-m-d H:i:s');
            $t['resultado']['erro'] = 'O processo da tarefa parou de responder. Veja o registro e ' . $T::pasta($id) . '/saida.txt.';
            $T::salvar($t);
            $T::log($t, 'O processo da tarefa não está mais rodando.');
        }
        [$log, $tam] = $T::lerLog($id, (int) ($_REQUEST['desde'] ?? 0));
        $duracao = empty($t['inicio']) ? '—' : $C::duracao(max(0, (empty($t['fim']) ? time() : strtotime($t['fim'])) - strtotime($t['inicio'])));
        [$rot, $cls] = $T::situacoes()[$t['situacao']] ?? [$t['situacao'], 'neutro'];
        bkg_responder([
            'success'   => true,
            'situacao'  => $t['situacao'],
            'rotulo'    => $rot,
            'classe'    => $cls,
            'rodando'   => in_array($t['situacao'], ['aguardando', 'executando'], true),
            'etapas'    => array_map(static fn($e) => ['chave' => $e['chave'], 'titulo' => $e['titulo'], 'situacao' => $e['situacao'] ?? 'pendente', 'detalhe' => $e['detalhe'] ?? ''], $t['etapas']),
            'progresso' => (string) ($t['progresso'] ?? ''),
            'duracao'   => $duracao,
            'log'       => $log,
            'tamanho'   => $tam,
        ]);

    case 'cancelar':
        $ok = $T::cancelar((string) ($_POST['id'] ?? ''));
        bkg_responder(['success' => $ok, 'message' => $ok ? 'Cancelamento pedido: a tarefa para ao fim da etapa atual (e reverte o que já tiver mudado).' : 'A tarefa não está em andamento.']);

    case 'reverter':
        $alvo = $T::carregar((string) ($_POST['id'] ?? ''));
        if (!$alvo || $alvo['tipo'] !== 'migracao' || empty($alvo['resultado']['reversao_disponivel']) || !empty($alvo['resultado']['revertido'])) {
            bkg_responder(['success' => false, 'message' => 'Esta migração não pode ser revertida.']);
        }
        if ($T::ativa()) {
            bkg_responder(['success' => false, 'message' => 'Já existe uma tarefa em andamento.']);
        }
        $t = $T::criar('reversao', ['ambiente' => $alvo['ambiente'], 'alvo' => $alvo['id']]);
        $t = $T::iniciar($t);
        bkg_responder(['success' => true, 'message' => 'Reversão iniciada.', 'url' => $C::url('tarefa.php', ['id' => $t['id']])]);

    case 'excluir_tarefa':
        $ok = $T::excluir((string) ($_POST['id'] ?? ''));
        bkg_responder(['success' => $ok, 'message' => $ok ? 'Tarefa excluída.' : 'Não foi possível excluir (a tarefa está em andamento?).', 'url' => $C::url('tarefa.php')]);

    case 'backup_iniciar':
        $r = PluginBackupeatualizacaoglpiBackup::iniciar(false, (string) ($_POST['rotulo'] ?? ''));
        bkg_responder(['success' => $r['ok'], 'message' => $r['mensagem'], 'url' => !empty($r['tarefa']) ? $C::url('tarefa.php', ['id' => $r['tarefa']]) : '']);

    case 'backup_excluir':
        $ok = PluginBackupeatualizacaoglpiBackup::excluir((string) ($_POST['backup'] ?? ''));
        bkg_responder(['success' => $ok, 'message' => $ok ? 'Backup excluído.' : 'Não foi possível excluir o backup.']);
}

bkg_responder(['success' => false, 'message' => 'Ação desconhecida.']);
