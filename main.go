// Command nethernet-bridge lets Minecraft: Bedrock Edition 1.26.60+ clients join a
// RakNet-only server (PocketMine-MP forks, older software) by terminating NetherNet
// (HTTP signaling on /v1/join + WebRTC data channels) and relaying the game batches,
// byte for byte, to the upstream RakNet server.
//
// The game protocol itself is not decoded on the hot path; only the transport changes:
//   - RakNet prefixes every batch with 0xfe, NetherNet does not.
//   - NetherNet sessions do not use Minecraft's AES encryption (DTLS already encrypts
//     the channel). The upstream must therefore skip encryption for sessions coming
//     from the bridge (see README).
package main

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"os"
	"time"

	"github.com/df-mc/go-nethernet"
	"github.com/df-mc/go-nethernet/endpoint"
	"github.com/pion/webrtc/v4"
	"github.com/sandertv/go-raknet"
)

// version is set at build time with -ldflags "-X main.version=...".
var version = "dev"

type config struct {
	httpAddr, udpAddr, publicIP, upstream, keyFile string

	injectPreJoin bool
}

func main() {
	var cfg config
	flag.StringVar(&cfg.httpAddr, "http", "0.0.0.0:19132", "TCP address for NetherNet HTTP signaling (/v1/join); clients use this port")
	flag.StringVar(&cfg.udpAddr, "udp", "0.0.0.0:19140", "single UDP address used for all WebRTC traffic (ICE UDP mux)")
	flag.StringVar(&cfg.publicIP, "public-ip", "", "public IPv4 announced in ICE candidates (required)")
	flag.StringVar(&cfg.upstream, "upstream", "127.0.0.1:19132", "RakNet server to relay to")
	flag.StringVar(&cfg.keyFile, "key", "server-identity.pem", "persistent ECDSA key for the server identity (clients pin it on first join over HTTP)")
	flag.BoolVar(&cfg.injectPreJoin, "inject-prejoin", true, "inject JigsawStructureData + VoxelShapes before StartGame when the upstream does not send them (required by 26.60 clients)")
	debug := flag.Bool("debug", false, "verbose logging (WebRTC/ICE/DTLS/SCTP)")
	showVersion := flag.Bool("version", false, "print version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println("nethernet-bridge", version)
		return
	}
	if net.ParseIP(cfg.publicIP).To4() == nil {
		fmt.Fprintln(os.Stderr, "error: -public-ip must be the server's public IPv4 address (for example -public-ip 203.0.113.10)")
		os.Exit(2)
	}

	level := slog.LevelInfo
	if *debug {
		level = slog.LevelDebug
	}
	log := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: level}))
	if err := run(log, cfg); err != nil {
		log.Error("fatal", "err", err)
		os.Exit(1)
	}
}

func run(log *slog.Logger, cfg config) error {
	key, err := loadOrCreateKey(cfg.keyFile)
	if err != nil {
		return fmt.Errorf("server identity key: %w", err)
	}

	// One UDP port for every peer keeps firewall rules simple.
	udpConn, err := net.ListenPacket("udp4", cfg.udpAddr)
	if err != nil {
		return fmt.Errorf("listen UDP: %w", err)
	}
	var se webrtc.SettingEngine
	se.SetICEUDPMux(webrtc.NewICEUDPMux(nil, udpConn))
	se.SetNAT1To1IPs([]string{cfg.publicIP}, webrtc.ICECandidateTypeHost)
	se.SetNetworkTypes([]webrtc.NetworkType{webrtc.NetworkTypeUDP4})
	api := webrtc.NewAPI(webrtc.WithSettingEngine(se))

	handler, err := endpoint.HandlerConfig{Logger: log}.Serve(cfg.httpAddr)
	if err != nil {
		return fmt.Errorf("serve HTTP signaling: %w", err)
	}

	l, err := nethernet.ListenConfig{
		Log: log,
		API: api,
		IssueServerIdentity: func(context.Context) (*nethernet.Identity, error) {
			return nethernet.ServerIdentity(key, "self")
		},
	}.Listen(handler)
	if err != nil {
		return fmt.Errorf("listen NetherNet: %w", err)
	}
	log.Info("bridge up", "version", version, "http", cfg.httpAddr, "udp", cfg.udpAddr, "public_ip", cfg.publicIP, "upstream", cfg.upstream)

	go syncStatus(log, handler, cfg.upstream)

	for {
		c, err := l.Accept()
		if err != nil {
			return fmt.Errorf("accept: %w", err)
		}
		go relay(log, cfg, c.(*nethernet.Conn))
	}
}

