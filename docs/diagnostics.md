# Diagnostics: porting a server to a new protocol

The bridge sees every batch in both directions, which makes it a good place to debug a server that is being
ported to a new Bedrock protocol. These flags are **off by default** and are not meant for production.

| Flag | What it does |
|---|---|
| `-trace` | Logs the packet IDs of every batch, `C->S` and `S->C` (decompressed). |
| `-validate` | Decodes every server→client packet with gophertunnel 26.60 and logs packets that fail or leave bytes over (`S->C packet INVALID for protocol 2225`). Logged once per (packet, problem). |
| `-dump-dir DIR` | Writes raw packets as hex: the first 80 server→client packets of each session (spawn sequence), block/drop related packets after that, invalid packets found by `-validate`, the first `PlayerAuthInput`s, every `PlayerAuthInput` that carries optionals, and `ItemStackRequest` / `InventoryTransaction`. |
| `-drop-file FILE` | Hex packet IDs to strip from server→client batches (re-read on every new session). Lets you bisect which packet the client rejects without restarting the server. Stripping packets the client needs (`ItemRegistry`, biome definitions…) will itself break the join. |
| `-startgame-patch FILE` | JSON object of gophertunnel `packet.StartGame` field names to override (e.g. `{"GameVersion":"1.26.62"}`). |
| `-debug` | WebRTC/ICE/DTLS/SCTP and signaling logs. |

Dumps contain player names, XUIDs and positions. Delete them when you are done.

## Reading the client's own error

The Android client hides the real cause behind generic codes (*Boat*, *Block*, *Terracotta*). The error screen has
**Show details** with an `InitialConnection-NNN` code and a sentence (e.g. *Missing structure data on server*).
With USB/Wi-Fi debugging enabled you can drive the phone and capture it:

```sh
adb pair <ip>:<pairing-port> <code>      # once
adb connect <ip>:<port>
adb exec-out screencap -p > screen.png   # read the error screen
adb shell input tap X Y                  # press "Show details", "Reconnect", ...
```

Who closed the connection is in the bridge log: `client (NetherNet) side closed: SCTP transport closed` means the
client gave up (it rejected something the server sent); `server (RakNet) side closed` means the server kicked the
player (check the server log for `Bad packet`).

## A reference server

When in doubt whether the transport or the server is at fault, run a minimal gophertunnel server behind the same
NetherNet listener and join it. If the client spawns there, the transport is fine and the difference is in what
your server sends: compare its `StartGame` and pre-join packets field by field.

## Useful LevelEvent IDs (server-side truth for block breaking)

`3600` start break (data = 65535 / break ticks) · `2014` crack particles · `3601` stop break · `2001` destroyed.
If you see start/crack/stop but never `2001`, the server never accepted the break.
