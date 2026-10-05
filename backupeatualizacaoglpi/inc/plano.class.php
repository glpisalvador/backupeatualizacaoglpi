<?php

/**
 * Plugin Backup e Atualização GLPI - plano da migração: bloqueios, avisos e etapas conforme o salto de versão
 */
class PluginBackupeatualizacaoglpiPlano
{
    /** Versões mínimas dos plugins para as migrações oficiais para o núcleo (GLPI 11+) */
    public const MIN_GENERICOBJECT = '2.14.14';
    public const MIN_FORMCREATOR = '2.13.10';

    public static function titulos(): array
    {
        return [
            'backup_destino' => 'Backup deste GLPI (ponto de reversão)',
            'contar_origem'  => 'Contagem dos registros da origem',
            'dump'           => 'Cópia do banco da origem',
            'importar'       => 'Importação do banco',
            'chaves'         => 'Chaves de criptografia e OAuth',
            'arquivos'       => 'Arquivos (documentos, imagens, dados de plugins)',
            'plugins'        => 'Pastas de plugins e marketplace',
            'desativar'      => 'Desativação dos plugins',
            'atualizar'      => 'Atualização oficial do banco (db:update)',
            'genericobject'  => 'Generic Objects para ativos nativos',
            'formcreator'    => 'Formcreator para formulários nativos',
            'migracoes'      => 'Migrações recomendadas (utf8mb4, timestamps, chaves)',
            'finalizar'      => 'Reativação deste plugin e limpeza do cache',
            'verificar'      => 'Conferência: registros antes × depois',
        ];
    }

    private static function temPlugin(array $a, string $dir): ?array
    {
        foreach ($a['plugins'] ?? [] as $p) {
            if (($p['directory'] ?? '') === $dir) {
                return $p;
            }
        }
        return null;
    }

    private static function temTabela(array $a, string $tabela): bool
    {
        return array_key_exists($tabela, $a['contagens'] ?? []) || (($a['tabelas'] ?? null) && in_array($tabela, $a['tabelas'], true));
    }

