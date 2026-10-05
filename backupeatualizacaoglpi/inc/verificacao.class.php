<?php

/**
 * Plugin Backup e Atualização GLPI - conferência dos dados: registros por tabela antes (origem) e depois (aqui)
 */
class PluginBackupeatualizacaoglpiVerificacao
{
    /** O que mais importa conferir, com as tabelas equivalentes nas versões novas */
    public static function essenciais(): array
    {
        return [
            'Usuários'                => ['glpi_users'],
            'E-mails de usuários'     => ['glpi_useremails'],
            'Grupos'                  => ['glpi_groups'],
            'Membros de grupos'       => ['glpi_groups_users'],
            'Perfis'                  => ['glpi_profiles'],
            'Perfis dos usuários'     => ['glpi_profiles_users'],
            'Entidades'               => ['glpi_entities'],
            'Regras'                  => ['glpi_rules'],
            'Critérios de regras'     => ['glpi_rulecriterias'],
            'Ações de regras'         => ['glpi_ruleactions'],
            'SLAs'                    => ['glpi_slas'],
            'OLAs'                    => ['glpi_olas'],
            'Calendários'             => ['glpi_calendars'],
            'Categorias ITIL'         => ['glpi_itilcategories'],
            'Categorias de serviço'   => ['glpi_knowbaseitemcategories', 'glpi_forms_categories'],
            'Chamados'                => ['glpi_tickets'],
            'Acompanhamentos'         => ['glpi_itilfollowups'],
            'Tarefas de chamado'      => ['glpi_tickettasks'],
            'Soluções'                => ['glpi_itilsolutions'],
            'Problemas'               => ['glpi_problems'],
            'Mudanças'                => ['glpi_changes'],
            'Projetos'                => ['glpi_projects'],
            'Documentos'              => ['glpi_documents'],
            'Base de conhecimento'    => ['glpi_knowbaseitems'],
            'Computadores'            => ['glpi_computers'],
            'Formulários'             => ['glpi_plugin_formcreator_forms', 'glpi_forms_forms'],
            'Ações automáticas'       => ['glpi_crontasks'],
            'Coletores de e-mail'     => ['glpi_mailcollectors'],
            'Notificações'            => ['glpi_notifications'],
            'Modelos de notificação'  => ['glpi_notificationtemplates'],
            'Diretórios LDAP'         => ['glpi_authldaps'],
            'Plugins registrados'     => ['glpi_plugins'],
        ];
    }

    /** Tabelas que a própria atualização do GLPI limpa ou recria (diferença esperada) */
    public static function variaveis(): array
    {
        return ['glpi_configs', 'glpi_crontasks', 'glpi_crontasklogs', 'glpi_displaypreferences', 'glpi_profilerights', 'glpi_events', 'glpi_logs', 'glpi_notimportedemails', 'glpi_queuednotifications', 'glpi_plugins', 'glpi_impactcompounds', 'glpi_savedsearches_users', 'glpi_users_sessions'];
    }

