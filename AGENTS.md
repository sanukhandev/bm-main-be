# Backend AGENTS.md Template

Use this file as `backend/AGENTS.md` inside the Laravel submodule.

## Scope

This submodule implements the Baithul Madeena REST API using Laravel and MySQL.

Read the parent repository's `AGENTS.md` and `docs/backend/*` before making changes.

## Non-Negotiable Rules

- Enforce branch isolation on every branch-owned resource.
- Never trust request-supplied branch IDs.
- Keep controllers thin.
- Use Form Requests for validation.
- Use policies/gates/services for authorization.
- Use transactions for financial, agreement activation, receiving, and stock workflows.
- Use DECIMAL for money.
- Do not hard-delete posted financial records.
- Do not change stock without a stock movement.
- Prevent overlapping tenant asset occupancy.
- Add automated tests for all sensitive domain changes.

## Required Test Cases

For every branch-scoped endpoint, test:

```text
authorized branch success
unauthorized branch failure
super-admin expected behavior
```

## Coding Workflow

Before completion run the project's:

```text
formatter
static analysis if configured
unit tests
feature tests
```

Do not invent command names; inspect `composer.json` and repository documentation.
