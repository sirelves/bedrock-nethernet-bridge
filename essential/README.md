# Essential 1.26.62 (protocol 2225)

Port of [BakuTeam/Essential](https://github.com/BakuTeam/Essential) — a PocketMine-MP fork, multiversion
1.12–1.26.45 — to Minecraft Bedrock **1.26.60 / 1.26.62 (protocol 2225)**, meant to run behind
[nethernet-bridge](../README.md).

## License notice (LGPL-3.0-or-later)

Essential is derived from PocketMine-MP and is licensed under the **GNU Lesser General Public License,
version 3 or later** (<https://www.gnu.org/licenses/lgpl-3.0.txt>). The files in this directory and the
`Essential-1.26.62.phar` attached to the releases are a **modified version** of Essential, distributed under the
same license:

- **Base:** BakuTeam/Essential commit `7b9f7cf8e9e9ee9303893835f155fedfaf350a13` ("prepare 5.46.2 release").
- **Modifications:** [`essential-1.26.62.patch`](essential-1.26.62.patch) (30 PHP files, +199/−49) and two new data
  files in [`data/`](data). Applies cleanly with `git apply` on the base commit.
- **Source:** a phar is a PHP archive; the full source of the build is inside it
  (`php -r '(new Phar("Essential-1.26.62.phar"))->extractTo("src-out");'`).
- Modified on 2026-11-10. No warranty.

The 1.26.60 block palette in [`data/`](data) (`canonical_block_states-1.26.62.json`) is converted from
[df-mc/dragonfly](https://github.com/df-mc/dragonfly)'s `block_states.nbt` (MIT).

## What changed

Every protocol change is gated on `>= ProtocolInfo::PROTOCOL_1_26_62`, so clients 1.12–1.26.45 behave exactly
as before. The full explanation, with the symptom of each missing piece, is in
[docs/protocol-2225.md](../docs/protocol-2225.md) (English) and
[docs/pt-BR/porte-essential-1.26.62.md](../docs/pt-BR/porte-essential-1.26.62.md) (Português).

| Area | Files |
|---|---|
| Protocol registration, item schema, block palette | `ProtocolInfo`, `ItemTranslator`, `BlockTranslator`, `ItemTypeDictionaryFromDataHelper`, `data/*-1.26.62.json` |
| Spawn sequence | `PreSpawnPacketHandler` (Jigsaw/VoxelShapes), `LevelSettings`, `LevelChunkPacket`, `ChunkSerializer`, `AddActorPacket`, `AddPlayerPacket`, `PlayerListPacket`, `PacketSerializer` (skin) |
| Input and interaction | `PlayerAuthInputPacket`, `ItemInteractionData`, `TransactionData`, `UseItemTransactionData`, `NetworkInventoryAction`, `InventoryTransactionPacket`, `InGamePacketHandler` |
| Inventory / crafting | `ItemStackRequest`, `DeprecatedCraftingResultsStackRequestAction`, `ItemStackResponse`, `ItemStackResponseSlotInfo`, `ItemStackRequestExecutor` (recipe net-ID offset, all versions) |
| Animation | `AnimatePacket` (hand), `ArmSwingAnimation`, `Player`, `SurvivalBlockBreakHandler` (arm-swing de-duplication present in the deployed build) |
| Bridge support | `NetworkSession` (no Minecraft encryption for `127.0.0.1`; ignore unknown packets on 2225), `RakLibInterface` (do not block `127.0.0.1`) |

## Using it

1. Back up your server and its current phar.
2. Download `Essential-1.26.62.phar` from the release (check the SHA-256 published there) or apply
   `essential-1.26.62.patch` to the base commit, copy `data/*.json` to `src/data/bedrock/mapping/` and build.
3. Keep `xbox-auth=on`.
4. Run [nethernet-bridge](../README.md) in front of it so 26.60+ clients can connect.

`scripts/` holds the in-place patch scripts used during the port (they edit a phar with the Phar API, checking
each replacement matches exactly once and linting with `php -l`). They are kept as a record of the process; the
`.patch` file is the authoritative change set.

```sh
php -d phar.readonly=0 scripts/15-ignore-unknown-packets.php Essential.phar --dry   # check only
```
