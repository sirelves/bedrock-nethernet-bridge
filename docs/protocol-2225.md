# Bedrock 1.26.60 / 1.26.62 (protocol 2225): what changed for servers

Everything we had to change to get a RakNet server (PocketMine-MP fork) fully playable by 26.60 clients,
with the symptom each change produces when missing. Formats were checked against gophertunnel
[`feature/26.60`](https://github.com/Sandertv/gophertunnel/tree/feature/26.60) (`CurrentProtocol = 2225`)
and against real client traffic captured through the bridge.

> Many of these are reversals of 1.26.40 changes: 1.26.40 introduced "dummy" / double optionals
> (`bool(true)` + `bool` + value) and moved use-item data out of `PlayerAuthInput`; 26.60 removed both.

## 0. Transport

| Change | Symptom if missing |
|---|---|
| Clients ≥ 26.60 (Android, new Windows client) join via **NetherNet**: `GET /v1/join` (status JSON), `POST /v1/join/{networkId}` (SDP offer → answer), then WebRTC data channels. No fallback to RakNet. | "An error occurred", nothing reaches the server. |
| Batches have **no `0xfe` prefix** and **no Minecraft encryption** (DTLS encrypts). | "Packet decryption error" after Login. |
| The server answer must carry its own `a=identity` (self-signed ES384 JWT). Over plain HTTP the client does TOFU. | Client refuses the connection. |

`nethernet-bridge` implements all of this.

## 1. Before / during spawn (clientbound)

| Packet | Change | Symptom if missing |
|---|---|---|
| `JigsawStructureData` (0x139) | **Required before `StartGame`.** NBT (network LE): `{processors:[], template_pools:[], jigsaws:[], structure_sets:[]}`. | Boat — *InitialConnection-148: Missing structure data on server*. |
| `VoxelShapes` (0x151) | Sent before `StartGame`; empty is fine (`00 00 00 00`). | — |
| `StartGame` / `LevelSettings` | New `EditorLevelMigrationVersion` (u8) after `exportedFromEditor`. | Desync of every following field. |
| `PlayerList` entry | **`PlayFabID` (string) moved** from the skin to the entry, between `XUID` and `PlatformChatID`; the skin no longer has it. | Client reads `BuildPlatform -1` as a 4 GB string, disconnects ~1 s after the spawn batch. |
| `LevelChunk` | New trailing bool `ClientBiomeUpdate`. Sub-chunks version **9** with Y index byte. | Chunks rejected. |
| Block palette | Hashed network IDs must come from the **1.26.60 block states** (redstone wire, fire, hopper, slabs, stairs… changed). | Wrong/unknown blocks. |
| `AddActor`, `AddPlayer` | New optional `PassengerOfBlock`. | Entity packets misread. |
| `Animate` (0x2c) | New trailing **`Hand` (u8)**. | Block — client drops when another player swings. |
| `ItemStackResponse` (0x94) | Container list is a **single** optional (1.26.40 had a manual double bool). | Block — `PacketViolationWarning: readNoHeader failed! packetId: 148` when using the recipe book / moving items. |
| `ItemStackResponseSlotInfo` | Stack ID is a single optional. | Same as above. |

## 2. Serverbound

| Packet | Change | Symptom if missing |
|---|---|---|
| `PlayerAuthInput` (0x90) | Input flags are `varuint count` + `count × varint32` flag IDs — **no leading bool**. | *Invalid or duplicate player input flag 0*. |
| `PlayerAuthInput` optionals | `ItemInteractionData`, `ItemStackRequest`, `BlockActions`, `VehicleRotation`, `ClientPredictedVehicle` are **single** optionals (bool + value). | *Vehicle rotation and actor unique ID must both be present or absent*. |
| `PlayerAuthInput` → `ItemInteractionData` | Carries the **full** use-item transaction again: legacy request ID, legacy slots **as an optional**, actions, `ActionType`, `TriggerType` (u8), block pos, face (u8), hotbar slot, **`Hand` (u8, new)**, held item, positions, block runtime ID, prediction (u8), cooldown state (u8). | Same error as above when breaking a block. |
| Use-item handling | The server must **process** that transaction again (1.26.30–1.26.45 ignored it because the data moved to `InventoryTransaction`). | Blocks break client-side but never server-side: no drop, no event 2001. |
| `InventoryTransaction` (0x1e) | No dummy optionals around the transaction type; `UseItemTransactionData` has the new `Hand` (u8). | *Dummy optional first byte should be 1, got 2*. |
| `InventoryAction` | `WindowID` and `SourceFlags` are single optionals. | Misread actions when items are moved. |
| `ItemStackRequest` (0x93) | Action enum gained **`CraftReserved` (id 18, `string reservedID` + `u8 crafts`)** after `CraftLoom`; `NonImplementedDeprecated` → 19, `CraftResultsDeprecated` → 20. | *Unhandled item stack request action type 20* when taking a crafting result. |
| `CraftResultsDeprecated` | Result items use the new `StackRequestItem` format: `varuint variant`, `u8 legacy`, `[string identifier + varint meta]`, `int16 count`, `varuint blockRuntimeID`, `bytes userData`. | *Not enough bytes left in buffer* when crafting. |
| New packets | e.g. `ServerboundCursorItemDrag` (0x166, `u8 state`) when dragging items in a container. Servers must **ignore** unknown packets instead of kicking. | *Unknown packet received*. |

## 3. Not protocol, but found on the way

- PocketMine forks' `CraftingDataCache` sends `netId = index + 1` while `ItemStackRequestExecutor::beginCrafting`
  looked the recipe up by `netId` → every recipe-book craft used the **neighbouring** recipe
  (*Not enough created items available to be taken (have 3, tried to take 4)*). Fix: subtract
  `CraftingDataCache::RECIPE_ID_OFFSET`.
- Exempt `127.0.0.1` from RakLib's 5 s address block, or one bad bridged client blocks all of them.

## How these were found

1. A **reference server** built with gophertunnel 26.60 (the client joined it) proved the transport was fine.
2. The client's own error screen (*Show details*, read over `adb`) named the first missing packet.
3. The bridge's `-validate` flag decodes every server→client packet with gophertunnel 26.60 and flags any that
   do not round-trip (it caught `Animate` and `ItemStackResponse` before anyone noticed in game).
4. LevelEvent IDs told server-side state: `3600` start break, `2014` crack, `3601` stop, **`2001` destroy**.

See [diagnostics.md](diagnostics.md).
