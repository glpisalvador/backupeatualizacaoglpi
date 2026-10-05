<?php

/**
 * Plugin Backup e Atualização GLPI - execução das tarefas (no processo de linha de comando)
 * Migração: backup deste GLPI → banco, chaves, arquivos e plugins da origem → db:update oficial →
 * conversões para o núcleo → migrações recomendadas → conferência. Falha antes da conferência reverte sozinha.
 */
class PluginBackupeatualizacaoglpiExecutor
{
    private array $t;
    private string $dir;
    private array $amb;
    private array $db;
    private string $cred = '';

    /** Etapas cuja falha não interrompe a migração (o banco já está atualizado e utilizável) */
    private const NAO_CRITICAS = ['genericobject', 'formcreator', 'migracoes', 'verificar'];

    public function __construct(array $t)
    {
        $this->t = $t;
        $this->dir = PluginBackupeatualizacaoglpiTarefa::pasta($t['id']);
        $this->amb = $t['ambiente'];
        $this->db = PluginBackupeatualizacaoglpiConfig::lerConfigDb($this->amb['config'] . '/config_db.php');
    }

    private function salvar(): void
    {
        PluginBackupeatualizacaoglpiTarefa::salvar($this->t);
    }

    private function log(string $m): void
    {
        PluginBackupeatualizacaoglpiTarefa::log($this->t, $m);
    }

    private function etapa(string $chave, string $situacao, string $detalhe = ''): void
    {
        foreach ($this->t['etapas'] as &$e) {
            if ($e['chave'] === $chave) {
                $e['situacao'] = $situacao;
                if ($situacao === 'executando') {
                    $e['inicio'] = date('Y-m-d H:i:s');
                } elseif (in_array($situacao, ['ok', 'erro', 'aviso', 'pulada'], true)) {
                    $e['fim'] = date('Y-m-d H:i:s');
                }
                if ($detalhe !== '') {
                    $e['detalhe'] = $detalhe;
                }
            }
        }
        unset($e);
        $this->t['atual'] = $situacao === 'executando' ? $chave : $this->t['atual'];
        $this->salvar();
    }

    private function progresso(string $texto): void
    {
        $this->t['progresso'] = $texto;
        $this->salvar();
    }

    /** Roda um comando; lança exceção com o erro se falhar */
    private function sh(string $cmd, string $acompanhar = '', int $limite = 0): string
    {
        $cb = null;
        if ($acompanhar !== '') {
            $cb = function () use ($acompanhar) {
                clearstatcache(true, $acompanhar);
                $this->progresso(PluginBackupeatualizacaoglpiConfig::tamanho((float) @filesize($acompanhar)) . ' transferidos');
            };
        }
        [$cod, $out, $err] = PluginBackupeatualizacaoglpiConfig::executar($cmd, null, $limite, $cb);
        $this->t['progresso'] = '';
        if ($cod !== 0) {
            $msg = trim($err) !== '' ? trim($err) : trim($out);
            throw new RuntimeException(mb_strimwidth($msg !== '' ? $msg : 'código de saída ' . $cod, 0, 1500, '…'));
        }
        return $out;
    }

    private function console(string $args, int $limite = 0): array
    {
        $cmd = 'cd ' . escapeshellarg($this->amb['raiz']) . ' && ' . escapeshellarg($this->amb['php_cli']) . ' -d memory_limit=-1 bin/console ' . $args . ' --no-ansi';
        [$cod, $out, $err] = PluginBackupeatualizacaoglpiConfig::executar($cmd, null, $limite);
        $texto = trim($out . "\n" . $err);
        $this->log('$ bin/console ' . $args . ' (código ' . $cod . ")\n" . mb_strimwidth($texto, 0, 20000, "\n…"));
        return [$cod, $texto];
    }

