# runner-dummy

Minimal PHP RabbitMQ runner for the WordPress plugin insights hackathon.

## Purpose

This repo acts as the reference implementation for future `runner-NNNN` repos.

It does three things:

1. Consumes a job from RabbitMQ
2. Validates the expected input shape
3. Publishes a minimal report back to the orchestrator

## Input contract

Each incoming message must be JSON with:

```json
{
  "plugin": "akismet",
  "src": "/path/to/unpacked/plugin"
}
```

- `plugin`: WordPress plugin slug
- `src`: absolute or repo-local path to the unpacked plugin source

## Output contract

Each published message is JSON with:

```json
{
  "runner": "runner-dummy",
  "plugin": "akismet",
  "src": "/path/to/unpacked/plugin",
  "report": {
    "message": "Dummy runner executed successfully"
  },
  "received_at": "2026-03-20T10:00:00+00:00",
  "completed_at": "2026-03-20T10:00:00+00:00"
}
```

Only the `report` property is intended to be runner-specific for now.

## RabbitMQ behavior

- Consumes from `RABBITMQ_INPUT_QUEUE`
- Publishes reports to `RABBITMQ_REPORT_EXCHANGE`
- Uses manual acknowledgements
- Rejects invalid JSON or missing fields without requeueing
- Requeues on unexpected runtime failures

## Setup

```bash
cd /Users/eriktorsner/src/wp-plugin-insights/runner-dummy
cp .env.example .env
composer install
php bin/runner
```

## Test without RabbitMQ

You can process a single message directly from the command line:

```bash
mkdir -p /tmp/plugins/akismet
echo '{"plugin":"akismet","src":"/tmp/plugins/akismet"}' | php bin/process-message
```

That prints the same payload shape the runner would publish back to RabbitMQ.

## Example input

```bash
cat <<'JSON'
{"plugin":"akismet","src":"/tmp/plugins/akismet"}
JSON
```

## Notes

- The runner currently does not inspect the plugin contents
- The dummy `report` is intentionally tiny so the report contract can evolve later
