# bedrock-nethernet-bridge

**Faça jogadores do Minecraft Bedrock 1.26.60+ entrarem no seu servidor RakNet.**

[English](README.md)

A partir do Bedrock **26.60** (out/2026), o cliente Android — e o novo cliente de Windows — entram em
servidores de terceiros por **NetherNet** (sinalização HTTP + WebRTC), e não mais por RakNet. Servidores que
só falam RakNet (PocketMine-MP e forks, softwares antigos) mostram *"Ocorreu um erro"* pra esses jogadores, e
nada chega no log do servidor.

O `nethernet-bridge` é um serviço Go pequeno que fica na frente do seu servidor: fala NetherNet com o cliente e
RakNet com o servidor, repassando o tráfego do jogo byte a byte. O servidor continua como está; clientes antigos
seguem entrando direto pelo RakNet.

```
 cliente Bedrock 26.60+                              seu servidor (mesma porta)
 ───────────────────── TCP 19132  HTTP /v1/join ───┐
                       UDP 19140  WebRTC (DTLS) ───┤ nethernet-bridge ── RakNet ──▶ 127.0.0.1:19132
 cliente Bedrock ≤ 26.5x ─────────── UDP 19132 RakNet ────────────────────────────▶ (mesmo servidor)
```

## É pra mim?

- Seu servidor Bedrock é **só RakNet** e quem está no **1.26.60 / 1.26.62** não entra.
- O software do servidor também precisa entender o **protocolo 2225** (1.26.60–1.26.62). O bridge troca o
  *transporte*, não o protocolo do jogo. Pra forks do PocketMine-MP, veja [O que o servidor precisa](#o-que-o-servidor-precisa)
  e o [build do Essential 1.26.62](#essential-corrigido-fork-do-pocketmine-mp).

## Começo rápido

1. Baixe o binário em [Releases](https://github.com/sirelves/bedrock-nethernet-bridge/releases) (ou `go build`).
2. Libere **TCP 19132** e **UDP 19140** no firewall (a UDP 19132 continua com o seu servidor).
3. Rode ao lado do servidor:

```sh
./nethernet-bridge -public-ip 203.0.113.10 -upstream 127.0.0.1:19132
```

Os jogadores adicionam o servidor com o IP e a porta de sempre (`19132`). Na primeira entrada por HTTP o jogo
pergunta uma vez se confia no servidor (TOFU); guarde o `server-identity.pem` pra continuar confiável.

Pra systemd, use [`deploy/nethernet-bridge.service`](deploy/nethernet-bridge.service). A tabela de flags está no
[README em inglês](README.md#flags).

## O que o servidor precisa

1. **Sem a criptografia do Minecraft nas sessões do bridge.** O cliente NetherNet nunca liga o AES — o canal já é
   cifrado pelo DTLS. O servidor tem que pular a criptografia pras sessões que vêm do bridge (`127.0.0.1`). Em forks
   do PocketMine-MP:

   ```php
   // NetworkSession::setAuthenticationStatus()
   if(EncryptionContext::$ENABLED && $this->ip !== '127.0.0.1'){
   ```

   Sem isso a sessão cai logo depois do login com *"Packet decryption error"*.

2. **Não bloquear o IP do bridge.** Todos os jogadores do bridge aparecem como `127.0.0.1`. Isente esse IP dos
   bloqueios automáticos (o RakLib bloqueia por 5 s depois de um pacote inválido) e use XUID/nome pra banir.

O resto é o **protocolo de jogo 2225**, que o servidor precisa implementar. A lista completa do que mudou, com os
sintomas de cada item, está em [docs/protocol-2225.md](docs/protocol-2225.md) (inglês) e no
[guia de porte em português](docs/pt-BR/porte-essential-1.26.62.md).

## Segurança

- **Proteção contra replay de login.** Sem a criptografia do Minecraft, um Login capturado em outro servidor poderia
  ser reaproveitado. O bridge só repassa o `Login` se a chave dele (`x5u`, que o servidor confere contra a cadeia da
  Xbox Live) for **a mesma que o cliente provou possuir** no handshake `a=identity`/DTLS. Se não bater, derruba.
  Mantenha o `xbox-auth` **ligado**.
- A chave de identidade (`server-identity.pem`) é o que os jogadores passam a confiar. Faça backup e nunca publique.

## Erros comuns

| O jogo mostra | Significado |
|---|---|
| *Ocorreu um erro* / NetherNet, nada no log | O cliente não alcança TCP 19132 / UDP 19140, ou o IP está errado. `curl http://IP:19132/v1/join` tem que devolver JSON. |
| **Boat** – *InitialConnection-148: Dados de estrutura ausentes no servidor* | Falta `JigsawStructureData` antes do `StartGame`. Deixe `-inject-prejoin` ligado ou corrija o servidor. |
| *Packet processing error* + no log *Packet decryption error* | O servidor ainda criptografa as sessões do bridge. |
| **Block** / cai calado depois de spawnar | O servidor manda algum pacote no formato antigo. Rode com `-validate` pra achar. |
| **Terracotta** – *conexão com o anfitrião perdida* | O celular trocou de rede, ou o bridge reiniciou. |

## Essential corrigido (fork do PocketMine-MP)

Portamos o [BakuTeam/Essential](https://github.com/BakuTeam/Essential) (multiversion 1.12–1.26.45) pro
**1.26.62 / protocolo 2225**, testado com clientes Android 26.60 reais: entrar, andar, quebrar/dropar/pegar/colocar
bloco, chat, comandos, crafting pelo livro de receitas, baús, morte e respawn.

- Mudanças: [`essential/essential-1.26.62.patch`](essential/essential-1.26.62.patch) (30 arquivos PHP, tudo
  condicionado a `>= ProtocolInfo::PROTOCOL_1_26_62`) + a paleta de blocos 1.26.60 em [`essential/data`](essential/data).
- `Essential.phar` pronto: anexado na [release](https://github.com/sirelves/bedrock-nethernet-bridge/releases).

O Essential é **LGPL-3.0-or-later** (herdado do PocketMine-MP). O phar corrigido é uma versão modificada distribuída
sob a mesma licença. Veja [essential/README.md](essential/README.md).

### Sem bridge: Essential com NetherNet nativo

O [sirelves/Essential](https://github.com/sirelves/Essential) (branch `nethernet-1.26.62`) é o mesmo porte 1.26.62
com **NetherNet nativo** (portado do axolotl-pm/PocketMine-MP): clientes 26.60+ entram direto no servidor, com o IP
real, e este bridge deixa de ser necessário. Precisa da extensão PHP `ext-webrtc`; uma já compilada pro PHP 8.4 ZTS do
PocketMine (Linux x86_64) está na [release](https://github.com/sirelves/Essential/releases). Configuração:
[NETHERNET.md](https://github.com/sirelves/Essential/blob/nethernet-1.26.62/NETHERNET.md).

## Limitações

- Só o protocolo **2225** (Bedrock 1.26.60–1.26.62). O 26.70 (em preview) usa outro protocolo.
- Só IPv4 e um IP público. Sem STUN/TURN (o servidor precisa ser acessível publicamente).
- O servidor vê todo jogador do bridge como `127.0.0.1`.

## Licença

MIT — veja [LICENSE](LICENSE). O patch e o phar do Essential são LGPL-3.0-or-later.
