// Package patchwatch reports a Go service's runtime and modules to Patchwatch.
// No dependencies beyond the standard library. Copy this file into your module,
// for example as internal/patchwatch/patchwatch.go.
//
//	patchwatch.Start(ctx) // on boot, then every 6 hours
//
// The module list comes from the build info embedded in the binary, so it is
// exactly what was compiled in, not what go.mod hopes for. The functions the
// linker kept come from the binary too, so Patchwatch can tell whether the
// vulnerable function an advisory names is in there at all.
package patchwatch

import (
	"bytes"
	"context"
	"debug/elf"
	"debug/gosym"
	"debug/macho"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"regexp"
	"runtime"
	"runtime/debug"
	"strings"
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

// Symbols returns the functions linked into this binary, grouped by package,
// read from its own function table (pclntab) the way govulncheck reads a
// binary. The linker drops functions nothing calls, so a function that isn't
// here can't run. Names use the form advisories use: Func, Type.Method.
//
// ponytail: ELF and Mach-O only; Windows binaries report no symbols, so their
// advisories are graded without them.
func Symbols() (map[string][]string, error) {
	exe, err := os.Executable()
	if err != nil {
		return nil, err
	}

	data, text, err := pclntab(exe)
	if err != nil {
		return nil, err
	}

	table, err := gosym.NewTable(nil, gosym.NewLineTable(data, text))
	if err != nil {
		return nil, err
	}

	symbols := map[string][]string{}
	seen := map[string]bool{}

	for _, fn := range table.Funcs {
		pkg, name := splitSymbol(fn.Name)
		if pkg == "" || pkg == "main" || seen[pkg+"."+name] {
			continue
		}
		seen[pkg+"."+name] = true
		symbols[pkg] = append(symbols[pkg], name)
	}

	return symbols, nil
}

func pclntab(path string) ([]byte, uint64, error) {
	if f, err := elf.Open(path); err == nil {
		defer f.Close()
		pcln, text := f.Section(".gopclntab"), f.Section(".text")
		if pcln == nil || text == nil {
			return nil, 0, errors.New("no .gopclntab section")
		}
		data, err := pcln.Data()
		return data, text.Addr, err
	}

	if f, err := macho.Open(path); err == nil {
		defer f.Close()
		pcln, text := f.Section("__gopclntab"), f.Section("__text")
		if pcln == nil || text == nil {
			return nil, 0, errors.New("no __gopclntab section")
		}
		data, err := pcln.Data()
		return data, text.Addr, err
	}

	return nil, 0, errors.New("unsupported executable format")
}

// Closures, go/defer wrappers and package-level initialisers belong to the function they sit in.
var closure = regexp.MustCompile(`\.(func|gowrap|deferwrap)\d+.*$|^glob\..*$`)

// splitSymbol turns "golang.org/x/net/html.(*Tokenizer[...]).Next" into
// "golang.org/x/net/html" and "Tokenizer.Next".
func splitSymbol(full string) (string, string) {
	// Drop generic instantiations, which may themselves contain dots and slashes.
	var plain strings.Builder
	depth := 0
	for _, r := range full {
		switch {
		case r == '[':
			depth++
		case r == ']' && depth > 0:
			depth--
		case depth == 0:
			plain.WriteRune(r)
		}
	}
	full = plain.String()

	slash := strings.LastIndex(full, "/")
	dot := strings.Index(full[slash+1:], ".")
	if dot < 0 {
		return "", ""
	}

	pkg, name := full[:slash+1+dot], full[slash+2+dot:]
	name = closure.ReplaceAllString(strings.NewReplacer("(*", "", "(", "", ")", "").Replace(name), "")
	if name == "" {
		return "", ""
	}

	return pkg, name
}

// Report sends one heartbeat to PATCHWATCH_URL using PATCHWATCH_TOKEN.
func Report(ctx context.Context) error {
	url, token := os.Getenv("PATCHWATCH_URL"), os.Getenv("PATCHWATCH_TOKEN")
	if url == "" || token == "" {
		return errors.New("set PATCHWATCH_URL and PATCHWATCH_TOKEN")
	}

	payload := map[string]any{"dependencies": Dependencies()}
	if symbols, err := Symbols(); err == nil {
		payload["symbols"] = symbols
	} else {
		log.Printf("[patchwatch] no symbol scan: %v", err)
	}

	body, err := json.Marshal(payload)
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
