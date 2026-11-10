# Porte do Essential (PocketMine-MP) para Bedrock 1.26.62 — protocolo 2225

> Essential = fork BakuTeam do PocketMine-MP (base pmmp 5.46.2, API 5.0.0, multiversion 1.12–1.26.45).
> Alvo: Minecraft Bedrock **1.26.62** = **protocolo 2225** (o mesmo do 1.26.60).
> **Todos os fixes são gated em `>= ProtocolInfo::PROTOCOL_1_26_62`** — nenhuma versão antiga (1.12–1.26.45) muda de comportamento.
> Referências de formato: gophertunnel `feature/26.60` (CurrentProtocol 2225) e df-mc/dragonfly (1.26.60).

Status: **jogável** — conectar, spawnar, andar, quebrar/colocar bloco (com drop), inventário, animação, chat, comandos, crafting pelo livro de receitas, baús, morte e respawn. 0 "Bad packet", 0 pacote S->C inválido (validado com o `-validate` do bridge, que decodifica tudo com o gophertunnel 26.60).

---

## 0. Transporte — NetherNet (pré-requisito)

Clientes Bedrock **1.26.60+** (Android **e** o cliente novo de Windows 10) conectam **só por NetherNet** (WebRTC/DTLS, sinalização HTTP `GET/POST /v1/join`), **não por RakNet/UDP**. Um servidor só-RakNet responde "ocorreu um erro" pra esses clientes.

- **[nethernet-bridge](../../README.pt-BR.md)** (este repositório): TCP 19132 (sinalização) + UDP 19140 (WebRTC) → upstream `127.0.0.1:19132`.
- Companheiros no Essential (pro tráfego do proxy, que já é cifrado por DTLS):
  - `NetworkSession.php`: pular o AES do MC quando `$this->ip === '127.0.0.1'` (`if(EncryptionContext::$ENABLED && $this->ip !== '127.0.0.1')`).
  - `RakLibInterface.php`: isentar `127.0.0.1` do `blockAddress` de 5s pós-bad-packet.
- O ideal futuro é NetherNet **nativo** no servidor (extensão C em PHP); PHP puro não tem stack WebRTC viável.

---

## 1. Registro do protocolo

### `ProtocolInfo.php`
- `public const PROTOCOL_1_26_62 = 2225;`
- `CURRENT_PROTOCOL = self::PROTOCOL_1_26_62;`
- adicionar `PROTOCOL_1_26_45` ao `ACCEPTED_PROTOCOL`.
- `MINECRAFT_VERSION = 'v26.62'`, `MINECRAFT_VERSION_NETWORK = '1.26.62'`.

### `convert/ItemTranslator.php`
- `getItemSchemaId`: mapear `PROTOCOL_1_26_62 => 271`.

### `convert/BlockTranslator.php` e `convert/ItemTypeDictionaryFromDataHelper.php`
- Nos `PATHS`, adicionar entradas pra `PROTOCOL_1_26_45` (herdando o dict do 1_26_40). `HASH_PROTOCOLS` já inclui 2225 via `array_keys(PATHS)` (>= 1.21.120).

### Paleta de blocos 1.26.60 (correção de conteúdo)
- Adicionar `src/data/bedrock/mapping/canonical_block_states-1.26.62.json` (= `df_block_states.nbt` do dragonfly 1.26.60, **23960 blocos**) e `block_state_meta_map-1.26.62.json` (23960 zeros).
- No `BlockTranslator::PATHS`, a entrada do `CURRENT_PROTOCOL` aponta pra `-1.26.62`. Só o 2225 usa a paleta nova; as outras versões ficam intactas.
- *Nota:* blocos comuns (stone/dirt/grass) têm o mesmo hash FNV-1a independente da versão; essa paleta importa pros blocos que **mudaram** no 1.26.60 (redstone_wire +228 states, fire, glowstone, hopper, slab, stairs, wire_connection).

---

## 2. Pacotes clientbound — mundo e spawn

### `PreSpawnPacketHandler.php` — **CRÍTICO** (sem isso o cliente nunca spawna)
Enviar **antes** do `StartGamePacket`, gated 2225:
1. `JigsawStructureDataPacket` com NBT (network little-endian):
   `{ processors:[], template_pools:[], jigsaws:[], structure_sets:[] }` — quatro `ListTag` de `TAG_Compound` **vazias**.
