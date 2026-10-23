// Package patchwatch reports a Go service's runtime and modules to Patchwatch.
// No dependencies beyond the standard library. Copy this file into your module,
// for example as internal/patchwatch/patchwatch.go.
//
//	patchwatch.Start(ctx) // on boot, then every 6 hours
//
// The module list comes from the build info embedded in the binary, so it is
// exactly what was compiled in, not what go.mod hopes for.
package patchwatch

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"runtime"
	"runtime/debug"
	"time"
)

// Interval between heartbeats sent by Start.
const Interval = 6 * time.Hour

// Dependency is one entry of the heartbeat manifest.
type Dependency struct {
	Ecosystem string `json:"ecosystem"`
	Name      string `json:"name"`
	Version   string `json:"version"`
}

// Dependencies returns the Go runtime plus every module linked into this binary.
func Dependencies() []Dependency {
	deps := []Dependency{{Ecosystem: "runtime", Name: "go", Version: runtime.Version()}}

	info, ok := debug.ReadBuildInfo()
	if !ok {
		return deps
	}

	for _, mod := range info.Deps {
		// A replace directive means the replacement is what actually got compiled.
		if mod.Replace != nil {
			mod = mod.Replace
		}
		// Local replacements (../mylib) have no version to check.
		if mod.Version == "" || mod.Version == "(devel)" {
			continue
		}
		deps = append(deps, Dependency{Ecosystem: "go", Name: mod.Path, Version: mod.Version})
	}

	return deps
}

// Report sends one heartbeat to PATCHWATCH_URL using PATCHWATCH_TOKEN.
func Report(ctx context.Context) error {
	url, token := os.Getenv("PATCHWATCH_URL"), os.Getenv("PATCHWATCH_TOKEN")
	if url == "" || token == "" {
		return errors.New("set PATCHWATCH_URL and PATCHWATCH_TOKEN")
	}

	body, err := json.Marshal(map[string][]Dependency{"dependencies": Dependencies()})
	if err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 300 {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		return fmt.Errorf("patchwatch heartbeat failed: %s %s", resp.Status, msg)
	}

	return nil
}

// Start reports now and then every Interval until ctx is cancelled. Failures are
// logged, never returned, so a Patchwatch outage can't take your service down.
func Start(ctx context.Context) {
	go func() {
		ticker := time.NewTicker(Interval)
		defer ticker.Stop()

		for {
			if err := Report(ctx); err != nil && ctx.Err() == nil {
				log.Printf("[patchwatch] %v", err)
			}

			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
			}
		}
	}()
}