// syncStatus mirrors the upstream RakNet pong (MOTD, player count) into /v1/join.
func syncStatus(log *slog.Logger, h *endpoint.Handler, upstream string) {
	for {
		if pong, err := raknet.PingTimeout(upstream, 3*time.Second); err == nil {
			h.PongData(pong)
		} else {
			log.Warn("upstream ping failed", "err", err)
		}
		time.Sleep(5 * time.Second)
	}
}

func relay(log *slog.Logger, cfg config, nn *nethernet.Conn) {
	log = log.With("remote", nn.RemoteAddr().String())
	log.Info("NetherNet client connected")

	rk, err := raknet.DialTimeout(cfg.upstream, 10*time.Second)
	if err != nil {
		log.Error("dial upstream", "err", err)
		_ = nn.Close()
		return
	}
	log.Info("upstream RakNet session open", "local", rk.LocalAddr().String())

	done := make(chan error, 2)

	// client -> server: verify the Login, then add the 0xfe prefix RakNet expects.
	g := &gate{peerKey: nn.PublicKey()}
	go func() {
		for {
			pk, err := nn.ReadPacket()
			if err != nil {
				done <- fmt.Errorf("client (NetherNet) side closed: %w", err)
				return
			}
			if err := g.check(pk); err != nil {
				done <- fmt.Errorf("login rejected: %w", err)
				return
			}
			if g.done && !g.logged {
				g.logged = true
				log.Info("login verified: Login key matches the NetherNet identity")
			}
			buf := make([]byte, 0, len(pk)+1)
			buf = append(buf, 0xfe)
			buf = append(buf, pk...)
			if _, err := rk.Write(buf); err != nil {
				done <- err
				return
			}
		}
	}()

	// server -> client: strip the 0xfe prefix.
	go func() {
		for {
			pk, err := rk.ReadPacket()
			if err != nil {
				done <- fmt.Errorf("server (RakNet) side closed: %w", err)
				return
			}
			if len(pk) == 0 || pk[0] != 0xfe {
				log.Warn("upstream packet without 0xfe header dropped", "len", len(pk))
				continue
			}
			batch := pk[1:]
			if cfg.injectPreJoin {
				if nb, ok := injectPreJoin(batch); ok {
					batch = nb
					log.Info("injected JigsawStructureData + VoxelShapes before StartGame")
				}
			}
			if _, err := nn.Write(batch); err != nil {
				done <- err
				return
			}
		}
	}()

	err = <-done
	_ = nn.Close()
	_ = rk.Close()
	log.Info("session closed", "reason", err)
}

func loadOrCreateKey(path string) (*ecdsa.PrivateKey, error) {
	if b, err := os.ReadFile(path); err == nil {
		blk, _ := pem.Decode(b)
		if blk == nil {
			return nil, errors.New("invalid PEM in " + path)
		}
		return x509.ParseECPrivateKey(blk.Bytes)
	}
	key, err := ecdsa.GenerateKey(elliptic.P384(), rand.Reader)
	if err != nil {
		return nil, err
	}
	der, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, err
	}
	if err := os.WriteFile(path, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der}), 0o600); err != nil {
		return nil, err
	}
	return key, nil
}