    /**
     * @return array{bloqueios: array, avisos: array, info: array, etapas: array, flags: array}
     */
    public static function gerar(array $origem, array $a, array $amb): array
    {
        global $DB;
        $C = PluginBackupeatualizacaoglpiConfig::class;
        $bloq = [];
        $avisos = array_values($a['avisos'] ?? []);
        $info = [];
        $vo = (string) ($a['versao'] ?? '');
        $vd = (string) $amb['versao'];
        if (!($a['ok'] ?? false)) {
            return ['bloqueios' => $a['erros'] ?? ['Análise da origem falhou.'], 'avisos' => $avisos, 'info' => [], 'etapas' => [], 'flags' => []];
        }
        $so = $C::serie($vo);
        $sd = $C::serie($vd);
        if (version_compare($so, '0.85', '<')) {
            $bloq[] = 'A origem é o GLPI ' . $vo . ': a atualização oficial só existe a partir do 0.85. Atualize-a antes para o 9.5.';
        }
        if (version_compare($so, $sd, '>')) {
            $bloq[] = 'A origem (' . $vo . ') é mais nova que este GLPI (' . $vd . '). Não é possível voltar de versão.';
        }
        if (version_compare($so, $sd, '==')) {
            $info[] = 'Mesma série (' . $sd . '): é uma restauração/cópia; o db:update só aplica ajustes menores, se houver.';
        } else {
            $info[] = 'Salto de versão: GLPI ' . $vo . ' → ' . $vd . '. A atualização oficial do GLPI converte o banco em uma única execução.';
        }
        if (!empty($a['versao_no_banco']) && $C::serie((string) $a['versao_no_banco']) !== $so) {
            $avisos[] = 'Os arquivos da origem são da versão ' . $vo . ', mas o banco dela está como ' . $a['versao_no_banco'] . '. A atualização usa a versão do banco.';
        }
        if ($amb['instavel']) {
            $avisos[] = 'Este GLPI é uma versão de testes (' . $vd . '). A atualização será feita com --allow-unstable.';
        }

        // Ferramentas
        if ($origem['tipo'] !== 'arquivo') {
            if (($a['ferramentas']['dump'] ?? '') === '') {
                $bloq[] = 'A origem não tem mysqldump nem mariadb-dump instalados.';
            }
            foreach (['gzip', 'tar'] as $f) {
                if (($a['ferramentas'][$f] ?? '') === '') {
                    $bloq[] = 'A origem não tem o comando "' . $f . '".';
                }
            }
        }
        foreach (['dump' => 'mariadb-dump/mysqldump', 'cliente' => 'mariadb/mysql', 'tar' => 'tar', 'gzip' => 'gzip'] as $f => $n) {
            if ($amb[$f] === '') {
                $bloq[] = 'Este servidor não tem "' . $n . '".';
            }
        }
        if ($origem['tipo'] === 'ssh' && $amb['ssh'] === '') {
            $bloq[] = 'Este servidor não tem o cliente ssh.';
        }

        // Chaves
        if (empty($a['chaves']['glpicrypt.key'])) {
            $avisos[] = 'A origem não tem config/glpicrypt.key: senhas guardadas (e-mail, LDAP, coletores) podem precisar ser digitadas de novo.';
        }

        // Espaço
        $precisa = (int) (($a['tamanho_banco'] ?? 0) * 2 + ($a['tamanho_files'] ?? 0) * 1.3);
        $info[] = 'Banco da origem: ' . $C::tamanho((float) ($a['tamanho_banco'] ?? 0)) . ' em ' . (int) ($a['total_tabelas'] ?? 0) . ' tabelas; arquivos: ' . $C::tamanho((float) ($a['tamanho_files'] ?? 0)) . '.';
        if ($amb['livre'] > 0 && $amb['livre'] < $precisa) {
            $bloq[] = 'Espaço livre insuficiente neste servidor: ' . $C::tamanho((float) $amb['livre']) . ' livres, são necessários cerca de ' . $C::tamanho((float) $precisa) . '.';
        }
        if ((int) ($a['tabelas_myisam'] ?? 0) > 0) {
            $info[] = (int) $a['tabelas_myisam'] . ' tabela(s) MyISAM serão convertidas para InnoDB.';
        }
        if ((int) ($a['collation_mysql8'] ?? 0) > 0) {
            $info[] = 'Tabelas com collation do MySQL 8 (utf8mb4_0900_*) serão ajustadas para utf8mb4_unicode_ci.';
        }

        // Este GLPI será substituído
        $usuarios = (int) countElementsInTable('glpi_users');
        $chamados = (int) countElementsInTable('glpi_tickets');
        if ($chamados > 0 || $usuarios > 5) {
            $avisos[] = 'Este GLPI já tem dados (' . $usuarios . ' usuários, ' . $chamados . ' chamados). Eles serão SUBSTITUÍDOS pelos da origem (fica um backup para reverter).';
        }
        $avisos[] = 'Depois da importação, a sessão atual termina: entre com um usuário administrador do GLPI de origem para acompanhar o final.';

        // Plugins
        $maior = (int) explode('.', $sd)[0];
        $go = self::temPlugin($a, 'genericobject');
        $fc = self::temPlugin($a, 'formcreator');
        $fi = self::temPlugin($a, 'fields');
        $flags = [
            'instavel'      => $amb['instavel'],
            'genericobject' => $maior >= 11 && ($go !== null || self::temTabela($a, 'glpi_plugin_genericobject_types')),
            'formcreator'   => $maior >= 11 && ($fc !== null || self::temTabela($a, 'glpi_plugin_formcreator_forms')),
        ];
        if ($flags['genericobject'] && $go && version_compare((string) $go['version'], self::MIN_GENERICOBJECT, '<')) {
            $avisos[] = 'Generic Objects está na versão ' . $go['version'] . ' na origem; a conversão oficial pede ' . self::MIN_GENERICOBJECT . ' ou mais nova. Atualize o plugin na origem antes, se possível.';
        }
        if ($flags['formcreator'] && $fc && version_compare((string) $fc['version'], self::MIN_FORMCREATOR, '<')) {
            $avisos[] = 'Formcreator está na versão ' . $fc['version'] . ' na origem; a conversão oficial pede ' . self::MIN_FORMCREATOR . ' ou mais nova. Atualize o plugin na origem antes, se possível.';
        }
        if ($fi && $maior >= 11) {
            $info[] = 'Fields continua como plugin no GLPI ' . $sd . ' (os dados são copiados; reative-o depois de instalar a versão compatível).';
        }
        $ativos = array_filter($a['plugins'] ?? [], static fn($p) => (int) $p['state'] === 1);
        $info[] = count($a['plugins'] ?? []) . ' plugin(s) registrados na origem (' . count($ativos) . ' ativos). Todos chegam desativados: ative cada um depois de conferir a compatibilidade.';

        $etapas = [];
        foreach (self::titulos() as $k => $t) {
            if ($k === 'genericobject' && !$flags['genericobject']) {
                continue;
            }
            if ($k === 'formcreator' && !$flags['formcreator']) {
                continue;
            }
            if ($k === 'contar_origem' && $origem['tipo'] === 'arquivo') {
                continue;
            }
            $etapas[] = ['chave' => $k, 'titulo' => $t];
        }
        return ['bloqueios' => array_values(array_unique($bloq)), 'avisos' => array_values(array_unique($avisos)), 'info' => $info, 'etapas' => $etapas, 'flags' => $flags];
    }

