#!/usr/local/bin/python3

"""
    Copyright (c) 2026 synapticmeta
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

    Gateway Actions engine.

    Invoked by the monitor syshook (/usr/local/etc/rc.syshook.d/monitor/30-gwactions)
    whenever dpinger reports a gateway transition. argv[1] holds a comma separated
    list of the gateways whose monitor state just changed.

    The engine loads the rendered rule set (config.json), matches the affected
    gateways against each rule, applies a per-rule cooldown (debounce) and runs the
    configured action (e.g. restart all WireGuard tunnels). Every decision is written
    to a JSON-lines history log and to syslog.

    Usage:
        engine.py "GW1,GW2"      run as event handler for the given affected gateways
        engine.py --status        print a JSON status document (for the GUI/API)
"""

import sys
import os
import json
import time
import syslog
import subprocess

CONFIG = "/usr/local/etc/gwactions/config.json"
STATE_DIR = "/var/run/gwactions"
LOG_DIR = "/var/log/gwactions"
HISTORY = os.path.join(LOG_DIR, "history.log")
HISTORY_MAX = 500

CONFIGCTL = "/usr/local/sbin/configctl"
WG_CONTROL = "/usr/local/opnsense/scripts/wireguard/wg-service-control.php"

# named action presets: key -> argv list executed directly
ACTION_PRESETS = {
    "wireguard_restart_all": [WG_CONTROL, "-a", "restart"],
    "ipsec_restart": [CONFIGCTL, "ipsec", "restart"],
    "unbound_restart": [CONFIGCTL, "unbound", "restart"],
    "flush_states": ["/sbin/pfctl", "-F", "states"],
}


def _log(msg, prio=syslog.LOG_NOTICE):
    syslog.syslog(prio, msg)


def load_config():
    try:
        with open(CONFIG) as handle:
            return json.load(handle)
    except FileNotFoundError:
        return None
    except Exception as exc:
        _log("gwactions: cannot parse %s: %s" % (CONFIG, exc), syslog.LOG_ERR)
        return None


def gateway_statuses():
    """Return {gateway_name: status_string} from configctl."""
    try:
        out = subprocess.run(
            [CONFIGCTL, "interface", "gateways", "status"],
            capture_output=True, text=True, timeout=15,
        ).stdout
        data = json.loads(out)
        items = data.get("items") if isinstance(data, dict) else data
        return {item["name"]: item.get("status", "") for item in (items or [])}
    except Exception as exc:
        _log("gwactions: gateway status lookup failed: %s" % exc, syslog.LOG_ERR)
        return {}


def append_history(entry):
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        entry["ts"] = int(time.time())
        with open(HISTORY, "a") as handle:
            handle.write(json.dumps(entry) + "\n")
        _trim_history()
    except Exception as exc:
        _log("gwactions: cannot write history: %s" % exc, syslog.LOG_ERR)


def _trim_history():
    try:
        with open(HISTORY) as handle:
            lines = handle.readlines()
        if len(lines) > HISTORY_MAX:
            with open(HISTORY, "w") as handle:
                handle.writelines(lines[-HISTORY_MAX:])
    except Exception:
        pass


def run_action(action):
    """Execute a rule action. Returns dict(status, detail)."""
    if not action:
        return {"status": "error", "detail": "no action configured"}
    if action in ACTION_PRESETS:
        cmd = ACTION_PRESETS[action]
    elif action.startswith("cmd:"):
        cmd = ["/bin/sh", "-c", action[4:]]
    elif action.startswith("configd:"):
        cmd = [CONFIGCTL] + action[len("configd:"):].split()
    else:
        # bare configd action string, e.g. "unbound restart"
        cmd = [CONFIGCTL] + action.split()
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
        ok = proc.returncode == 0
        detail = (proc.stdout + proc.stderr).strip()
        return {"status": "ok" if ok else "failed", "detail": detail}
    except Exception as exc:
        return {"status": "error", "detail": str(exc)}


