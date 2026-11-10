package main

import (
	"crypto/ecdsa"
	"crypto/x509"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
)

const (
	idLogin                  = 0x01
	idRequestNetworkSettings = 0xc1
	maxDecompressed          = 4 << 20
)

type gate struct {
	peerKey *ecdsa.PublicKey
	done    bool
	logged  bool
}

func (g *gate) check(batch []byte) error {
	if g.done {
		return nil
	}
	pks, err := splitBatch(batch)
	if err != nil {
		return err
	}
	for _, pk := range pks {
		id, n := binary.Uvarint(pk)
		if n <= 0 {
			return errors.New("invalid packet header")
		}
		switch id & 0x3ff {
		case idRequestNetworkSettings:
		case idLogin:
			if err := g.verifyLogin(pk[n:]); err != nil {
				return err
			}
			g.done = true
		default:
			return fmt.Errorf("packet 0x%x before Login", id&0x3ff)
		}
	}
	return nil
}

func (g *gate) verifyLogin(b []byte) error {
	if g.peerKey == nil {
		return errors.New("client has no NetherNet identity (a=identity)")
	}
	if len(b) < 4 {
		return errors.New("Login too short")
	}
	b = b[4:] // protocol (int32 BE)
	l, n := binary.Uvarint(b)
	if n <= 0 || uint64(len(b)-n) < l {
		return errors.New("invalid connection request")
	}
	req := b[n : n+int(l)]
	_, rest, err := readLE32String(req) // auth chain / token
	if err != nil {
		return err
	}
	clientData, _, err := readLE32String(rest)
	if err != nil {
		return err
	}
	key, err := jwtX5U(clientData)
	if err != nil {
		return fmt.Errorf("client data: %w", err)
	}
	if !key.Equal(g.peerKey) {
		return errors.New("Login key differs from the NetherNet identity (possible replay)")
	}
	return nil
}

func readLE32String(b []byte) (string, []byte, error) {
	if len(b) < 4 {
		return "", nil, errors.New("string too short")
	}
	l := binary.LittleEndian.Uint32(b)
	if uint64(len(b)-4) < uint64(l) {
		return "", nil, errors.New("truncated string")
	}
	return string(b[4 : 4+l]), b[4+l:], nil
}

func jwtX5U(token string) (*ecdsa.PublicKey, error) {
	head, _, ok := strings.Cut(token, ".")
	if !ok {
		return nil, errors.New("invalid JWT")
	}
	raw, err := base64.RawURLEncoding.DecodeString(strings.TrimRight(head, "="))
	if err != nil {
		return nil, err
	}
	var h struct {
		X5U string `json:"x5u"`
	}
	if err := json.Unmarshal(raw, &h); err != nil {
		return nil, err
	}
	der, err := base64.StdEncoding.DecodeString(h.X5U)
	if err != nil {
		return nil, err
	}
	pub, err := x509.ParsePKIXPublicKey(der)
	if err != nil {
		return nil, err
	}
	k, ok := pub.(*ecdsa.PublicKey)
	if !ok {
		return nil, errors.New("x5u is not an ECDSA key")
	}
	return k, nil
}
