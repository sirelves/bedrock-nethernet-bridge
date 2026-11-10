package main

import (
	"encoding/hex"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"time"
)

// diagConfig holds the opt-in diagnostics used to port a server to a new protocol.
// None of them are needed in production.
type diagConfig struct {
	trace          bool
	validate       bool
	dumpDir        string
	dropFile       string
	startGamePatch string
}

func (c *diagConfig) register() {
	flag.BoolVar(&c.trace, "trace", false, "diagnostics: log the packet IDs of every batch in both directions")
	flag.BoolVar(&c.validate, "validate", false, "diagnostics: decode every server->client packet with gophertunnel 26.60 and log the ones that do not round-trip")
	flag.StringVar(&c.dumpDir, "dump-dir", "", "diagnostics: write raw packets (hex) to this directory (spawn sequence, invalid packets, inventory/use packets)")
	flag.StringVar(&c.dropFile, "drop-file", "", "diagnostics: file with hex packet IDs to strip from server->client batches, re-read per session (bisection)")
	flag.StringVar(&c.startGamePatch, "startgame-patch", "", "diagnostics: JSON object of gophertunnel StartGame fields to override")
}

// diagSession is the per-connection state of the diagnostics.
type diagSession struct {
	cfg diagConfig
	log *slog.Logger
	val *validator

	prefix                             string
	seq, valSeq, csSeq, optSeq, invSeq int
	drop                               map[uint64]bool
}

func (c diagConfig) session(log *slog.Logger) *diagSession {
	d := &diagSession{cfg: c, log: log, val: newValidator(), prefix: time.Now().UTC().Format("150405")}
	if c.dropFile != "" {
		d.drop = loadDrop(c.dropFile)
		if len(d.drop) > 0 {
			log.Info("bisection: stripping server->client packets", "ids", fmt.Sprintf("%x", mapKeys(d.drop)))
		}
	}
	return d
}

// clientBatch observes a client->server batch (never modifies it).
func (d *diagSession) clientBatch(batch []byte) {
	c := d.cfg
	if c.dumpDir != "" {
		cs := d.prefix + "-cs"
		if d.optSeq < 2000 {
			if pks, err := splitBatch(batch); err == nil {
				for _, p := range pks {
					if tag, ok := authInputWithOptionals(p); ok {
						d.optSeq++
						name := fmt.Sprintf("%s/%s-opt-%02d.hex", c.dumpDir, cs, d.optSeq)
						_ = os.WriteFile(name, []byte(hex.EncodeToString(p)+"\n"), 0o644)
						d.log.Info("PlayerAuthInput with optionals captured", "tags", tag, "file", name)
					}
				}
			}
		}
		if d.csSeq < 6 {
			dumpPackets(c.dumpDir, cs, batch, map[uint64]bool{0x90: true, 0x21: true}, &d.csSeq)
		}
		if d.invSeq < 40 {
			// inventory/use requests are decoded by the upstream, not by the S->C validator
			dumpPackets(c.dumpDir, cs+"-inv", batch, map[uint64]bool{0x93: true, 0x1e: true}, &d.invSeq)
		}
	}
	if c.trace {
		d.log.Info("C->S", "ids", fmt.Sprintf("%x", packetIDs(batch)), "len", len(batch))
	}
}

// serverBatch observes (and, for bisection/patching, may rewrite) a server->client
// batch. keep=false means the whole batch was stripped and must not be sent.
func (d *diagSession) serverBatch(batch []byte) (out []byte, keep bool) {
	c := d.cfg
	if c.startGamePatch != "" {
		batch = rewriteBatch(batch, func(p []byte) []byte {
			np, err := patchStartGame(p, c.startGamePatch)
			if err != nil {
				d.log.Warn("StartGame patch", "err", err)
			} else if np != nil {
				d.log.Info("StartGame rewritten by patch")
			}
			return np
		})
	}
	if len(d.drop) > 0 {
		nb, removed := filterBatch(batch, d.drop)
		if removed != nil {
			if len(packetIDs(nb)) == 0 {
				if c.trace {
					d.log.Info("stripped (whole batch dropped)", "ids", fmt.Sprintf("%x", removed))
				}
				return nil, false
			}
			batch = nb
			if c.trace {
				d.log.Info("stripped", "ids", fmt.Sprintf("%x", removed))
			}
		}
	}
	if c.dumpDir != "" {
		if d.seq < 80 {
			dumpPackets(c.dumpDir, d.prefix, batch, nil, &d.seq)
		} else if d.seq < 200 {
			// after spawn, only block break/drop related packets
			dumpPackets(c.dumpDir, d.prefix, batch, map[uint64]bool{0x19: true, 0x0f: true, 0x15: true, 0x11: true}, &d.seq)
		}
	}
	if c.validate {
		if pks, err := splitBatch(batch); err == nil {
			for _, p := range pks {
				if id, problem := d.val.check(p); problem != "" && d.val.firstReport(id, problem) {
					d.valSeq++
					name := ""
					if c.dumpDir != "" && d.valSeq <= 50 {
						name = fmt.Sprintf("%s/%s-invalid-%02d-%x.hex", c.dumpDir, d.prefix, d.valSeq, id)
						_ = os.WriteFile(name, []byte(hex.EncodeToString(p)+"\n"), 0o644)
					}
					d.log.Warn("S->C packet INVALID for protocol 2225", "id", fmt.Sprintf("0x%x", id), "problem", problem, "dump", name)
				}
			}
		}
	}
	if c.trace {
		d.log.Info("S->C", "ids", fmt.Sprintf("%x", packetIDs(batch)), "len", len(batch))
	}
	return batch, true
}

func mapKeys(m map[uint64]bool) []uint64 {
	out := make([]uint64, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