2. `VoxelShapesPacket::create([], [])` → bytes `00 00 00 00` (shapes 0, nameMap 0, customShapeCount LShort 0).

Sem os dois, o cliente 26.60 mostra **"Dados de estrutura ausentes no servidor"** e fecha.

### `StartGamePacket` / `LevelSettings.php`
- `LevelSettings`: escrever `EditorLevelMigrationVersion` (`putByte(0)`) entre `exportedFromEditorMode` e `putVarInt($this->time)`.

### `LevelChunkPacket.php`
- `encodePayload`: `putBool(false)` (**ClientBiomeUpdate**, novo no 1.26.60) depois do `extraPayload`.

### `ChunkSerializer.php`
- `serializeSubChunk`: versão do subchunk **8 → 9**, com o byte de índice Y (`putByte($subChunkY & 0xff)`); `serializeSubChunks` passa o `$y`.

### `AddActorPacket.php` e `AddPlayerPacket.php`
- Campo opcional **PassengerOfBlock** (`putBool(false)`).

### `PlayerListPacket.php` + `serializer/PacketSerializer.php` (PlayFabID)
- No 1.26.60 o **PlayFabID saiu da skin** e foi pro **corpo da entry**.
- `PlayerListPacket`: escrever `putString($entry->skinData->getPlayFabId())` **entre o XUID e o platformChatId**.
- `PacketSerializer::putSkin`: **não** escrever o PlayFabId no 2225 (`&& getProtocolId() < PROTOCOL_1_26_62`).
- *Sintoma se faltar:* o cliente lê o platformChatId a partir do `BuildPlatform -1` (`ff ff ff ff`), interpreta como string de ~4 GB e cai calado ~1s depois.

### `AnimatePacket.php`
- Campo novo **`hand` (u8)** no fim (depois do `swingSource` Optional). Prop `public int $hand = 0;` + decode `$this->hand = $in->getByte()` + encode `$out->putByte($this->hand)`, gated 2225.

---

## 3. Pacotes serverbound — input e interação

### `PlayerAuthInputPacket.php`
- **InputData (flags):** no 26.60 é `varuint count + count × varint32 (zigzag)` com os IDs dos flags ligados — **não** o bool-guard + lista do 1.26.40. (Fix: no 2225, ler `count` direto, sem o `getBool()` externo.) `NUMBER_OF_FLAGS` já é 66; flag 48 = `BLOCK_BREAKING_DELAY_ENABLED`.
- **5 Optionals** (itemInteractionData, itemStackRequest, blockActions, vehicleRotation, vehicleActorUniqueId): no 26.60 cada um é **1 bool + valor**. O código antigo fazia `if(getBool()){ readOptional(...) }` = bool duplo. (Fix: no 2225, só `readOptional(...)` — curto-circuito `>= 2225 || getBool()`.)

### `types/ItemInteractionData.php`
- `read`: os **legacy slots** viraram **Optional (bool)**, não mais condicionados a `requestId !== 0`.

### `types/inventory/TransactionData.php`
- `decodeAuthInput`: no 2225, **depois das actions, chamar `$this->decodeData($stream)`** (o corpo da transação — actionType, blockPos, face, hand, item, etc. — voltou pro auth-input no 26.60).

### `types/inventory/UseItemTransactionData.php`
- Campo novo **`hand` (u8)** depois do `hotbarSlot` (decode + encode + prop `private int $hand = 0;`). Vale também pro `InventoryTransactionPacket` standalone.

### `types/inventory/NetworkInventoryAction.php`
- `readAuthInput`, `read`, `write`, `writeAuthInput`: **optional único** pra `windowId` e `sourceFlags` no 2225 (não o `readOptional(fn()=>readOptional(...))` duplo do 1.26.40).

### `types/inventory/stackresponse/ItemStackResponseSlotInfo.php`
- `itemStackId`: **optional único** (não `readDoubleOptional`/`writeDoubleOptional`).

### `types/inventory/stackresponse/ItemStackResponse.php` (S->C)
- `write`/`read` pra `>= 1.26.40` tinha um **optional DUPLO manual** (`putBool($has) + putBool(true)` / `getBool() && getBool()`) que **não passa pelo helper** Dummy. No 26.60 é **bool único** (gophertunnel `ItemStackResponse.Marshal`).
- *Sintoma se faltar:* selecionar receita no livro de crafting derruba o cliente ("Block"); o validador marca o `0x94 ItemStackResponse` com bytes sobrando e o cliente manda `PacketViolationWarning: readNoHeader failed! packetId: 148`.

