package main

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"os"

	"github.com/sandertv/gophertunnel/minecraft/protocol"
	"github.com/sandertv/gophertunnel/minecraft/protocol/packet"
)

// patchStartGame overrides fields of the server's StartGame with the JSON object in
// path (gophertunnel 26.60 packet.StartGame field names). It isolates which value the
// client rejects without restarting the upstream. It returns the re-encoded packet
// (with header) or nil if nothing changed.
func patchStartGame(pk []byte, path string) ([]byte, error) {
	patch, err := os.ReadFile(path)
	if err != nil || len(bytes.TrimSpace(patch)) == 0 {
		return nil, nil
	}
	header, n := binary.Uvarint(pk)
	if n <= 0 || header&0x3ff != packet.IDStartGame {
		return nil, nil
	}
	sg := &packet.StartGame{}
	if err := decodeInto(sg, pk[n:]); err != nil {
		return nil, fmt.Errorf("decode StartGame: %w", err)
	}
	if err := json.Unmarshal(patch, sg); err != nil {
		return nil, fmt.Errorf("patch JSON: %w", err)
	}
	buf := bytes.NewBuffer(binary.AppendUvarint(nil, header))
	sg.Marshal(protocol.NewWriter(buf, 0))
	return buf.Bytes(), nil
}

func decodeInto(pk packet.Packet, b []byte) (err error) {
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("%v", r)
		}
	}()
	r := bytes.NewBuffer(b)
	pk.Marshal(protocol.NewReader(r, 0, false))
	if r.Len() != 0 {
		return fmt.Errorf("%d bytes left over", r.Len())
	}
	return nil
}

// rewriteBatch replaces each packet of a server->client batch with fn(packet) when fn
// returns non-nil, and recompresses with the original algorithm.
func rewriteBatch(batch []byte, fn func([]byte) []byte) []byte {
	pks, err := splitBatch(batch)
	if err != nil {
		return batch
	}
	changed := false
	var raw []byte
	for _, p := range pks {
		if np := fn(p); np != nil {
			p, changed = np, true
		}
		raw = binary.AppendUvarint(raw, uint64(len(p)))
		raw = append(raw, p...)
	}
	if !changed {
		return batch
	}
	return recompress(batch[0], raw)
}

// encodePacket serialises a gophertunnel packet including its ID header.
func encodePacket(pk packet.Packet) []byte {
	buf := bytes.NewBuffer(binary.AppendUvarint(nil, uint64(pk.ID())))
	pk.Marshal(protocol.NewWriter(buf, 0))
	return buf.Bytes()
}

// preJoinPackets are the packets 26.60 clients require before StartGame and that
// older servers (PocketMine forks) do not send. Without JigsawStructureData the
// client aborts with "Missing structure data on server" (Boat / InitialConnection-148).
func preJoinPackets() [][]byte {
	return [][]byte{
		encodePacket(&packet.JigsawStructureData{StructureData: map[string]any{
			"processors":     make([]map[string]any, 0),
			"template_pools": make([]map[string]any, 0),
			"jigsaws":        make([]map[string]any, 0),
			"structure_sets": make([]map[string]any, 0),
		}}),
		encodePacket(&packet.VoxelShapes{}),
	}
}

// injectPreJoin inserts preJoinPackets right before StartGame when the upstream did
// not send them. It returns the recompressed batch.
func injectPreJoin(batch []byte) ([]byte, bool) {
	pks, err := splitBatch(batch)
	if err != nil {
		return batch, false
	}
	idx := -1
	for i, p := range pks {
		id, n := binary.Uvarint(p)
		if n <= 0 {
			continue
		}
		switch id & 0x3ff {
		case packet.IDJigsawStructureData, packet.IDVoxelShapes:
			return batch, false // the upstream already sends them
		case packet.IDStartGame:
			idx = i
		}
	}
	if idx < 0 {
		return batch, false
	}
	var raw []byte
	for i, p := range pks {
		if i == idx {
			for _, inj := range preJoinPackets() {
				raw = binary.AppendUvarint(raw, uint64(len(inj)))
				raw = append(raw, inj...)
			}
		}
		raw = binary.AppendUvarint(raw, uint64(len(p)))
		raw = append(raw, p...)
	}
	return recompress(batch[0], raw), true
}

// authInputWithOptionals reports whether a PlayerAuthInput (whole packet, with header)
// carries any of the 26.60 optional fields. Only used to capture real samples for
// diagnostics.
func authInputWithOptionals(p []byte) (string, bool) {
	id, n := binary.Uvarint(p)
	if n <= 0 || id&0x3ff != packet.IDPlayerAuthInput {
		return "", false
	}
	pk := &packet.PlayerAuthInput{}
	if err := decodeInto(pk, p[n:]); err != nil {
		return "decode-erro", true
	}
	var tags []string
	if has(pk.ItemInteractionData) {
		tags = append(tags, "item")
	}
	if has(pk.ItemStackRequest) {
		tags = append(tags, "stack")
	}
	if has(pk.BlockActions) {
		tags = append(tags, "block")
	}
	if has(pk.VehicleRotation) {
		tags = append(tags, "veh")
	}
	if len(tags) == 0 {
		return "", false
	}
	return fmt.Sprint(tags), true
}

func has[T any](o protocol.Optional[T]) bool {
	_, ok := o.Value()
	return ok
}
