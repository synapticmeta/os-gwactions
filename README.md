# os-gwactions

An OPNsense plugin that runs **actions when a gateway changes state** - built to
**restart all WireGuard tunnels on multi-WAN failover/failback**, so tunnels
re-establish over the new uplink instead of staying pinned to a dead WAN.

Adds **Services -> Gateway Actions** with:
- **Status** - live gateway states, WireGuard handshakes, event history (+ clear)
- **Rules** - watch gateways -> trigger (any/down/up) -> action, with a **delay**
  (wait before acting) and a **cooldown** (rate-limit against flapping); plus a
  **Run now** button to test an action without forcing a failover
- **Settings** - master enable switch

Built-in action presets: restart all WireGuard tunnels, restart IPsec, restart
Unbound (DNS), flush firewall states - or a custom command / configd action.

## Quick start
```sh
# build on the OPNsense box (FreeBSD + pkg + python3):
./build-pkg.sh 1.0.2
# install:
pkg add -f dist/os-gwactions-1.0.2.pkg
```
Then create a rule (e.g. watch your WAN gateways -> *Restart all WireGuard*,
cooldown 30s) and flip the master switch on.

## Repo layout
- `src/` - plugin files, mirroring `/usr/local` on the firewall
- `build-pkg.sh` - reproducible `pkg create` build
- `dist/` - built packages

> Note: a self-built local package shows as "(misconfigured)" in the OPNsense plugin
> list - that's expected and harmless (checksums/deps pass; it's just not from the
> official mirror).

## License
BSD-2-Clause.