### `serializer/PacketSerializer.php` — **o fix centralizado**
- `readDummyOptional()` e `writeDummyOptional()` viram **no-op no 2225** (`if(getProtocolId() >= PROTOCOL_1_26_62) return;`).
- Como `readDoubleOptional`/`writeDoubleOptional` chamam os Dummy, isso cobre de uma vez: `InventoryTransactionPacket` (dummy antes/depois do transactionType), o encoder do `PlayerAuthInputPacket`, e os sites de Double optional. O 1.26.40 ainda escreve/lê o dummy; o 26.60 removeu.

---

## 4. Dispatch de gameplay — **quebrar/colocar bloco**

### `handler/InGamePacketHandler.php` — `handlePlayerAuthInput`
- A condição `if(getProtocolId() >= PROTOCOL_1_26_30){ syncMismatchedPredictedSlotChanges(); }` (que **ignorava** a transação, pois "1.26.30+ não carrega mais use-item data") vira:
  `if(getProtocolId() >= PROTOCOL_1_26_30 && getProtocolId() < PROTOCOL_1_26_62){ ... }`
- Assim o 2225 cai no `handleUseItemTransaction()` → `ACTION_BREAK_BLOCK`/`ACTION_CLICK_BLOCK` → `breakBlock()`/colocar.
- *Sem isso:* o bloco quebra **visualmente** mas o servidor nunca manda o evento 2001 (destroy) — não dropa nada e não reverte.

---

## 5. Achados nos testes de gameplay (crafting, baú, itens)

### `types/inventory/stackrequest/ItemStackRequest.php`
- O 26.60 inseriu **`CraftReserved` (id 18)** no enum de ações, depois do `CraftLoom` (payload: `string reservedId` + `u8 numberOfCrafts`). `NonImplementedDeprecated` foi pra 19 e `CraftResultsDeprecated` pra **20**.
- Fix (2225): depois do `fromModernTypeId`, o 18 é lido e descartado, e quem for > 18 volta uma posição. O `write` soma 1 pros tipos >= 18.
- *Sintoma:* pegar o resultado do crafting → `Unhandled item stack request action type 20`.

### `types/inventory/stackrequest/DeprecatedCraftingResultsStackRequestAction.php`
- Os itens de resultado agora vêm como **StackRequestItem**: `varuint variant`, `u8 legacy`, `[string identifier + varint meta]`, `int16 count`, `varuint blockRuntimeId`, `bytes userData`. O PocketMine ignora essa ação, então no 2225 ela é lida no formato novo e descartada.
- *Sintoma:* `ItemStackRequestPacket: Not enough bytes left in buffer`.

### `handler/ItemStackRequestExecutor.php` (bug antigo, todas as versões)
- O `CraftingDataCache` envia `netId = índice + RECIPE_ID_OFFSET (1)`, mas o `beginCrafting` buscava `getCraftingRecipeFromIndex($recipeId)` sem descontar. Todo craft pelo livro de receitas usava a receita **vizinha**.
- Fix: `getCraftingRecipeFromIndex($recipeId - CraftingDataCache::RECIPE_ID_OFFSET)`.
- *Sintoma:* `Not enough created items available to be taken (have 3, tried to take 4)` e ressincronização completa do inventário.

### `NetworkSession.php`
- O cliente 26.60 manda pacotes novos, como o `ServerboundCursorItemDrag` (0x166, `u8 state`) ao arrastar item num baú. O Essential derrubava o jogador com `Unknown packet received`.
- Fix (2225): pacote desconhecido vira log de debug e é ignorado, como no BDS.

---

## Como aplicar

- **Diff completo:** [`essential/essential-1.26.62.patch`](../../essential/essential-1.26.62.patch), contra o commit `7b9f7cf8` do BakuTeam/Essential, mais os dois JSON de paleta em [`essential/data`](../../essential/data).
- **Phar pronto:** anexado na release do repositório.
- Os scripts usados durante o porte (patch in-place no phar via Phar API, com checagem `count == 1` e `php -l`) estão em [`essential/scripts`](../../essential/scripts), como referência.
