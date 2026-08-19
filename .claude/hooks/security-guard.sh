#!/usr/bin/env bash

INPUT="$(cat)"
COMMAND="$(echo "$INPUT" | jq -r '.tool_input.command // ""')"

if echo "$COMMAND" | grep -Eiq \
  'rm +-rf|git +reset +--hard|git +clean +-.*f|DROP +TABLE|DROP +DATABASE|TRUNCATE +TABLE'; then

  jq -n '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason:
        "Blocked by GastroFlow AI safety policy: destructive operation."
    }
  }'

  exit 0
fi

exit 0
