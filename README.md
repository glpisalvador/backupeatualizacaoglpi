# Backup e Atualização GLPI para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Plugin que **traz tudo de um GLPI antigo para um GLPI novo** (11 ou 12) pela tela, sem precisar de terminal. Ele roda no GLPI **novo** e **nunca altera o antigo**. Também faz **backups completos** do GLPI em que está instalado.

## O que o plugin faz

### Migração
1. **Origem dos dados:**
   - outro servidor por **SSH**, com uma chave gerada pelo próprio plugin; basta colocar a chave pública no servidor antigo e nenhuma senha fica guardada;
   - uma **pasta** deste servidor;
   - um **backup** feito pelo plugin, inclusive copiado de outro servidor.
2. **Análise:** um script de reconhecimento lê na origem a versão, as pastas (inclusive `local_define.php` e `downstream.php`), o banco, os plugins e a quantidade de registros. É compatível com PHP 7.4, então funciona com GLPI 9.5 e 10.
3. **Plano:** mostra os bloqueios, os avisos e as etapas conforme o salto de versão.
4. **Execução em segundo plano**, com etapas e log ao vivo na tela:
   1. backup deste GLPI, para poder reverter;
   2. banco da origem, com a collation ajustada;
   3. chaves de criptografia;
   4. pasta `files`;
   5. plugins e marketplace, que chegam desativados e sem sobrescrever o que já existe;
   6. atualização oficial do GLPI (`db:update`);
   7. **conversões para o núcleo**: Generic Objects → ativos personalizados e Formcreator → formulários nativos;
   8. migrações recomendadas, retomada dos plugins e limpeza do cache.
5. **Conferência:** registros por tabela antes (na origem) e depois (aqui).
6. **Reversão:** uma falha antes da conferência **reverte sozinha**. Também dá para reverter manualmente depois.

Opções: manter o endereço (`url_base`) deste GLPI, descartar a fila de e-mails não enviados e desativar os coletores de e-mail.

### Backups completos
- Banco, configuração e chaves, `files`, plugins e marketplace, numa pasta com manifesto.
- Criar, **baixar** em partes, usar como origem de uma migração e excluir.
- Backup automático pela ação automática, com retenção configurável.

## Configuração

- Chave SSH do plugin: gerar e mostrar a chave pública.
- Origens salvas.
- Pastas de `files` que ficam fora da cópia e do backup.
- Retenção dos backups.
- Caminho do PHP de linha de comando.

## Requisitos extras

- O PHP precisa poder executar comandos (`shell_exec`/`proc_open`), com `mysqldump`, `mysql`, `tar` e, para SSH, o cliente `ssh` do sistema.
- Espaço em disco para o backup e para a cópia da origem.
- A instalação e a desinstalação **nunca removem** tabelas nem backups.

---

## Download e instalação

1. Baixe o arquivo `backupeatualizacaoglpi-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/backupeatualizacaoglpi/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/backupeatualizacaoglpi
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install backupeatualizacaoglpi -u <usuário administrador>
   php bin/console plugin:activate backupeatualizacaoglpi
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/backupeatualizacaoglpi` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install backupeatualizacaoglpi -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).