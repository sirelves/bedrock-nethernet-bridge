package main

import (
	"bytes"
	"compress/flate"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/golang/snappy"
)

// gate sits between the NetherNet client and the upstream until the Login is verified.
// Without Minecraft's encryption, what prevents replaying a Login captured on another
// server is requiring that the Login key (x5u of the client data, which the upstream
// checks against the Xbox chain) is the same key the client proved to own in the
// NetherNet a=identity / DTLS handshake.
// check returns nil if the batch may pass. Once the Login is verified, everything passes.
// splitBatch opens a NetherNet batch (no 0xfe). Before NetworkSettings the batch is
// raw; afterwards the first byte is the algorithm: 0x00 flate, 0x01 snappy, 0xff none.
func splitBatch(b []byte) ([][]byte, error) {
	if len(b) == 0 {
		return nil, errors.New("empty batch")
	}
	if pks, err := readPackets(b); err == nil && isRequestNetworkSettings(pks) {
		return pks, nil
	}
	var data []byte
	switch b[0] {
	case 0xff:
		data = b[1:]
	case 0x00:
		r := flate.NewReader(bytes.NewReader(b[1:]))
		d, err := io.ReadAll(io.LimitReader(r, maxDecompressed))
		if err != nil {
			return nil, fmt.Errorf("flate: %w", err)
		}
		data = d
	case 0x01:
		d, err := snappy.Decode(nil, b[1:])
		if err != nil {
			return nil, fmt.Errorf("snappy: %w", err)
		}
		data = d
	default:
		return nil, fmt.Errorf("unknown compression 0x%x", b[0])
	}
	return readPackets(data)
}

func isRequestNetworkSettings(pks [][]byte) bool {
	if len(pks) != 1 {
		return false
	}
	id, n := binary.Uvarint(pks[0])
	return n > 0 && id&0x3ff == idRequestNetworkSettings
}

func readPackets(b []byte) ([][]byte, error) {
	var out [][]byte
	for len(b) > 0 {
		l, n := binary.Uvarint(b)
		if n <= 0 || l == 0 || uint64(len(b)-n) < l {
			return nil, errors.New("malformed batch")
		}
		out = append(out, b[n:n+int(l)])
		b = b[n+int(l):]
	}
	return out, nil
}

// packetIDs returns the packet IDs of a batch (for tracing). Batches that cannot be
// opened (e.g. unknown compression) return nil.
func packetIDs(batch []byte) []uint64 {
	pks, err := splitBatch(batch)
	if err != nil {
		return nil
	}
	ids := make([]uint64, 0, len(pks))
	for _, pk := range pks {
		id, n := binary.Uvarint(pk)
		if n > 0 {
			ids = append(ids, id&0x3ff)
		}
	}
	return ids
}

// dumpPackets writes, as hex, every packet of the batch whose ID is in want (nil = all)
// to dir, including the header varuint. It returns how many were written.
func dumpPackets(dir, prefix string, batch []byte, want map[uint64]bool, seq *int) int {
	pks, err := splitBatch(batch)
	if err != nil {
		return 0
	}
	n := 0
	for _, pk := range pks {
		id, m := binary.Uvarint(pk)
		if m <= 0 || (want != nil && !want[id&0x3ff]) {
			continue
		}
		*seq++
		name := fmt.Sprintf("%s/%s-%03d-%x.hex", dir, prefix, *seq, id&0x3ff)
		if os.WriteFile(name, []byte(hex.EncodeToString(pk)+"\n"), 0o644) == nil {
			n++
		}
	}
	return n
}

// filterBatch removes the packets whose IDs are in drop from a server->client batch
// and recompresses with the same algorithm. Batches that cannot be opened pass intact.
func filterBatch(batch []byte, drop map[uint64]bool) ([]byte, []uint64) {
	if len(drop) == 0 || len(batch) == 0 {
		return batch, nil
	}
	pks, err := splitBatch(batch)
	if err != nil {
		return batch, nil
	}
	var kept []byte
	var removed []uint64
	for _, pk := range pks {
		id, n := binary.Uvarint(pk)
		if n > 0 && drop[id&0x3ff] {
			removed = append(removed, id&0x3ff)
			continue
		}
		kept = binary.AppendUvarint(kept, uint64(len(pk)))
		kept = append(kept, pk...)
	}
	if len(removed) == 0 {
		return batch, nil
	}
	return recompress(batch[0], kept), removed
}

// recompress builds a batch from the algorithm byte and already serialised packets.
func recompress(algo byte, raw []byte) []byte {
	switch algo {
	case 0x00:
		var buf bytes.Buffer
		buf.WriteByte(0x00)
		w, _ := flate.NewWriter(&buf, 6)
		_, _ = w.Write(raw)
		_ = w.Close()
		return buf.Bytes()
	case 0x01:
		return append([]byte{0x01}, snappy.Encode(nil, raw)...)
	default:
		return append([]byte{0xff}, raw...)
	}
}

// loadDrop reads hex packet IDs (one per line, or separated by commas/spaces).
func loadDrop(path string) map[uint64]bool {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	m := map[uint64]bool{}
	for _, f := range strings.FieldsFunc(string(b), func(r rune) bool { return r == ',' || r == ' ' || r == '\n' || r == '\t' }) {
		f = strings.TrimPrefix(strings.TrimSpace(f), "0x")
		if f == "" || strings.HasPrefix(f, "#") {
			continue
		}
		var v uint64
		if _, err := fmt.Sscanf(f, "%x", &v); err == nil {
			m[v] = true
		}
	}
	return m
}
