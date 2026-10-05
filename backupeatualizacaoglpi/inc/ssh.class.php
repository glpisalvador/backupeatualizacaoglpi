<?php

/**
 * Plugin Backup e Atualização GLPI - acesso à origem: SSH com chave própria do plugin ou pasta deste servidor
 * Usa o cliente "ssh" do sistema (sem extensões PHP e sem senha guardada).
 */
class PluginBackupeatualizacaoglpiSsh
{
    public static function chavePrivada(): string
    {
        return PluginBackupeatualizacaoglpiConfig::pasta('ssh/id_ed25519');
    }

    /** Gera o par de chaves na primeira vez */
    public static function garantirChave(): bool
    {
        PluginBackupeatualizacaoglpiConfig::pastas();
        $k = self::chavePrivada();
        if (is_file($k) && is_file($k . '.pub')) {
            return true;
        }
        $host = php_uname('n');
        [$cod] = PluginBackupeatualizacaoglpiConfig::executar('ssh-keygen -q -t ed25519 -N "" -C ' . escapeshellarg('glpi-backupeatualizacao@' . $host) . ' -f ' . escapeshellarg($k));
        @chmod($k, 0600);
        return $cod === 0 && is_file($k);
    }

    public static function chavePublica(): string
    {
        self::garantirChave();
        return trim((string) @file_get_contents(self::chavePrivada() . '.pub'));
    }

    public static function novaChave(): bool
    {
        @unlink(self::chavePrivada());
        @unlink(self::chavePrivada() . '.pub');
        return self::garantirChave();
    }

    /** Comando ssh (sem o comando remoto) para a origem */
    public static function prefixo(array $o): string
    {
        $known = PluginBackupeatualizacaoglpiConfig::pasta('ssh/known_hosts');
        return 'ssh -i ' . escapeshellarg(self::chavePrivada())
            . ' -p ' . max(1, (int) ($o['porta'] ?? 22))
            . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -o ServerAliveInterval=30'
            . ' -o UserKnownHostsFile=' . escapeshellarg($known) . ' -o LogLevel=ERROR '
            . escapeshellarg((string) $o['usuario'] . '@' . (string) $o['host']);
    }

    /** Trecho de pipeline que executa um comando na origem e manda a saída para cá */
    public static function comando(array $o, string $cmd): string
    {
        if (($o['tipo'] ?? '') === 'ssh') {
            return self::prefixo($o) . ' ' . escapeshellarg('bash -o pipefail -c ' . escapeshellarg($cmd));
        }
        return 'bash -o pipefail -c ' . escapeshellarg($cmd);
    }

    /** Executa na origem e devolve [código, saída, erro] */
    public static function executar(array $o, string $cmd, ?string $entrada = null, int $limite = 120): array
    {
        if (($o['tipo'] ?? '') === 'ssh') {
            self::garantirChave();
        }
        return PluginBackupeatualizacaoglpiConfig::executar(self::comando($o, $cmd), $entrada, $limite);
    }

    /** @return array{0: bool, 1: string} */
    public static function testar(array $o): array
    {
        if (($o['tipo'] ?? '') === 'ssh' && (trim((string) ($o['host'] ?? '')) === '' || trim((string) ($o['usuario'] ?? '')) === '')) {
            return [false, 'Informe o servidor e o usuário.'];
        }
        [$cod, $out, $err] = self::executar($o, 'echo CONECTADO; uname -n; command -v php || true', null, 30);
        if ($cod !== 0 || !str_contains($out, 'CONECTADO')) {
            $msg = trim($err) ?: 'sem resposta';
            if (str_contains($err, 'Permission denied')) {
                $msg = 'Acesso negado: adicione a chave pública do plugin ao arquivo ~/.ssh/authorized_keys do usuário "' . ($o['usuario'] ?? '') . '" na origem.';
            }
            return [false, 'Não foi possível conectar: ' . $msg];
        }
        $linhas = array_values(array_filter(array_map('trim', explode("\n", $out))));
        $php = $linhas[2] ?? '';
        return [true, 'Conectado a ' . ($linhas[1] ?? '?') . ($php !== '' ? ' (PHP em ' . $php . ')' : ' — atenção: PHP não encontrado na origem')];
    }

    /** Lê um arquivo da origem (em base64, para não estragar binários) */
    public static function lerArquivo(array $o, string $caminho): ?string
    {
        [$cod, $out] = self::executar($o, 'test -f ' . escapeshellarg($caminho) . ' && base64 -w0 ' . escapeshellarg($caminho));
        if ($cod !== 0) {
            return null;
        }
        $bin = base64_decode(trim($out), true);
        return $bin === false ? null : $bin;
    }
}