def handle_event(affected):
    cfg = load_config()
    if not cfg or not cfg.get("enabled", False):
        return 0
    statuses = gateway_statuses()
    os.makedirs(STATE_DIR, exist_ok=True)
    now = time.time()

    for rule in cfg.get("rules", []):
        if not rule.get("enabled", True):
            continue
        watch = set(rule.get("gateways", []))
        hit = sorted(watch.intersection(affected))
        if not hit:
            continue

        trigger = rule.get("trigger", "any")
        if trigger != "any":
            def is_down(name):
                return "down" in statuses.get(name, "")
            if trigger == "down" and not any(is_down(g) for g in hit):
                continue
            if trigger == "up" and not any(not is_down(g) for g in hit):
                continue

        rid = str(rule.get("uuid") or rule.get("name") or "rule").replace("/", "_")
        state_file = os.path.join(STATE_DIR, rid + ".last")
        cooldown = int(rule.get("cooldown", 30))
        last = 0.0
        try:
            with open(state_file) as handle:
                last = float(handle.read().strip() or 0)
        except Exception:
            pass

        if now - last < cooldown:
            left = round(cooldown - (now - last), 1)
            _log("gwactions: rule '%s' debounced (%.0fs left) for %s"
                 % (rule.get("name"), left, ",".join(hit)))
            append_history({
                "rule": rule.get("name"), "affected": hit,
                "trigger": trigger, "action": rule.get("action"),
                "result": "debounced", "cooldown_left": left,
            })
            continue

        # mark fired now, so repeat transitions during the delay/action debounce
        try:
            with open(state_file, "w") as handle:
                handle.write(str(now))
        except Exception:
            pass

        delay = int(rule.get("delay", 0) or 0)
        if delay > 0:
            _log("gwactions: rule '%s' waiting %ds before action" % (rule.get("name"), delay))
            time.sleep(min(delay, 600))

        _log("gwactions: rule '%s' firing action '%s' for %s"
             % (rule.get("name"), rule.get("action"), ",".join(hit)))
        result = run_action(rule.get("action"))
        _log("gwactions: rule '%s' result=%s" % (rule.get("name"), result["status"]),
             syslog.LOG_NOTICE if result["status"] == "ok" else syslog.LOG_ERR)
        append_history({
            "rule": rule.get("name"), "affected": hit,
            "trigger": trigger, "action": rule.get("action"), "delay": delay,
            "result": result["status"], "detail": result.get("detail", "")[:500],
        })
    return 0


def clear_history():
    """Truncate the history log."""
    try:
        open(HISTORY, "w").close()
        print(json.dumps({"status": "ok"}))
        return 0
    except Exception as exc:
        print(json.dumps({"status": "error", "detail": str(exc)}))
        return 1


def run_rule(uuid):
    """Manually run a single rule's action, ignoring cooldown."""
    cfg = load_config() or {}
    for rule in cfg.get("rules", []):
        if str(rule.get("uuid")) == str(uuid):
            _log("gwactions: manual run of rule '%s'" % rule.get("name"))
            result = run_action(rule.get("action"))
            append_history({
                "rule": rule.get("name"), "affected": [], "trigger": "manual",
                "action": rule.get("action"), "result": result["status"],
                "detail": result.get("detail", "")[:500],
            })
            try:
                rid = str(rule.get("uuid") or rule.get("name")).replace("/", "_")
                with open(os.path.join(STATE_DIR, rid + ".last"), "w") as handle:
                    handle.write(str(time.time()))
            except Exception:
                pass
            print(json.dumps(result))
            return 0
    print(json.dumps({"status": "error", "detail": "rule not found"}))
    return 1


def status_document():
    cfg = load_config() or {}
    statuses = gateway_statuses()
    history = []
    try:
        with open(HISTORY) as handle:
            for line in handle.readlines()[-50:]:
                line = line.strip()
                if line:
                    history.append(json.loads(line))
    except Exception:
        pass
    history.reverse()
    return {
        "enabled": bool(cfg.get("enabled", False)),
        "rules": cfg.get("rules", []),
        "gateways": statuses,
        "history": history,
    }


def main():
    syslog.openlog("gwactions", syslog.LOG_PID, syslog.LOG_DAEMON)
    args = sys.argv[1:]
    if args and args[0] == "--status":
        print(json.dumps(status_document()))
        return 0
    if args and args[0] == "--run-rule":
        os.makedirs(STATE_DIR, exist_ok=True)
        return run_rule(args[1] if len(args) > 1 else "")
    if args and args[0] == "--clear-history":
        return clear_history()
    affected = []
    if args and args[0]:
        affected = [g for g in args[0].split(",") if g]
    return handle_event(affected)


if __name__ == "__main__":
    sys.exit(main())
