package main

import (
	"encoding/hex"
	"os"
	"strings"
	"testing"

	"github.com/sandertv/gophertunnel/minecraft/protocol/packet"
)

func TestValidator(t *testing.T) {
	raw, err := os.ReadFile("testdata/startgame-essential.hex")
	if err != nil {
		t.Skip("no dump")
	}
	sg, _ := hex.DecodeString(strings.TrimSpace(string(raw)))
	v := newValidator()
	if id, problem := v.check(sg); problem != "" || id != packet.IDStartGame {
		t.Fatalf("valid StartGame rejected: %x %s", id, problem)
	}
	broken := append(append([]byte{}, sg...), 0x00, 0x01)
	if _, problem := v.check(broken); problem == "" {
		t.Fatal("left-over bytes not detected")
	}
	if _, problem := v.check(sg[:len(sg)/2]); problem == "" {
		t.Fatal("truncated packet not detected")
	}
}
