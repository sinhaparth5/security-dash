# Patchwatch reporter for Go

[`patchwatch.go`](patchwatch.go) is a single file that uses only the standard library. It sends a heartbeat containing two things:

- the Go runtime version the binary was built with (`runtime.Version()`)
- every module compiled into the binary, read from its embedded build info (`runtime/debug.ReadBuildInfo`)

Because the list comes from the binary itself, it matches what's actually deployed. It needs no `go.mod` or `go.sum` at runtime.

## 1. Get the URL and token

1. In Patchwatch, click **Register service**, or open an existing service and click **Issue new token**.
2. Copy the **Heartbeat URL** and the **Token**.
3. Set both as environment variables (secrets) on the service:

```bash
PATCHWATCH_URL=https://patchwatch.example.com/api/v1/telemetry/heartbeat
PATCHWATCH_TOKEN=12|xxxxxxxxxxxxxxxxxxxxxxxx
```

Every service posts to the same URL. The token identifies which service is reporting, so each service and environment needs its own token.

## 2. Add the file

Copy `patchwatch.go` into your module as its own package:

```text
yourservice/
├── go.mod
├── main.go
└── internal/
    └── patchwatch/
        └── patchwatch.go
```

## 3. Start it in `main`

`Start` reports immediately, then every 6 hours, until the context is cancelled:

```go
package main

import (
	"context"
	"os/signal"
	"syscall"

	"example.com/yourservice/internal/patchwatch"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	patchwatch.Start(ctx)

	// ... start your HTTP server, workers, etc. with ctx
	<-ctx.Done()
}
```

Failures are logged with `log.Printf` and never returned, so if Patchwatch is down your service keeps running.

### One-off report (CLI, cron job, deploy step)

Call `Report` directly to send exactly one heartbeat. It returns any error:

```go
if err := patchwatch.Report(ctx); err != nil {
	log.Fatal(err)
}
```

## Docker

Nothing extra is needed. The build info is embedded in the binary by default, including in static `scratch` or `distroless` images. Stripping symbols with `-ldflags="-s -w"` and `-trimpath` both keep it.

## Notes

- **Symbol scan:** each heartbeat also lists the functions linked into the binary, read from its own function table (the same data `govulncheck` reads). The linker drops code nothing calls, so when an advisory names vulnerable functions and none of them are in the binary, Patchwatch grades it as a warning instead of Red. Windows binaries skip this.

- **`replace` directives:** the replacement module is reported. Local path replacements (`=> ../mylib`) have no version, so they're skipped.
- **Your own module isn't reported.** Patchwatch only checks the modules you depend on.
- **Container images** are reported separately, from CI with Trivy. See [Report container image scans from CI](../../README.md#3-report-container-image-scans-from-ci).
- **`go run` vs `go build`:** both embed build info, so local testing works.

## Troubleshooting

| Response              | Fix                                                                                                           |
| --------------------- | ------------------------------------------------------------------------------------------------------------- |
| `401`                 | Token missing, mistyped or revoked. Issue a new one on the service page.                                      |
| `422`                 | Payload rejected. The response body names the field.                                                          |
| `429`                 | More than 60 requests a minute. The default 6-hour interval is far below that.                                |
| Only `go` is reported | The binary was built without module info (GOPATH mode or `-buildmode` without modules). Build it as a module. |