    /** Plugins da origem com o que acontece com cada um aqui */
    public static function plugins(array $a, array $amb): array
    {
        $aqui = [];
        foreach ([$amb['plugins'], $amb['marketplace']] as $d) {
            foreach ((array) @scandir($d) as $n) {
                if ($n !== '.' && $n !== '..' && is_dir($d . '/' . $n)) {
                    $aqui[$n] = true;
                }
            }
        }
        $pastas = array_merge($a['pastas_plugins']['plugins'] ?? [], $a['pastas_plugins']['marketplace'] ?? []);
        $lista = [];
        foreach ($a['plugins'] ?? [] as $p) {
            $dir = (string) $p['directory'];
            $lista[$dir] = ['diretorio' => $dir, 'nome' => strip_tags((string) $p['name']), 'versao' => (string) $p['version'], 'estado' => (int) $p['state'], 'pasta' => in_array($dir, $pastas, true), 'aqui' => isset($aqui[$dir])];
        }
        foreach ($pastas as $dir) {
            if (!isset($lista[$dir])) {
                $lista[$dir] = ['diretorio' => $dir, 'nome' => $dir, 'versao' => '', 'estado' => -1, 'pasta' => true, 'aqui' => isset($aqui[$dir])];
            }
        }
        unset($lista[PluginBackupeatualizacaoglpiConfig::PLUGIN]);
        ksort($lista);
        return array_values($lista);
    }

