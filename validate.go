package main

import (
	"bytes"
	"encoding/binary"
	"fmt"
	"sync"

	"github.com/sandertv/gophertunnel/minecraft/protocol"
	"github.com/sandertv/gophertunnel/minecraft/protocol/packet"
)

// validator decodes every server->client packet with gophertunnel 26.60 and reports
// the ones that do not decode exactly. It finds encoder bugs in the upstream server
// before the client silently disconnects.
type validator struct {
	pool   packet.Pool
	shield int32
	seen   map[string]int
	mu     sync.Mutex
}

func newValidator() *validator {
	return &validator{pool: packet.NewServerPool(), seen: map[string]int{}}
}

// check returns a description of the problem, or "" if the packet decoded cleanly.
func (v *validator) check(p []byte) (id uint32, problem string) {
	h, n := binary.Uvarint(p)
	if n <= 0 {
		return 0, "invalid header"
	}
	id = uint32(h & 0x3ff)
	f, ok := v.pool[id]
	if !ok {
		return id, "" // packet unknown to gophertunnel: cannot validate
	}
	pk := f()
	buf := bytes.NewBuffer(p[n:])
	defer func() {
		if r := recover(); r != nil {
			problem = fmt.Sprintf("%v (consumiu %d de %d bytes)", r, len(p[n:])-buf.Len(), len(p[n:]))
		}
	}()
	pk.Marshal(protocol.NewReader(buf, v.shield, false))
	if ir, ok := pk.(*packet.ItemRegistry); ok {
		for _, it := range ir.Items {
			if it.Name == "minecraft:shield" {
				v.shield = int32(it.RuntimeID)
			}
		}
	}
	if buf.Len() != 0 {
		return id, fmt.Sprintf("%d bytes left over out of %d", buf.Len(), len(p[n:]))
	}
	return id, ""
}

// firstReport reports whether this problem is seen for the first time (so the log
// is not flooded with the same error every tick).
func (v *validator) firstReport(id uint32, problem string) bool {
	v.mu.Lock()
	defer v.mu.Unlock()
	k := fmt.Sprintf("%x:%s", id, problem)
	v.seen[k]++
	return v.seen[k] == 1
}