    public static function contarLocal(mysqli $c): array
    {
        $tabelas = [];
        $q = mysqli_query($c, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($q && ($l = mysqli_fetch_row($q))) {
            $tabelas[] = $l[0];
        }
        $r = [];
        foreach ($tabelas as $t) {
            $q = mysqli_query($c, 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`');
            if ($q) {
                $r[$t] = (int) mysqli_fetch_row($q)[0];
            }
        }
        ksort($r);
        return $r;
    }

    public static function comparar(array $antes, array $depois): array
    {
        $ignorar = static fn($t) => str_starts_with($t, 'glpi_plugin_' . PluginBackupeatualizacaoglpiConfig::PLUGIN . '_');
        $variaveis = self::variaveis();
        $r = ['iguais' => 0, 'maiores' => [], 'menores' => [], 'esperadas' => [], 'removidas' => [], 'novas' => 0, 'essenciais' => [], 'total_antes' => 0, 'total_depois' => 0];
        foreach ($antes as $t => $n) {
            if ($ignorar($t)) {
                continue;
            }
            $r['total_antes'] += $n;
            if (!array_key_exists($t, $depois)) {
                if ($n > 0) {
                    $r['removidas'][$t] = $n;
                }
                continue;
            }
            $d = $depois[$t];
            if ($d === $n) {
                $r['iguais']++;
            } elseif ($d > $n) {
                $r['maiores'][$t] = [$n, $d];
            } elseif (in_array($t, $variaveis, true)) {
                $r['esperadas'][$t] = [$n, $d];
            } else {
                $r['menores'][$t] = [$n, $d];
            }
        }
        foreach ($depois as $t => $n) {
            if ($ignorar($t)) {
                continue;
            }
            $r['total_depois'] += $n;
            if (!array_key_exists($t, $antes)) {
                $r['novas']++;
            }
        }
        foreach (self::essenciais() as $rotulo => $tabs) {
            $a = null;
            $d = null;
            foreach ($tabs as $t) {
                if (array_key_exists($t, $antes)) {
                    $a = ($a ?? 0) + $antes[$t];
                }
                if (array_key_exists($t, $depois)) {
                    $d = ($d ?? 0) + $depois[$t];
                }
            }
            // Formulários: os do Formcreator passam a existir também nos nativos (contar só o lado novo)
            if ($rotulo === 'Formulários' && isset($antes['glpi_plugin_formcreator_forms'], $depois['glpi_forms_forms'])) {
                $a = $antes['glpi_plugin_formcreator_forms'] + ($antes['glpi_forms_forms'] ?? 0);
                $d = $depois['glpi_forms_forms'];
            }
            if ($a === null && $d === null) {
                continue;
            }
            $r['essenciais'][] = ['rotulo' => $rotulo, 'antes' => $a, 'depois' => $d];
        }
        return $r;
    }

    /** Tabela HTML com o resultado da conferência */
    public static function exibir(array $r): void
    {
        $C = PluginBackupeatualizacaoglpiConfig::class;
        $f = static fn($n) => $n === null ? '—' : number_format((int) $n, 0, ',', '.');
        echo '<div class="bkg-verif">';
        echo '<div class="bkg-resumo">';
        echo '<span><strong>' . $f($r['iguais']) . '</strong> tabelas idênticas</span>';
        echo '<span><strong>' . count($r['maiores']) . '</strong> com mais registros</span>';
        echo '<span class="' . ($r['menores'] ? 'bkg-txt-erro' : '') . '"><strong>' . count($r['menores']) . '</strong> com menos registros</span>';
        echo '<span><strong>' . count($r['removidas']) . '</strong> convertidas ou removidas pela atualização</span>';
        echo '<span><strong>' . $f($r['total_antes']) . '</strong> registros antes · <strong>' . $f($r['total_depois']) . '</strong> depois</span>';
        echo '</div>';

        echo '<table class="table table-sm table-hover bkg-tabela"><thead><tr><th>Item</th><th class="text-end">Origem</th><th class="text-end">Aqui</th><th></th></tr></thead><tbody>';
        foreach ($r['essenciais'] as $e) {
            $ok = $e['depois'] !== null && ($e['antes'] === null || $e['depois'] >= $e['antes']);
            $esperada = $e['rotulo'] === 'Ações automáticas' || $e['rotulo'] === 'Plugins registrados';
            $classe = $ok ? 'bkg-ok' : ($esperada ? 'bkg-neutro' : 'bkg-erro');
            $icone = $ok ? 'ti-circle-check' : ($esperada ? 'ti-info-circle' : 'ti-alert-triangle');
            echo '<tr><td>' . $C::e($e['rotulo']) . '</td><td class="text-end">' . $f($e['antes']) . '</td><td class="text-end">' . $f($e['depois']) . '</td><td class="text-center"><i class="ti ' . $icone . ' ' . $classe . '"></i></td></tr>';
        }
        echo '</tbody></table>';

        foreach ([['menores', 'Tabelas com menos registros que na origem', 'bkg-alerta-aviso'], ['esperadas', 'Diferenças esperadas (a atualização limpa ou recria estas tabelas)', 'bkg-alerta-info'], ['maiores', 'Tabelas com mais registros (dados criados pela atualização)', 'bkg-alerta-info']] as [$k, $titulo, $cls]) {
            if (!$r[$k]) {
                continue;
            }
            echo '<details class="bkg-detalhes"><summary>' . $C::e($titulo) . ' (' . count($r[$k]) . ')</summary><table class="table table-sm bkg-tabela"><tbody>';
            foreach ($r[$k] as $t => [$a, $d]) {
                echo '<tr><td><code>' . $C::e($t) . '</code></td><td class="text-end">' . $f($a) . '</td><td class="text-end">' . $f($d) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
        if ($r['removidas']) {
            echo '<details class="bkg-detalhes"><summary>Tabelas da origem que não existem mais aqui (' . count($r['removidas']) . ')</summary>';
            echo '<p class="text-muted bkg-dica"><i class="ti ti-info-circle"></i> A atualização oficial renomeia, une ou converte tabelas entre versões (ex.: categorias da base de conhecimento no GLPI 12). Os dados passam para as estruturas novas.</p><table class="table table-sm bkg-tabela"><tbody>';
            foreach ($r['removidas'] as $t => $n) {
                echo '<tr><td><code>' . $C::e($t) . '</code></td><td class="text-end">' . $f($n) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
        echo '</div>';
    }
}
