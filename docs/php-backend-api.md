# PHP Backend API Notes

This agent now assumes a JSON API hosted behind nginx and implemented in PHP.

Recommended design choices:
- Use `POST` for all agent endpoints, including job polling, so PHP can consistently read JSON request bodies.
- Return JSON for both success and failure cases.
- Authenticate with a short enrollment key during registration, then issue an agent bearer token.
- Keep route paths stable and versioned under `/v1/agents/...`.

Expected endpoints:
- `POST /v1/agents/register`
- `POST /v1/agents/heartbeat`
- `POST /v1/agents/inventory`
- `POST /v1/agents/jobs/next`
- `POST /v1/agents/job-events`

Minimal registration response:

```json
{
  "agent_record_id": "agt_01HRX9H6W7K8W9P4M2",
  "agent_token": "issued-by-php-backend",
  "poll": {
    "heartbeat_seconds": 300,
    "jobs_seconds": 120,
    "inventory_seconds": 21600
  }
}
```

Suggested nginx routing approach:
- Route `/v1/agents/*` to a single PHP front controller.
- Let PHP decode `php://input` as JSON and dispatch by path and method.
- Return `401` or `403` when an agent token is invalid so the agent can re-register.

Suggested PHP handler responsibilities:
- `register`: create or look up the device, issue token, return poll intervals
- `heartbeat`: update liveness and current job summary
- `inventory`: store latest machine inventory snapshot
- `jobs/next`: return a single active assignment or `{"job":null}`
- `job-events`: append the submitted event batch and return an accepted count