    /** HTML do plano mostrado na tela antes de iniciar */
    public static function exibir(array $origem, array $a, array $plano, array $amb): string
    {
        $C = PluginBackupeatualizacaoglpiConfig::class;
        ob_start();
        if (!($a['ok'] ?? false)) {
            echo '<div class="bkg-alerta bkg-alerta-erro"><i class="ti ti-alert-triangle"></i> <div>' . implode('<br>', array_map([$C, 'e'], $a['erros'] ?? ['Falha na análise.'])) . '</div></div>';
            return (string) ob_get_clean();
        }
        echo '<div class="bkg-salto"><div><span>Origem</span><strong>GLPI ' . $C::e($a['versao']) . '</strong><small>' . $C::e(PluginBackupeatualizacaoglpiOrigem::descrever($origem)) . '</small></div>';
        echo '<i class="ti ti-arrow-right"></i>';
        echo '<div><span>Destino (este)</span><strong>GLPI ' . $C::e($amb['versao']) . '</strong><small>' . $C::e($amb['servidor']) . '</small></div></div>';

        echo '<table class="bkg-dados">';
        echo '<tr><th>PHP da origem</th><td>' . $C::e($a['php'] ?? '?') . '</td></tr>';
        echo '<tr><th>Banco da origem</th><td>' . $C::e(($a['versao_banco'] ?? '?') . ' · ' . ($a['banco']['banco'] ?? '') . ' em ' . ($a['banco']['host'] ?? '')) . '</td></tr>';
        echo '<tr><th>Pastas</th><td><code>' . $C::e($a['caminhos']['raiz'] ?? '') . '</code> · files <code>' . $C::e($a['caminhos']['files'] ?? '') . '</code></td></tr>';
        $chaves = array_keys(array_filter($a['chaves'] ?? []));
        echo '<tr><th>Chaves</th><td>' . ($chaves ? $C::e(implode(', ', $chaves)) : '<span class="bkg-txt-erro">nenhuma</span>') . '</td></tr>';
        echo '</table>';

        foreach ([['bloqueios', 'bkg-alerta-erro', 'ti-ban', 'Impede a migração'], ['avisos', 'bkg-alerta-aviso', 'ti-alert-triangle', 'Atenção'], ['info', 'bkg-alerta-info', 'ti-info-circle', 'Informações']] as [$k, $cls, $ic, $tit]) {
            if (empty($plano[$k])) {
                continue;
            }
            echo '<div class="bkg-alerta ' . $cls . '"><i class="ti ' . $ic . '"></i><div><strong>' . $tit . '</strong><ul>';
            foreach ($plano[$k] as $m) {
                echo '<li>' . $C::e($m) . '</li>';
            }
            echo '</ul></div></div>';
        }

        $plugins = self::plugins($a, $amb);
        if ($plugins) {
            echo '<details class="bkg-detalhes" open><summary>Plugins da origem (' . count($plugins) . ')</summary>';
            echo '<table class="table table-sm table-hover bkg-tabela"><thead><tr><th>Plugin</th><th>Versão</th><th>Na origem</th><th>O que acontece</th></tr></thead><tbody>';
            foreach ($plugins as $p) {
                if (in_array($p['diretorio'], ['genericobject', 'formcreator'], true) && !empty($plano['flags'][$p['diretorio']])) {
                    $acao = '<span class="bkg-pill bkg-pill-ok">convertido para o núcleo</span>';
                } elseif ($p['aqui']) {
                    $acao = '<span class="bkg-pill bkg-pill-neutro">mantida a pasta que já existe aqui</span>';
                } elseif ($p['pasta']) {
                    $acao = '<span class="bkg-pill bkg-pill-aviso">copiado, desativado</span>';
                } else {
                    $acao = '<span class="bkg-pill bkg-pill-neutro">só os dados (sem pasta na origem)</span>';
                }
                echo '<tr><td><strong>' . $C::e($p['nome']) . '</strong> <code>' . $C::e($p['diretorio']) . '</code></td><td>' . $C::e($p['versao']) . '</td><td>' . $C::e(self::estadoPlugin($p['estado'])) . '</td><td>' . $acao . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        if ($plano['etapas']) {
            echo '<details class="bkg-detalhes"><summary>Etapas (' . count($plano['etapas']) . ')</summary><ol class="bkg-passos">';
            foreach ($plano['etapas'] as $e) {
                echo '<li>' . $C::e($e['titulo']) . '</li>';
            }
            echo '</ol></details>';
        }
        return (string) ob_get_clean();
    }

    public static function estadoPlugin(int $e): string
    {
        return [-1 => 'só a pasta', 0 => 'novo', 1 => 'ativo', 2 => 'não instalado', 3 => 'a configurar', 4 => 'desativado', 5 => 'a limpar', 6 => 'a atualizar', 7 => 'substituído'][$e] ?? (string) $e;
    }
}
