package main

import (
	"bytes"
	"encoding/binary"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/sandertv/gophertunnel/minecraft/protocol/packet"
)

func TestPatchStartGame(t *testing.T) {
	raw, err := os.ReadFile("testdata/startgame-essential.hex")
	if err != nil {
		t.Skip("no dump")
	}
	orig, _ := hex.DecodeString(strings.TrimSpace(string(raw)))
	dir := t.TempDir()

	empty := filepath.Join(dir, "empty.json")
	os.WriteFile(empty, []byte("{}"), 0o644)
	same, err := patchStartGame(orig, empty)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(same, orig) {
		t.Fatalf("roundtrip is not identical: %d vs %d bytes", len(same), len(orig))
	}

	p := filepath.Join(dir, "p.json")
	os.WriteFile(p, []byte(`{"GameVersion":"1.26.62"}`), 0o644)
	out, err := patchStartGame(orig, p)
	if err != nil {
		t.Fatal(err)
	}
	sg := &packet.StartGame{}
	if err := decodeInto(sg, out[1:]); err != nil {
		t.Fatal(err)
	}
	if sg.GameVersion != "1.26.62" || sg.WorldName != "Teste 1.26.62 Athmos" {
		t.Fatalf("wrong patch: %q %q", sg.GameVersion, sg.WorldName)
	}
}

func TestInjectPreJoin(t *testing.T) {
	raw, err := os.ReadFile("testdata/startgame-essential.hex")
	if err != nil {
		t.Skip("no dump")
	}
	sg, _ := hex.DecodeString(strings.TrimSpace(string(raw)))
	batch := recompress(0x00, append(append([]byte{}, byte(len(sg)&0x7f|0x80), byte(len(sg)>>7)), sg...))
	out, ok := injectPreJoin(batch)
	if !ok {
		t.Fatal("did not inject")
	}
	ids := packetIDs(out)
	want := []uint64{packet.IDJigsawStructureData, packet.IDVoxelShapes, packet.IDStartGame}
	if len(ids) != 3 || ids[0] != want[0] || ids[1] != want[1] || ids[2] != want[2] {
		t.Fatalf("order = %x, want %x", ids, want)
	}
	if _, again := injectPreJoin(out); again {
		t.Fatal("injected twice")
	}
	pks, _ := splitBatch(out)
	for _, p := range pks[:2] {
		var pk packet.Packet
		if p[0] == byte(packet.IDJigsawStructureData&0x7f|0x80) {
			pk = &packet.JigsawStructureData{}
		} else {
			pk = &packet.VoxelShapes{}
		}
		_, n := binary.Uvarint(p)
		if err := decodeInto(pk, p[n:]); err != nil {
			t.Fatalf("%T does not decode: %v", pk, err)
		}
	}
}