    private function conexao(): mysqli
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = @mysqli_connect($this->db['host'], $this->db['usuario'], $this->db['senha'], $this->db['banco'], $this->db['porta'] ?: 3306);
        if (!$c) {
            throw new RuntimeException('Sem conexão com o banco deste GLPI: ' . mysqli_connect_error());
        }
        mysqli_set_charset($c, 'utf8mb4');
        return $c;
    }

    private function credenciais(): string
    {
        if ($this->cred === '' || !is_file($this->cred)) {
            $this->cred = PluginBackupeatualizacaoglpiConfig::arquivoCredenciais($this->db, $this->dir);
        }
        return $this->cred;
    }

    public function __destruct()
    {
        if ($this->cred !== '' && is_file($this->cred)) {
            @unlink($this->cred);
        }
    }

    // =====================================================================
    // Execução
    // =====================================================================

    public function rodar(): void
    {
        $this->t['situacao'] = 'executando';
        $this->t['inicio'] = date('Y-m-d H:i:s');
        $this->salvar();
        match ($this->t['tipo']) {
            'migracao' => $this->migrar(),
            'backup'   => $this->backup(),
            'reversao' => $this->reverterTarefa(),
            default    => throw new RuntimeException('Tipo de tarefa desconhecido.'),
        };
    }

    private function migrar(): void
    {
        $avisos = 0;
        foreach ($this->t['etapas'] as $e) {
            $k = $e['chave'];
            if (PluginBackupeatualizacaoglpiTarefa::pedidoCancelamento($this->t['id'])) {
                $this->log('Cancelada antes da etapa "' . $e['titulo'] . '".');
                if (!empty($this->t['resultado']['importado'])) {
                    $this->reverter('cancelamento');
                }
                $this->encerrar('cancelada');
                return;
            }
            $this->etapa($k, 'executando');
            $this->log('== ' . $e['titulo']);
            $inicio = time();
            try {
                $detalhe = (string) $this->{'etapa_' . $k}();
                $this->etapa($k, 'ok', $detalhe);
                $this->log('ok em ' . PluginBackupeatualizacaoglpiConfig::duracao(time() - $inicio) . ($detalhe !== '' ? ': ' . $detalhe : ''));
            } catch (\Throwable $ex) {
                $this->log('FALHA: ' . $ex->getMessage());
                if (in_array($k, self::NAO_CRITICAS, true)) {
                    $this->etapa($k, 'aviso', $ex->getMessage());
                    $avisos++;
                    continue;
                }
                $this->etapa($k, 'erro', $ex->getMessage());
                $this->t['resultado']['erro'] = $e['titulo'] . ': ' . $ex->getMessage();
                if (!empty($this->t['resultado']['importado'])) {
                    $this->reverter('falha na etapa "' . $e['titulo'] . '"');
                    $this->encerrar('erro');
                    return;
                }
                $this->encerrar('erro');
                return;
            }
        }
        $this->encerrar($avisos > 0 || !empty($this->t['resultado']['verificacao']['menores']) ? 'aviso' : 'concluida');
    }

    private function encerrar(string $situacao): void
    {
        $this->t['situacao'] = $situacao;
        $this->t['fim'] = date('Y-m-d H:i:s');
        $this->t['atual'] = '';
        $this->t['progresso'] = '';
        // A senha do banco da origem só fica na tarefa enquanto ela roda
        if (isset($this->t['analise']['banco']['senha'])) {
            $this->t['analise']['banco']['senha'] = '';
        }
        $this->salvar();
        $this->log('Tarefa terminada: ' . (PluginBackupeatualizacaoglpiTarefa::situacoes()[$situacao][0] ?? $situacao) . '.');
    }

    // =====================================================================
    // Etapas da migração
    // =====================================================================

    private function etapa_backup_destino(): string
    {
        $amb = $this->amb;
        $this->sh(escapeshellarg($amb['dump']) . ' --defaults-extra-file=' . escapeshellarg($this->credenciais()) . ' --single-transaction --quick --hex-blob --no-tablespaces --default-character-set=utf8mb4 ' . escapeshellarg($this->db['banco']) . ' | gzip -c > ' . escapeshellarg($this->dir . '/destino_banco.sql.gz'), $this->dir . '/destino_banco.sql.gz');
        $this->sh('tar -C ' . escapeshellarg($amb['config']) . ' -czf ' . escapeshellarg($this->dir . '/destino_config.tar.gz') . ' .');
        $excl = '';
        foreach (['_cache', '_sessions', '_tmp', '_plugins/' . PluginBackupeatualizacaoglpiConfig::PLUGIN] as $x) {
            $excl .= ' --exclude=' . escapeshellarg('./' . $x);
        }
        $this->sh('tar -C ' . escapeshellarg($amb['files']) . ' -czf ' . escapeshellarg($this->dir . '/destino_files.tar.gz') . $excl . ' .', $this->dir . '/destino_files.tar.gz');
        $this->t['resultado']['reversao_disponivel'] = true;
        return 'banco ' . PluginBackupeatualizacaoglpiConfig::tamanho((float) filesize($this->dir . '/destino_banco.sql.gz')) . ', arquivos ' . PluginBackupeatualizacaoglpiConfig::tamanho((float) filesize($this->dir . '/destino_files.tar.gz'));
    }

    private function etapa_contar_origem(): string
    {
        $a = PluginBackupeatualizacaoglpiOrigem::analisar($this->t['origem'], true);
        if (!($a['ok'] ?? false)) {
            throw new RuntimeException(implode(' ', $a['erros'] ?? ['falha no reconhecimento']));
        }
        $this->t['analise']['contagens'] = $a['contagens'];
        file_put_contents($this->dir . '/contagens_origem.json', json_encode($a['contagens']));
        return count($a['contagens']) . ' tabelas, ' . number_format(array_sum($a['contagens']), 0, ',', '.') . ' registros';
    }

    private function etapa_dump(): string
    {
        $arq = $this->dir . '/origem_banco.sql.gz';
        $this->sh(PluginBackupeatualizacaoglpiOrigem::comandoDump($this->t['origem'], $this->t['analise']) . ' > ' . escapeshellarg($arq), $arq);
        $this->sh('gzip -t ' . escapeshellarg($arq));
        if (filesize($arq) < 1000) {
            throw new RuntimeException('O dump da origem veio vazio.');
        }
        if ($this->t['origem']['tipo'] !== 'arquivo') {
            $this->t['analise']['banco']['senha'] = '';
        }
        return PluginBackupeatualizacaoglpiConfig::tamanho((float) filesize($arq)) . ' compactados';
    }

    private function limparBanco(): int
    {
        $c = $this->conexao();
        $q = mysqli_query($c, 'SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = DATABASE()');
        $tabelas = [];
        $views = [];
        while ($q && ($l = mysqli_fetch_row($q))) {
            if ($l[1] === 'VIEW') {
                $views[] = $l[0];
            } else {
                $tabelas[] = $l[0];
            }
        }
        mysqli_query($c, 'SET FOREIGN_KEY_CHECKS = 0');
        foreach ($views as $v) {
            mysqli_query($c, 'DROP VIEW IF EXISTS `' . str_replace('`', '``', $v) . '`');
        }
        foreach (array_chunk($tabelas, 50) as $lote) {
            if (!mysqli_query($c, 'DROP TABLE IF EXISTS ' . implode(', ', array_map(static fn($x) => '`' . str_replace('`', '``', $x) . '`', $lote)))) {
                throw new RuntimeException('Não foi possível limpar o banco: ' . mysqli_error($c));
            }
        }
        mysqli_close($c);
        return count($tabelas);
    }

    private function importarDump(string $arq): void
    {
        $sed = "sed -E -e 's/utf8mb4_0900_[a-z_]+/utf8mb4_unicode_ci/g' -e 's#/\\*!80016 DEFAULT ENCRYPTION=.N. \\*/##g'";
        $this->sh('gzip -dc ' . escapeshellarg($arq) . ' | ' . $sed . ' | ' . escapeshellarg($this->amb['cliente']) . ' --defaults-extra-file=' . escapeshellarg($this->credenciais()) . ' --default-character-set=utf8mb4 --max-allowed-packet=1G ' . escapeshellarg($this->db['banco']));
    }

    private function etapa_importar(): string
    {
        $n = $this->limparBanco();
        $this->t['resultado']['importado'] = true;
        $this->salvar();
        $this->log($n . ' tabelas deste GLPI removidas; importando o banco da origem.');
        $this->importarDump($this->dir . '/origem_banco.sql.gz');
        // Restos deste plugin vindos da origem (versão antiga) não podem atrapalhar a reinstalação
        $c = $this->conexao();
        $q = mysqli_query($c, "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'glpi\\_plugin\\_backupeatualizacaoglpi\\_%'");
        while ($q && ($l = mysqli_fetch_row($q))) {
            mysqli_query($c, 'DROP TABLE `' . str_replace('`', '``', $l[0]) . '`');
        }
        @mysqli_query($c, "DELETE FROM glpi_plugins WHERE directory = '" . PluginBackupeatualizacaoglpiConfig::PLUGIN . "'");
        $q = mysqli_query($c, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $total = $q ? (int) mysqli_fetch_row($q)[0] : 0;
        $ajustes = $this->ajustesPosImportacao($c);
        mysqli_close($c);
        return $total . ' tabelas importadas' . ($ajustes ? '; ' . implode('; ', $ajustes) : '');
    }

    /** Ajustes escolhidos na tela para o banco recém-importado não agir como se fosse o servidor antigo */
    private function ajustesPosImportacao(mysqli $c): array
    {
        $op = $this->t['opcoes'] ?? [];
        $feitos = [];
        if (!empty($op['manter_url'])) {
            foreach (['url_base', 'url_base_api'] as $nome) {
                $valor = (string) ($this->amb[$nome] ?? '');
                if ($valor === '') {
                    continue;
                }
                $st = mysqli_prepare($c, "UPDATE glpi_configs SET value = ? WHERE context = 'core' AND name = ?");
                mysqli_stmt_bind_param($st, 'ss', $valor, $nome);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
            $feitos[] = 'endereço deste GLPI mantido (' . ($this->amb['url_base'] ?? '') . ')';
        }
        if (!empty($op['limpar_fila'])) {
            if (@mysqli_query($c, 'DELETE FROM glpi_queuednotifications WHERE sent_time IS NULL')) {
                $feitos[] = mysqli_affected_rows($c) . ' e-mail(s) pendentes da origem descartados da fila';
            }
        }
        if (!empty($op['desativar_coletores'])) {
            if (@mysqli_query($c, 'UPDATE glpi_mailcollectors SET is_active = 0 WHERE is_active = 1')) {
                $n = mysqli_affected_rows($c);
                $this->t['resultado']['coletores_desativados'] = $n;
                $feitos[] = $n . ' coletor(es) de e-mail desativado(s)';
            }
        }
        return $feitos;
    }

    private function etapa_chaves(): string
    {
        $levadas = [];
        foreach (['glpicrypt.key', 'glpi.key', 'oauth.pem', 'oauth.pub'] as $k) {
            if (empty($this->t['analise']['chaves'][$k])) {
                continue;
            }
            $conteudo = PluginBackupeatualizacaoglpiOrigem::arquivoConfig($this->t['origem'], $this->t['analise'], $k);
            if ($conteudo === null) {
                throw new RuntimeException('Não foi possível ler ' . $k . ' da origem.');
            }
            $destino = $this->amb['config'] . '/' . $k;
            if (file_put_contents($destino, $conteudo) === false) {
                throw new RuntimeException('Não foi possível gravar ' . $destino . '.');
            }
            @chmod($destino, str_starts_with($k, 'oauth') ? 0660 : 0640);
            $levadas[] = $k;
        }
        $local = PluginBackupeatualizacaoglpiOrigem::arquivoConfig($this->t['origem'], $this->t['analise'], 'local_define.php');
        if ($local !== null) {
            file_put_contents($this->dir . '/origem_local_define.php.txt', $local);
            $this->log('A origem tinha config/local_define.php: guardado na tarefa para conferência (não foi aplicado aqui).');
        }
        return $levadas ? implode(', ', $levadas) : 'a origem não tinha chaves';
    }

    private function etapa_arquivos(): string
    {
        $excl = array_values(array_unique(array_merge($this->t['opcoes']['exclusoes'] ?? [], ['_plugins/' . PluginBackupeatualizacaoglpiConfig::PLUGIN, '_sessions', '_cache', '_tmp'])));
        $o = $this->t['origem'];
        $a = $this->t['analise'];
        $tmpTam = $this->dir . '/.copiando';
        $this->sh(PluginBackupeatualizacaoglpiOrigem::comandoPasta($o, $a, 'files', $excl) . ' | tee >(wc -c > ' . escapeshellarg($tmpTam) . ') | tar -C ' . escapeshellarg($this->amb['files']) . ' -xzf - --no-same-owner --no-same-permissions');
        $docsOrigem = rtrim((string) ($a['caminhos']['documentos'] ?? ''), '/');
        $filesOrigem = rtrim((string) ($a['caminhos']['files'] ?? ''), '/');
        $extra = '';
        if ($o['tipo'] !== 'arquivo' && $docsOrigem !== '' && $docsOrigem !== $filesOrigem && !str_starts_with($docsOrigem . '/', $filesOrigem . '/')) {
            $this->sh(PluginBackupeatualizacaoglpiOrigem::comandoPasta($o, $a, 'documentos') . ' | tar -C ' . escapeshellarg($this->amb['documentos']) . ' -xzf - --no-same-owner --no-same-permissions');
            $extra = ' (e a pasta de documentos separada)';
        }
        $copiado = is_file($tmpTam) ? (int) trim((string) file_get_contents($tmpTam)) : 0;
        @unlink($tmpTam);
        return PluginBackupeatualizacaoglpiConfig::tamanho((float) $copiado) . ' compactados copiados' . $extra . '; fora: ' . implode(', ', $excl);
    }

    private function etapa_plugins(): string
    {
        $o = $this->t['origem'];
        $a = $this->t['analise'];
        $aqui = [];
        foreach ([$this->amb['plugins'], $this->amb['marketplace']] as $d) {
            foreach ((array) @scandir($d) as $n) {
                if ($n !== '.' && $n !== '..' && is_dir($d . '/' . $n)) {
                    $aqui[$n] = true;
                }
            }
        }
        $copiados = ['plugins' => [], 'marketplace' => []];
        $mantidos = [];
        foreach (['plugins', 'marketplace'] as $tipo) {
            $nomes = [];
            foreach ($a['pastas_plugins'][$tipo] ?? [] as $n) {
                if ($n === PluginBackupeatualizacaoglpiConfig::PLUGIN || !preg_match('/^[A-Za-z0-9_.-]+$/', $n)) {
                    continue;
                }
                if (isset($aqui[$n])) {
                    $mantidos[] = $n;
                    continue;
                }
                $nomes[] = $n;
            }
            if (!$nomes) {
                continue;
            }
            $destino = $this->amb[$tipo];
            if (!is_dir($destino)) {
                @mkdir($destino, 0750, true);
            }
            if ($o['tipo'] === 'arquivo') {
                $tmp = $this->dir . '/tmp_' . $tipo;
                @mkdir($tmp, 0750, true);
                $this->sh('tar -C ' . escapeshellarg($tmp) . ' -xzf ' . escapeshellarg(PluginBackupeatualizacaoglpiOrigem::pastaBackup($o) . '/' . $tipo . '.tar.gz'));
                foreach ($nomes as $n) {
                    if (is_dir($tmp . '/' . $n)) {
                        $this->sh('mv ' . escapeshellarg($tmp . '/' . $n) . ' ' . escapeshellarg($destino . '/' . $n));
                        $copiados[$tipo][] = $n;
                    }
                }
                $this->sh('rm -rf ' . escapeshellarg($tmp));
            } else {
                $this->sh(PluginBackupeatualizacaoglpiOrigem::comandoPasta($o, $a, $tipo, [], $nomes) . ' | tar -C ' . escapeshellarg($destino) . ' -xzf - --no-same-owner');
                $copiados[$tipo] = $nomes;
            }
        }
        $this->t['resultado']['plugins_copiados'] = $copiados;
        $this->t['resultado']['plugins_mantidos'] = array_values(array_unique($mantidos));
        $n = count($copiados['plugins']) + count($copiados['marketplace']);
        return $n . ' pasta(s) copiada(s)' . ($mantidos ? '; mantidas as que já existiam aqui: ' . implode(', ', array_unique($mantidos)) : '');
    }

    private function etapa_desativar(): string
    {
        $c = $this->conexao();
        $estados = [];
        $q = mysqli_query($c, 'SELECT directory, state FROM glpi_plugins');
        while ($q && ($l = mysqli_fetch_row($q))) {
            $estados[$l[0]] = (int) $l[1];
        }
        mysqli_query($c, 'UPDATE glpi_plugins SET state = 4 WHERE state = 1');
        $n = mysqli_affected_rows($c);
        mysqli_close($c);
        $this->t['resultado']['plugins_estado_origem'] = $estados;
        return $n . ' plugin(s) que estavam ativos foram desativados';
    }

    private function etapa_atualizar(): string
    {
        $base = 'db:update -n --no-telemetry' . (!empty($this->t['plano']['flags']['instavel']) ? ' --allow-unstable' : '');
        [$cod, $out] = $this->console($base);
        if ($cod !== 0 && preg_match('/integrit|integridad|esquema|schema|diferen/i', $out)) {
            $this->log('A verificação de integridade do esquema apontou diferenças (comum em bancos antigos com alterações de plugins). Repetindo sem a verificação.');
            $this->t['resultado']['avisos'][] = 'O banco da origem tinha diferenças de esquema; a atualização foi feita sem a verificação de integridade (veja o log).';
            [$cod, $out] = $this->console($base . ' --skip-db-checks');
        }
        if ($cod !== 0) {
            throw new RuntimeException('db:update falhou: ' . mb_strimwidth($out, -1500, 1500));
        }
        [$cod2, $out2] = $this->console('db:is_up_to_date -n');
        if ($cod2 !== 0) {
            throw new RuntimeException('O banco não ficou na versão deste GLPI: ' . mb_strimwidth($out2, 0, 500));
        }
        return 'banco na versão ' . $this->amb['versao'];
    }

    private function etapa_genericobject(): string
    {
        [$cod, $out] = $this->console('migration:genericobject_plugin_to_core -n');
        if ($cod !== 0) {
            throw new RuntimeException('a conversão do Generic Objects falhou (os dados do plugin continuam no banco): ' . mb_strimwidth($out, -800, 800));
        }
        return 'tipos do Generic Objects convertidos em ativos nativos';
    }

    private function etapa_formcreator(): string
    {
        [$cod, $out] = $this->console('migration:formcreator_plugin_to_core -n');
        if ($cod !== 0) {
            throw new RuntimeException('a conversão do Formcreator falhou (os dados do plugin continuam no banco): ' . mb_strimwidth($out, -800, 800));
        }
        return 'formulários convertidos em formulários nativos';
    }

    private function etapa_migracoes(): string
    {
        [$cod, $out] = $this->console('migration:migrate_all -n');
        if ($cod !== 0) {
            throw new RuntimeException('alguma migração recomendada não terminou: ' . mb_strimwidth($out, -800, 800));
        }
        return 'utf8mb4, timestamps, chaves unsigned e formato de linha';
    }

    private function etapa_finalizar(): string
    {
        $p = PluginBackupeatualizacaoglpiConfig::PLUGIN;
        // O GLPI 11+ suspende a execução dos plugins depois do db:update; os da origem já estão desativados
        $this->console('plugin:resume_execution -n');
        [$cod, $out] = $this->console('plugin:install ' . $p . ' -n -f');
        if ($cod !== 0 || stripos($out, 'erro') !== false && stripos($out, 'instalado') === false) {
            throw new RuntimeException('Não foi possível reinstalar este plugin: ' . mb_strimwidth($out, 0, 500));
        }
        $this->console('plugin:activate ' . $p . ' -n');
        $this->console('cache:clear -n');
        return 'plugin reativado e cache limpo';
    }

    private function etapa_verificar(): string
    {
        $antes = $this->t['analise']['contagens'] ?? [];
        if (!$antes && is_file($this->dir . '/contagens_origem.json')) {
            $antes = json_decode((string) file_get_contents($this->dir . '/contagens_origem.json'), true) ?: [];
        }
        $depois = PluginBackupeatualizacaoglpiVerificacao::contarLocal($this->conexao());
        $r = PluginBackupeatualizacaoglpiVerificacao::comparar($antes, $depois);
        $this->t['resultado']['verificacao'] = $r;
        file_put_contents($this->dir . '/verificacao.json', json_encode($r, JSON_UNESCAPED_UNICODE));
        return $r['iguais'] . ' tabelas iguais, ' . count($r['maiores']) . ' com mais registros (atualização), ' . count($r['menores']) . ' com menos, ' . count($r['removidas']) . ' convertidas/removidas pela atualização';
    }

    // =====================================================================
    // Reversão
    // =====================================================================

    private function reverter(string $motivo): void
    {
        $this->log('== Revertendo este GLPI ao estado anterior (' . $motivo . ')');
        try {
            if (!is_file($this->dir . '/destino_banco.sql.gz')) {
                throw new RuntimeException('o backup deste GLPI não foi encontrado.');
            }
            $this->limparBanco();
            $this->importarDump($this->dir . '/destino_banco.sql.gz');
            $this->sh('tar -C ' . escapeshellarg($this->amb['config']) . ' -xzf ' . escapeshellarg($this->dir . '/destino_config.tar.gz') . ' --no-same-owner');
            $tarFiles = $this->dir . '/destino_files.tar.gz';
            if (is_file($tarFiles)) {
                $this->sh('gzip -t ' . escapeshellarg($tarFiles));
                // files/ volta a ser exatamente o do backup (o que veio da origem sai), menos o que nunca entra nele
                $preservar = ['_cache', '_sessions', '_tmp', '_plugins'];
                $base = rtrim($this->amb['files'], '/');
                foreach ((array) @scandir($base) as $n) {
                    if ($n !== '.' && $n !== '..' && $n !== '' && !in_array($n, $preservar, true)) {
                        $this->sh('rm -rf ' . escapeshellarg($base . '/' . $n));
                    }
                }
                foreach ((array) @scandir($base . '/_plugins') as $n) {
                    if ($n !== '.' && $n !== '..' && $n !== '' && $n !== PluginBackupeatualizacaoglpiConfig::PLUGIN) {
                        $this->sh('rm -rf ' . escapeshellarg($base . '/_plugins/' . $n));
                    }
                }
                $this->sh('tar -C ' . escapeshellarg($base) . ' -xzf ' . escapeshellarg($tarFiles) . ' --no-same-owner');
            }
            foreach ($this->t['resultado']['plugins_copiados'] ?? [] as $tipo => $nomes) {
                foreach ($nomes as $n) {
                    if ($n !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $n)) {
                        $this->sh('rm -rf ' . escapeshellarg($this->amb[$tipo] . '/' . $n));
                    }
                }
            }
            $this->console('cache:clear -n');
            // O banco voltou com a configuração do plugin: o instantâneo não deve ser reaplicado depois
            @unlink(PluginBackupeatualizacaoglpiConfig::pasta('instantaneo.json'));
            $this->t['resultado']['revertido'] = date('Y-m-d H:i:s');
            $this->t['resultado']['importado'] = false;
            $this->log('Reversão concluída: este GLPI voltou a ser como antes da migração.');
        } catch (\Throwable $e) {
            $this->t['resultado']['erro_reversao'] = $e->getMessage();
            $this->log('FALHA NA REVERSÃO: ' . $e->getMessage() . ' — o backup está em ' . $this->dir);
        }
        $this->salvar();
    }

    /** Tarefa de reversão manual de uma migração anterior */
    private function reverterTarefa(): void
    {
        $alvo = PluginBackupeatualizacaoglpiTarefa::carregar((string) $this->t['alvo']);
        if (!$alvo) {
            throw new RuntimeException('Migração a reverter não encontrada.');
        }
        $this->t['etapas'] = [['chave' => 'reverter', 'titulo' => 'Reversão da migração ' . $alvo['id'], 'situacao' => 'executando', 'inicio' => date('Y-m-d H:i:s')]];
        $this->dir = PluginBackupeatualizacaoglpiTarefa::pasta($alvo['id']);
        $this->t['resultado']['plugins_copiados'] = $alvo['resultado']['plugins_copiados'] ?? [];
        $this->reverter('pedido manual');
        $ok = empty($this->t['resultado']['erro_reversao']);
        $this->t['etapas'][0]['situacao'] = $ok ? 'ok' : 'erro';
        $this->t['etapas'][0]['fim'] = date('Y-m-d H:i:s');
        if ($ok) {
            $alvo['situacao'] = 'revertida';
            $alvo['resultado']['revertido'] = date('Y-m-d H:i:s');
            PluginBackupeatualizacaoglpiTarefa::salvar($alvo);
        }
        $this->dir = PluginBackupeatualizacaoglpiTarefa::pasta($this->t['id']);
        $this->encerrar($ok ? 'concluida' : 'erro');
    }

    // =====================================================================
    // Backup completo deste GLPI
    // =====================================================================

    private function backup(): void
    {
        $nome = (string) $this->t['backup'];
        $destino = PluginBackupeatualizacaoglpiConfig::pasta('backups/' . $nome);
        @mkdir($destino, 0750, true);
        $amb = $this->amb;
        $excl = '';
        foreach (array_values(array_unique(array_merge($this->t['opcoes']['exclusoes'] ?? [], ['_plugins/' . PluginBackupeatualizacaoglpiConfig::PLUGIN, '_sessions', '_cache', '_tmp']))) as $x) {
            $excl .= ' --exclude=' . escapeshellarg('./' . $x);
        }
        $passos = [
            'banco'       => ['Banco de dados', fn() => $this->sh(escapeshellarg($amb['dump']) . ' --defaults-extra-file=' . escapeshellarg($this->credenciais()) . ' --single-transaction --quick --hex-blob --no-tablespaces --default-character-set=utf8mb4 ' . escapeshellarg($this->db['banco']) . ' | gzip -c > ' . escapeshellarg($destino . '/banco.sql.gz'), $destino . '/banco.sql.gz')],
            'config'      => ['Configuração e chaves', fn() => $this->sh('tar -C ' . escapeshellarg($amb['config']) . ' -czf ' . escapeshellarg($destino . '/config.tar.gz') . ' .')],
            'files'       => ['Arquivos', fn() => !empty($this->t['opcoes']['files']) ? $this->sh('tar -C ' . escapeshellarg($amb['files']) . ' -czf ' . escapeshellarg($destino . '/files.tar.gz') . $excl . ' .', $destino . '/files.tar.gz') : $this->sh('tar -czf ' . escapeshellarg($destino . '/files.tar.gz') . ' --files-from /dev/null')],
            'plugins'     => ['Plugins', fn() => $this->sh('tar -C ' . escapeshellarg($amb['plugins']) . ' -czf ' . escapeshellarg($destino . '/plugins.tar.gz') . ' --exclude=' . escapeshellarg('./' . PluginBackupeatualizacaoglpiConfig::PLUGIN) . ' .')],
            'marketplace' => ['Marketplace', fn() => is_dir($amb['marketplace']) ? $this->sh('tar -C ' . escapeshellarg($amb['marketplace']) . ' -czf ' . escapeshellarg($destino . '/marketplace.tar.gz') . ' .') : $this->sh('tar -czf ' . escapeshellarg($destino . '/marketplace.tar.gz') . ' --files-from /dev/null')],
            'manifesto'   => ['Manifesto e contagens', fn() => $this->manifesto($destino)],
        ];
        $this->t['etapas'] = [];
        foreach ($passos as $k => [$titulo]) {
            $this->t['etapas'][] = ['chave' => $k, 'titulo' => $titulo, 'situacao' => 'pendente'];
        }
        $this->salvar();
        foreach ($passos as $k => [$titulo, $fn]) {
            $this->etapa($k, 'executando');
            $this->log('== ' . $titulo);
            try {
                $fn();
                $this->etapa($k, 'ok');
            } catch (\Throwable $e) {
                $this->etapa($k, 'erro', $e->getMessage());
                $this->log('FALHA: ' . $e->getMessage());
                $this->t['resultado']['erro'] = $titulo . ': ' . $e->getMessage();
                $this->sh('rm -rf ' . escapeshellarg($destino));
                $this->encerrar('erro');
                return;
            }
        }
        $this->t['resultado']['tamanho'] = PluginBackupeatualizacaoglpiBackup::tamanho($nome);
        $removidos = PluginBackupeatualizacaoglpiBackup::aplicarRetencao((int) ($this->t['opcoes']['retencao'] ?? 0));
        if ($removidos) {
            $this->log('Retenção: ' . count($removidos) . ' backup(s) antigo(s) removido(s): ' . implode(', ', $removidos));
        }
        $this->encerrar('concluida');
    }

    private function manifesto(string $destino): void
    {
        $c = $this->conexao();
        $contagens = PluginBackupeatualizacaoglpiVerificacao::contarLocal($c);
        $plugins = [];
        $q = mysqli_query($c, 'SELECT directory, name, version, state FROM glpi_plugins ORDER BY directory');
        while ($q && ($l = mysqli_fetch_assoc($q))) {
            $plugins[] = $l;
        }
        $q = mysqli_query($c, 'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $tamBanco = $q ? (int) mysqli_fetch_row($q)[0] : 0;
        mysqli_close($c);
        $pastas = ['plugins' => [], 'marketplace' => []];
        foreach (['plugins', 'marketplace'] as $k) {
            foreach ((array) @scandir($this->amb[$k]) as $n) {
                if ($n !== '.' && $n !== '..' && is_dir($this->amb[$k] . '/' . $n) && $n !== PluginBackupeatualizacaoglpiConfig::PLUGIN) {
                    $pastas[$k][] = $n;
                }
            }
        }
        $chaves = [];
        foreach (['glpicrypt.key', 'glpi.key', 'oauth.pem', 'oauth.pub'] as $k) {
            $chaves[$k] = is_file($this->amb['config'] . '/' . $k);
        }
        $m = [
            'formato'        => 1,
            'criado'         => date('Y-m-d H:i:s'),
            'por'            => $this->t['usuario'],
            'versao'         => $this->amb['versao'],
            'versao_no_banco' => $this->amb['versao'],
            'servidor'       => php_uname('n'),
            'caminhos'       => ['raiz' => $this->amb['raiz'], 'config' => $this->amb['config'], 'files' => $this->amb['files'], 'documentos' => $this->amb['documentos'], 'plugins' => $this->amb['plugins'], 'marketplace' => $this->amb['marketplace']],
            'php'            => $this->amb['php'],
            'versao_banco'   => $this->amb['versao_banco'],
            'chaves'         => $chaves,
            'plugins'        => $plugins,
            'pastas_plugins' => $pastas,
            'contagens'      => $contagens,
            'total_tabelas'  => count($contagens),
            'tamanho_banco'  => $tamBanco,
            'tamanho_files'  => (int) @filesize($destino . '/files.tar.gz'),
            'tabelas_myisam' => 0,
            'collation_mysql8' => 0,
            'com_files'      => !empty($this->t['opcoes']['files']),
            'avisos'         => !empty($this->t['opcoes']['files']) ? [] : ['Este backup foi feito sem a pasta files (documentos e imagens).'],
        ];
        file_put_contents($destino . '/manifesto.json', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
