#!/usr/bin/env bash
# PreToolUse hook — validates Bash commands before they run.
#
# Project: Dashboard Jefe de Zona (Tren Maya)
#
# This is a fast, deterministic safety net for the handful of mistakes that
# would be specifically expensive in THIS project: taking the VPN-only
# dashboard public, exposing MySQL beyond localhost, reopening password SSH,
# leaking .env, or rewriting/force-pushing shared git history. It mirrors the
# non-negotiables in the repo's CLAUDE.md and the global git-safety rules.
#
# It does not replace the security-auditor or code-reviewer agents — those
# review code and diffs; this only intercepts commands before they execute.
# Everything not matched here is left to Claude Code's normal permission
# flow (this hook neither auto-approves nor auto-denies it).
#
# Register in .claude/settings.json:
#   "PreToolUse": [
#     { "matcher": "Bash", "hooks": [
#         { "type": "command", "command": "bash ${CLAUDE_PROJECT_DIR}/.claude/hooks/validate-bash.sh" }
#     ] }
#   ]

set -uo pipefail

input="$(cat)"

# ---------------------------------------------------------------------------
# Extract tool_input.command without hard-depending on jq being installed.
# ---------------------------------------------------------------------------
extract_command() {
  if command -v jq >/dev/null 2>&1; then
    printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null
    return
  fi
  if command -v node >/dev/null 2>&1; then
    printf '%s' "$input" | node -e '
      let data = "";
      process.stdin.on("data", c => (data += c));
      process.stdin.on("end", () => {
        try {
          const json = JSON.parse(data);
          process.stdout.write((json.tool_input && json.tool_input.command) || "");
        } catch (e) {
          process.stdout.write("");
        }
      });
    ' 2>/dev/null
    return
  fi
  # Last-resort fallback: naive single-line extraction (best-effort only).
  printf '%s' "$input" \
    | grep -o '"command"[[:space:]]*:[[:space:]]*"[^"]*"' \
    | head -n1 \
    | sed -E 's/^"command"[[:space:]]*:[[:space:]]*"//; s/"$//'
}

command_str="$(extract_command || true)"

# No command found, or we couldn't parse it — fail open. A hook bug should
# never be the reason a legitimate command gets stuck; the normal permission
# prompt and the review/security agents remain the real safety net.
if [ -z "$command_str" ]; then
  exit 0
fi

# ---------------------------------------------------------------------------
# Emit a PreToolUse decision (deny or ask) and stop, without depending on jq
# for correct JSON string escaping either.
# ---------------------------------------------------------------------------
emit_decision() {
  local decision="$1" reason="$2"
  if command -v jq >/dev/null 2>&1; then
    jq -n --arg d "$decision" --arg r "$reason" \
      '{hookSpecificOutput: {permissionDecision: $d}, systemMessage: $r}'
  elif command -v node >/dev/null 2>&1; then
    node -e '
      const decision = process.argv[1];
      const reason = process.argv[2];
      console.log(JSON.stringify({
        hookSpecificOutput: { permissionDecision: decision },
        systemMessage: reason,
      }));
    ' "$decision" "$reason"
  else
    local escaped
    escaped=$(printf '%s' "$reason" | sed 's/\\/\\\\/g; s/"/\\"/g')
    printf '{"hookSpecificOutput":{"permissionDecision":"%s"},"systemMessage":"%s"}\n' "$decision" "$escaped"
  fi
}

deny() { emit_decision "deny" "$1" >&2; exit 2; }
ask()  { emit_decision "ask"  "$1" >&2; exit 2; }

c="$command_str"

# ===========================================================================
# DENY — project non-negotiables and irreversible system destruction.
# These are refused outright rather than escalated to the user, because
# CLAUDE.md declares them out of bounds for this project regardless of who
# is asking: an infra change like this belongs to a deliberate, manual
# sysadmin action outside of Claude Code, not a dashboard-development task.
# ===========================================================================

# Wipe the filesystem root or the user's home directory.
if [[ "$c" =~ rm[[:space:]]+(-[a-zA-Z]*[rR][a-zA-Z]*[fF][a-zA-Z]*|-[a-zA-Z]*[fF][a-zA-Z]*[rR][a-zA-Z]*)[[:space:]]+(/|/\*|~|\$HOME)([[:space:]]|$) ]]; then
  deny "Blocked: command deletes the filesystem root or home directory. This can never be an intended dashboard-development action."
fi

# Disk-level destructive operations (mkfs, dd to a device, raw writes to /dev).
if [[ "$c" == *"mkfs"* ]] || [[ "$c" == *"dd if="* && "$c" == *"of=/dev/"* ]] || [[ "$c" == *"> /dev/sd"* ]]; then
  deny "Blocked: disk-level destructive operation (mkfs/dd/raw device write). Not something this project's Bash tool should ever run."
fi

# Widening MySQL beyond 127.0.0.1 — CLAUDE.md: "MySQL solo accesible desde localhost".
if [[ "$c" == *"bind-address"* ]] && [[ "$c" == *"0.0.0.0"* ]]; then
  deny "Blocked: this would bind MySQL beyond 127.0.0.1, violating the project's non-negotiable 'MySQL solo accesible desde localhost' rule."
