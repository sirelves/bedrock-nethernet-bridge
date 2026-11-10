# bedrock-nethernet-bridge

**Let Minecraft: Bedrock Edition 1.26.60+ players join your RakNet server.**

[Português (Brasil)](README.pt-BR.md)

Starting with Bedrock **26.60** (Oct 2026), the Android client — and the new Windows client — join
third-party servers over **NetherNet** (HTTP signaling + WebRTC) instead of RakNet. Servers that only
speak RakNet (PocketMine-MP and its forks, older software) show *"An error occurred"* to those players,
and nothing even reaches the server log.

`nethernet-bridge` is a small Go service that sits in front of your server, speaks NetherNet to the
client and RakNet to the server, and relays the game traffic byte for byte. Your server keeps running
as it is; older clients keep connecting over RakNet directly.

```
 Bedrock 26.60+ client                               your server (unchanged port)
 ───────────────────── TCP 19132  HTTP /v1/join ───┐
                       UDP 19140  WebRTC (DTLS) ───┤ nethernet-bridge ── RakNet ──▶ 127.0.0.1:19132
 Bedrock ≤ 26.5x client ─────────── UDP 19132 RakNet ─────────────────────────────▶ (same server)
```

Built on [df-mc/go-nethernet](https://github.com/df-mc/go-nethernet), [pion/webrtc](https://github.com/pion/webrtc)
and [sandertv/go-raknet](https://github.com/sandertv/go-raknet). The reference for every protocol detail is
[gophertunnel `feature/26.60`](https://github.com/Sandertv/gophertunnel/tree/feature/26.60) and Mojang's
[NetherNet onboarding guide](https://mojang.github.io/bedrock-protocol-docs/guides/nether-net-onboarding-guide/).

## Is this for me?

- You run a **RakNet-only** Bedrock server and players on **1.26.60 / 1.26.62** can't join.
- Your server software must also understand **protocol 2225** (1.26.60–1.26.62). The bridge changes the
  *transport*, not the game protocol. For PocketMine-MP forks, see [What the upstream server needs](#what-the-upstream-server-needs)
  and the ready-made [Essential 1.26.62 build](#patched-essential-pocketmine-mp-fork).

## Quick start

1. Download a binary from [Releases](https://github.com/sirelves/bedrock-nethernet-bridge/releases) (or `go build`).
2. Open **TCP 19132** and **UDP 19140** in your firewall (UDP 19132 stays with your server).
3. Run it next to your server:

```sh
./nethernet-bridge -public-ip 203.0.113.10 -upstream 127.0.0.1:19132
```

Players add the server with your normal IP and port (`19132`). On the first join over plain HTTP the
client shows a one-time "trust this server" prompt (TOFU); keep `server-identity.pem` so it stays trusted.

For systemd, use [`deploy/nethernet-bridge.service`](deploy/nethernet-bridge.service).

### Flags

| Flag | Default | |
|---|---|---|
| `-public-ip` | *(required)* | Public IPv4 announced in ICE candidates. |
| `-http` | `0.0.0.0:19132` | TCP address of the HTTP signaling (`GET /v1/join`, `POST /v1/join/{id}`). Clients use this port. |
| `-udp` | `0.0.0.0:19140` | Single UDP port for all WebRTC traffic (ICE UDP mux). |
| `-upstream` | `127.0.0.1:19132` | Your RakNet server. |
| `-key` | `server-identity.pem` | Persistent ECDSA key for the server identity (`a=identity`). Created on first run. |
| `-inject-prejoin` | `true` | Inject `JigsawStructureData` + `VoxelShapes` before `StartGame` when the upstream does not send them. |
| `-debug` | `false` | Verbose WebRTC/ICE/DTLS/SCTP logs. |
| `-version` | | Print version. |

Diagnostic flags for porting work (off in production): `-trace`, `-validate`, `-dump-dir`, `-drop-file`,
`-startgame-patch`. See [docs/diagnostics.md](docs/diagnostics.md).

## What the upstream server needs

NetherNet changes two things the upstream must cooperate with:

1. **No Minecraft encryption on bridged sessions.** NetherNet clients never enable the AES layer — the
   channel is already encrypted by DTLS. The server must skip `ServerToClientHandshake`/encryption for
   sessions coming from the bridge (they connect from `127.0.0.1`). In PocketMine-MP forks:

   ```php
   // NetworkSession::setAuthenticationStatus()
   if(EncryptionContext::$ENABLED && $this->ip !== '127.0.0.1'){
   ```

   Without this the session dies right after login with *"Packet decryption error"*.

2. **Don't IP-ban the bridge.** All bridged players share `127.0.0.1`. Exempt it from automatic
   address blocks (PocketMine's RakLib blocks an address for 5 s after a bad packet), and use XUID/name
   for bans.

Everything else is the **game protocol 2225**, which the server has to implement itself. The full list of
changes we found (with byte-level details and symptoms) is in [docs/protocol-2225.md](docs/protocol-2225.md).

## Security

- **Login replay protection.** Without Minecraft's encryption, a Login captured elsewhere could be
  replayed. The bridge only forwards the `Login` if the key in its client data (`x5u`, which the server
  checks against the Xbox Live chain) is the **same key the client proved it owns** in the NetherNet
  `a=identity` / DTLS handshake. Mismatch → connection dropped. Keep `xbox-auth` **on** in your server.
- The identity key (`server-identity.pem`) is what returning players trust. Back it up; never publish it.
- The bridge does not parse or modify game packets on the hot path (except the optional pre-join injection).

## Troubleshooting

| Client shows | Meaning |
|---|---|
| *An error occurred* / NetherNet, nothing in server log | Client can't reach TCP 19132 / UDP 19140, or wrong IP. `curl http://IP:19132/v1/join` must return JSON. |
| **Boat** – *InitialConnection-148: Missing structure data on server* | No `JigsawStructureData` before `StartGame`. Keep `-inject-prejoin` on or fix the server. |
| *Packet processing error* + server log *Packet decryption error* | Upstream still encrypts bridged sessions (see above). |
| **Block** / client disconnects silently after spawning | Server sends a packet in the old format. Run with `-validate` to find it. |
| **Terracotta** – *connection to host lost* | Network change on the client (Wi‑Fi switch) or bridge restarted. |

On Android, *Show details* on the error screen gives the exact error code; with `adb` you can also
screenshot it remotely. See [docs/diagnostics.md](docs/diagnostics.md) for the workflow we used.

## Patched Essential (PocketMine-MP fork)

We ported [BakuTeam/Essential](https://github.com/BakuTeam/Essential) (a PocketMine-MP fork, multiversion
1.12–1.26.45) to **1.26.62 / protocol 2225**, tested end to end with real Android 26.60 clients: join,
movement, block break/drop/pickup/place, chat, commands, crafting (recipe book), chests, death and respawn.

- Changes: [`essential/essential-1.26.62.patch`](essential/essential-1.26.62.patch) against upstream commit
  `7b9f7cf8` (30 PHP files, every change gated on `>= ProtocolInfo::PROTOCOL_1_26_62`) plus the 1.26.60 block
  palette in [`essential/data`](essential/data).
- Ready-to-run `Essential.phar`: attached to the [release](https://github.com/sirelves/bedrock-nethernet-bridge/releases).

Essential is licensed **LGPL-3.0-or-later** (inherited from PocketMine-MP). The patched phar is a modified
version distributed under the same license; its full source is inside the phar and the modifications are in
this repository. See [essential/README.md](essential/README.md).

## Limitations

- Protocol **2225** only (Bedrock 1.26.60–1.26.62). Bedrock 26.70 (in preview) uses a newer protocol.
- IPv4 only, single public IP. No STUN/TURN (the server is expected to be publicly reachable).
- The upstream sees every bridged player as `127.0.0.1`.

## License

MIT — see [LICENSE](LICENSE). The Essential patch and phar are LGPL-3.0-or-later.
