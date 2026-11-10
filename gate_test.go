package main

import (
	"bytes"
	"compress/flate"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/base64"
	"encoding/binary"
	"testing"
)

func fakeJWT(t *testing.T, k *ecdsa.PublicKey) string {
	der, err := x509.MarshalPKIXPublicKey(k)
	if err != nil {
		t.Fatal(err)
	}
	h := `{"alg":"ES384","x5u":"` + base64.StdEncoding.EncodeToString(der) + `"}`
	return base64.RawURLEncoding.EncodeToString([]byte(h)) + ".e30.sig"
}

func le32(s string) []byte {
	b := make([]byte, 4)
	binary.LittleEndian.PutUint32(b, uint32(len(s)))
	return append(b, s...)
}

func pkt(id uint64, body []byte) []byte {
	p := binary.AppendUvarint(nil, id)
	p = append(p, body...)
	return append(binary.AppendUvarint(nil, uint64(len(p))), p...)
}

func loginBatch(t *testing.T, k *ecdsa.PublicKey, comp byte) []byte {
	req := append(le32(`{"Certificate":"x"}`), le32(fakeJWT(t, k))...)
	body := []byte{0, 0, 0x08, 0xb1}
	body = append(body, binary.AppendUvarint(nil, uint64(len(req)))...)
	body = append(body, req...)
	raw := pkt(idLogin, body)
	if comp == 0xff {
		return append([]byte{0xff}, raw...)
	}
	var buf bytes.Buffer
	w, _ := flate.NewWriter(&buf, 6)
	w.Write(raw)
	w.Close()
	return append([]byte{0x00}, buf.Bytes()...)
}

func TestGate(t *testing.T) {
	a, _ := ecdsa.GenerateKey(elliptic.P384(), rand.Reader)
	b, _ := ecdsa.GenerateKey(elliptic.P384(), rand.Reader)
	rns := pkt(idRequestNetworkSettings, []byte{0, 0, 0x08, 0xb1})

	for _, comp := range []byte{0x00, 0xff} {
		g := &gate{peerKey: &a.PublicKey}
		if err := g.check(rns); err != nil {
			t.Fatalf("RequestNetworkSettings: %v", err)
		}
		if err := g.check(loginBatch(t, &a.PublicKey, comp)); err != nil || !g.done {
			t.Fatalf("legitimate login rejected (comp %x): %v", comp, err)
		}
		g2 := &gate{peerKey: &a.PublicKey}
		if err := g2.check(loginBatch(t, &b.PublicKey, comp)); err == nil {
			t.Fatalf("replay accepted (comp %x)", comp)
		}
	}
	if err := (&gate{}).check(loginBatch(t, &a.PublicKey, 0)); err == nil {
		t.Fatal("accepted without NetherNet identity")
	}
	if err := (&gate{peerKey: &a.PublicKey}).check(pkt(0x09, []byte{1})); err == nil {
		t.Fatal("accepted packet before Login")
	}
}

func TestFilterBatch(t *testing.T) {
	raw := append(pkt(0x91, []byte{1, 2, 3}), pkt(0x3f, []byte{4, 5})...)
	var buf bytes.Buffer
	w, _ := flate.NewWriter(&buf, 6)
	w.Write(raw)
	w.Close()
	batch := append([]byte{0x00}, buf.Bytes()...)
	out, removed := filterBatch(batch, map[uint64]bool{0x91: true})
	if len(removed) != 1 || removed[0] != 0x91 {
		t.Fatalf("removed = %v", removed)
	}
	ids := packetIDs(out)
	if len(ids) != 1 || ids[0] != 0x3f {
		t.Fatalf("ids after filter = %x", ids)
	}
	if same, _ := filterBatch(batch, map[uint64]bool{0x01: true}); !bytes.Equal(same, batch) {
		t.Fatal("batch without removals should pass intact")
	}
}