fi

# Opening the MySQL port on the firewall — the DB must never be reachable off-box.
if [[ "$c" == *"ufw"* ]] && [[ "$c" == *"3306"* ]] && [[ "$c" == *"allow"* ]]; then
  deny "Blocked: opening port 3306 on the firewall would expose MySQL outside the server, violating the VPN-only access model."
fi

# Re-enabling SSH password authentication — CLAUDE.md: "Acceso SSH al servidor solo por llave".
if [[ "$c" == *"PasswordAuthentication"* ]] && [[ "$c" == *"yes"* ]]; then
  deny "Blocked: enabling SSH password authentication violates the project's key-only SSH access rule."
fi

# Tearing down the VPN or the firewall that keeps this system off the open internet.
if [[ "$c" == *"tailscale down"* ]] || [[ "$c" == *"systemctl stop tailscaled"* ]] \
  || [[ "$c" == *"systemctl disable tailscaled"* ]] || [[ "$c" == *"ufw disable"* ]]; then
  deny "Blocked: this would remove the VPN-only barrier that keeps the dashboard off the public internet. CLAUDE.md marks this non-negotiable."
fi

# Committing the real .env file (never .env.example) to git.
if [[ "$c" == *"git add"* ]] && [[ "$c" == *".env"* ]] && [[ "$c" != *".env.example"* ]]; then
  deny "Blocked: staging .env for commit. Secrets/credentials must never enter the repository — use .env.example with placeholders instead."
fi

# Blind remote code execution (classic supply-chain vector).
if [[ "$c" =~ curl.*\|[[:space:]]*(bash|sh) ]] || [[ "$c" =~ wget.*\|[[:space:]]*(bash|sh) ]]; then
  deny "Blocked: piping a remote download directly into a shell. Download, read, and verify the script first."
fi

# ===========================================================================
# ASK — destructive or sensitive, but sometimes a legitimate step. A human
# should confirm these rather than have them run silently.
# ===========================================================================

# Force-pushing, especially to main/master.
if [[ "$c" =~ git[[:space:]]+push.*(--force|-f)([[:space:]]|$) ]]; then
  if [[ "$c" == *"main"* ]] || [[ "$c" == *"master"* ]]; then
    ask "Force-push to main/master detected. This can overwrite shared history — confirm this is intentional and authorized."
  fi
  ask "Force-push detected. Confirm this won't discard someone else's work on the remote."
fi

# Rewriting or discarding local work.
if [[ "$c" =~ git[[:space:]]+reset[[:space:]]+--hard ]] || [[ "$c" =~ git[[:space:]]+clean[[:space:]]+-[a-zA-Z]*f ]]; then
  ask "Destructive git operation detected (reset --hard / clean -f). Confirm there's nothing uncommitted worth keeping first."
fi

# Skipping commit hooks or signing — only acceptable with explicit user request.
if [[ "$c" == *"--no-verify"* ]] || [[ "$c" == *"--no-gpg-sign"* ]] || [[ "$c" == *"commit.gpgsign=false"* ]]; then
  ask "Command skips git hooks or commit signing. Confirm the user explicitly asked for this."
fi

# Laravel commands that wipe application data — this project's MySQL will
# eventually hold real zone-chief operational data, not just seed rows.
if [[ "$c" == *"migrate:fresh"* ]] || [[ "$c" == *"migrate:reset"* ]] || [[ "$c" == *"db:wipe"* ]]; then
  ask "This artisan command drops all database data. Confirm this is a local/dev database, not one with real records."
fi

# Raw destructive SQL issued straight from the shell.
if [[ "$c" == *"mysql"* ]] && [[ "$c" =~ (DROP[[:space:]]+(DATABASE|TABLE)|TRUNCATE) ]]; then
  ask "Raw destructive SQL (DROP/TRUNCATE) detected. Confirm the target database and that this isn't production data."
fi

# Overly permissive file permissions.
if [[ "$c" =~ chmod[[:space:]]+(-R[[:space:]]+)?777 ]]; then
  ask "chmod 777 detected. World-writable permissions are rarely correct — confirm this is really needed."
fi

# Privilege escalation.
if [[ "$c" =~ ^sudo([[:space:]]|$) ]] || [[ "$c" =~ ^su([[:space:]]|$) ]]; then
  ask "Command requires elevated privileges. Confirm before running as root."
fi

# Any other recursive forced delete not already caught above.
if [[ "$c" =~ rm[[:space:]]+(-[a-zA-Z]*[rR][a-zA-Z]*[fF][a-zA-Z]*|-[a-zA-Z]*[fF][a-zA-Z]*[rR][a-zA-Z]*)[[:space:]] ]]; then
  ask "Recursive forced delete (rm -rf) detected. Confirm the target path is correct."
fi

# Nothing matched — defer to Claude Code's normal permission handling.
exit 0
