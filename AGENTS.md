# Project Sync Agent Instructions

This file defines how AI coding agents and developers must work in this repository.

The detailed product and technical source of truth is `PROJECT_ARCHITECTURE.md`. Read it before proposing architecture or writing implementation code. If this file and the architecture document conflict, stop, identify the conflict, and ask for a decision.

## 1. Product context

Project Sync is a multi-store commerce product for small businesses. Each merchant receives an isolated deployment with:

- its own cPanel folder and domain or subdomain;
- its own PHP backend and React application build;
- its own MySQL database;
- its own environment configuration and uploaded files.

The central `sync.business` control plane stores the merchant registry, subscription and deployment metadata, aggregate reporting, version inventory, and store lifecycle status. It must not become the runtime database for storefront catalogue or checkout traffic.

## 2. Mandatory first-task protocol

Before changing code, the agent must:

1. Read this file completely.
2. Read `PROJECT_ARCHITECTURE.md` completely.
3. Inspect the repository structure, current branch state, dependency files, migrations, tests, and relevant existing code.
4. State its understanding of the requested outcome and current delivery phase.
5. List the files or modules it expects to change.
6. Identify unresolved decisions, assumptions, security concerns, and migration risks.
7. Present a short implementation and verification plan.

If a missing answer could materially affect data design, public API behavior, security, deployment, payments, or tenant isolation, pause and ask a focused question before implementation. Do not ask about choices that can be safely inferred from this document or the existing code.

For later tasks, repeat the relevant inspection and planning steps. Do not assume the repository still matches a previous conversation.

## 3. Non-negotiable architecture

- Backend: structured plain PHP supported by Composer packages.
- Frontend and merchant admin: React.
- Database: MySQL, with one database per merchant deployment.
- Hosting: cPanel-compatible shared hosting.
- API: JSON over HTTPS under a versioned prefix such as `/api/v1`.
- Deployment model: one maintained product codebase released to many isolated merchant installations.

Do not introduce Laravel, another full-stack framework, microservices, containers, queues, Redis, or infrastructure that requires persistent background workers unless the architecture has first been intentionally revised and approved.

Plain PHP does not mean unstructured PHP. New backend code must follow this flow:

`route -> middleware -> controller -> validator -> service -> repository -> database`

Controllers must remain thin. Business rules belong in services, input rules in validators, and SQL access in repositories. Cross-cutting integrations belong behind interfaces or infrastructure adapters.

## 4. Repository shape

Use the repository structure defined in `PROJECT_ARCHITECTURE.md`. Keep responsibilities separated along these lines:

```text
backend/
  public/
  src/
    Controllers/
    Middleware/
    Validators/
    Services/
    Repositories/
    Infrastructure/
  routes/
  database/migrations/
  tests/
frontend/
  src/
deployment/
docs/
```

Do not place application logic in the public entry point, route definitions, templates, or raw utility files. Do not add a second pattern when an established repository pattern already solves the problem.

## 5. Product-code rules

- Maintain one shared boilerplate; never fork business logic for an individual merchant.
- Put merchant identity, branding, feature flags, contact details, and operational options in environment or database configuration.
- Do not hard-code domains, merchant IDs, credentials, phone numbers, currency values, or deployment paths.
- Prefer small, explicit interfaces over global helpers and hidden state.
- Add Composer dependencies only when they solve a clear problem better than a small maintained implementation. Explain the tradeoff.
- Avoid speculative abstractions. Build the simplest design that preserves correctness and the documented boundaries.
- Preserve backward compatibility for published API contracts unless a planned version change explicitly allows a break.

## 6. Database rules

- Use PDO with prepared statements. Never build SQL by concatenating user input.
- Every schema change must be an ordered, repeatable migration. Do not edit production data manually as the implementation strategy.
- Migrations must be safe to run once, tracked, and compatible with the deployment process.
- Use transactions for operations that must succeed or fail together.
- Store money as integer minor units, such as Nigerian kobo. Never use floating-point arithmetic for money.
- Store timestamps in UTC and convert only at presentation boundaries.
- Preserve immutable order-item snapshots so later catalogue changes do not rewrite historical orders.
- Use explicit status values and validate every state transition.
- Add indexes based on real query paths, especially foreign keys, order lookup, status filtering, and reporting date ranges.
- Never log secrets, password hashes, reset tokens, complete JWTs, refresh tokens, or unnecessary personal data.

## 7. API conventions

- Keep endpoints under the documented version prefix.
- Return the standard success and error envelopes defined in `PROJECT_ARCHITECTURE.md`.
- Use appropriate HTTP status codes and stable machine-readable error codes.
- Validate and normalize all external input at the boundary.
- Do not expose stack traces, SQL errors, internal paths, or configuration details to clients.
- Pagination, filters, sorting, and date formats must be explicit and consistent.
- Update API documentation and contract tests whenever a public contract changes.

## 8. Checkout and order invariants

Checkout is the most sensitive workflow. Every implementation must preserve these rules:

1. Treat client-submitted totals as untrusted.
2. Reload products and prices from the database.
3. Validate product availability and quantities on the server.
4. Calculate subtotal, fees, discounts, and total on the server using integer money values.
5. Require and enforce an idempotency key so retries cannot create duplicate orders.
6. Create the order and its item snapshots inside one database transaction.
7. Commit the order before triggering optional notifications or redirects.
8. A failed email or WhatsApp redirect must never erase or duplicate a successfully stored order.
9. Return a stable order reference that the customer and merchant can use.

Tests must cover double submission, request retry, price tampering, invalid quantity, unavailable products, transaction rollback, and notification failure.

## 9. Authentication and security

- Use short-lived, signed JWT access tokens in the `Authorization: Bearer` header for merchant administration.
- Store administrator access tokens only in frontend memory; never document or implement `localStorage` or `sessionStorage` persistence.
- Use rotating opaque refresh tokens in Secure, HTTP-only, host-only, same-site cookies; store only protected token hashes in MySQL.
- Validate same-origin `Origin` or `Referer` values on login and every refresh-cookie operation.
- Revoke the complete refresh-token family when a rotated token is reused or the administrator logs out.
- Use PHP's `password_hash` and `password_verify`; never design custom password cryptography.
- Rate-limit login, password-reset, checkout, upload, and other abuse-sensitive endpoints.
- Apply authorization checks on the server for every protected operation.
- Keep secrets in environment configuration outside source control and outside the public web root.
- Restrict CORS to configured origins. Do not use unrestricted CORS with credentials.
- Validate file type by content, generate server-side filenames, enforce size limits, and block script execution in upload directories.
- Escape output according to context and set the security headers documented in the architecture.
- Record security-relevant actions in the audit log without recording secrets.

Any shortcut that weakens authentication, authorization, isolation, input validation, or checkout integrity requires explicit approval and must not be silently implemented.

## 10. Central control-plane boundaries

- A merchant storefront must continue serving catalogue and checkout traffic if the central control plane is temporarily unavailable.
- Central reporting receives minimum required aggregate data, not an unrestricted copy of merchant databases.
- Every central-to-store request must be authenticated, time-bounded, auditable, and safe to retry.
- Store suspension or lifecycle controls must fail predictably and must not corrupt merchant data.
- Never run a cross-merchant query against storefront databases during a customer request.

## 11. Performance and reliability

- Avoid N+1 queries and unbounded result sets.
- Select only required columns and paginate list endpoints.
- Keep the checkout transaction short and free from network calls.
- Add timeouts to external I/O. A non-critical integration failure should degrade gracefully.
- Use structured logs with request or correlation IDs and enough context to diagnose failures.
- Do not expose sensitive values in logs or metrics.
- Optimize after measuring, but prevent obvious shared-hosting problems in advance.

## 12. Testing requirements

Every behavior change needs the smallest effective mix of:

- unit tests for validation, calculations, and state-transition rules;
- integration tests for repositories, migrations, transactions, and authentication;
- API contract tests for request and response behavior;
- end-to-end coverage for critical customer and merchant paths.

For a bug fix, first add or identify a test that reproduces the defect, then fix it, then run relevant regression tests.

Do not claim a test passed unless it was actually run. If the environment prevents a check, state exactly which check was not run and why.

Discover commands from `composer.json`, `package.json`, repository scripts, and CI configuration. Do not invent commands that the project does not provide.

## 13. Definition of done

A task is complete only when applicable items are satisfied:

- acceptance criteria are met;
- code follows existing conventions and the documented layer boundaries;
- input validation, authorization, and error paths are implemented;
- migrations are included and tested where the schema changed;
- automated tests cover the new behavior and important failures;
- relevant test, lint, type-check, and build commands pass;
- public API or operational documentation is updated;
- deployment and rollback effects are understood;
- no secrets, debug output, generated dependencies, or unrelated edits were committed.

## 14. Deployment and update safety

- The web server document root must point to the backend public directory, never the repository root.
- Preserve each merchant's `.env`, uploaded files, logs, and other documented persistent paths during releases.
- Never overwrite merchant-specific configuration with template values.
- Back up the database before migrations in a production update.
- Updates must be versioned, logged, repeatable, and capable of rollback.
- Do not execute destructive schema or filesystem actions without naming the exact impact and obtaining explicit approval.
- Never assume all cPanel installations have identical PHP extensions or permissions; check deployment prerequisites.

## 15. MVP scope discipline

Implement only the current approved phase. The MVP focuses on business profile and branding, catalogue management, storefront browsing, server-authoritative ordering, merchant authentication, order management, WhatsApp handoff, email notifications, and reliable deployment.

Unless a task explicitly moves them into scope, defer:

- Paystack and other online-payment processing;
- automated remote code updates;
- fully automated cPanel provisioning;
- advanced analytics and recommendation systems;
- native mobile applications;
- complex multi-warehouse or marketplace behavior.

Do not build a deferred system indirectly while completing an MVP task.

## 16. Git and workspace safety

- Treat existing changes as user-owned. Inspect before editing and do not overwrite unrelated work.
- Keep changes focused on the requested task.
- Do not use destructive Git commands, force pushes, or broad file deletion.
- Do not rewrite history unless explicitly requested.
- Do not commit generated build output, vendor directories, local environment files, uploads, or secrets unless repository policy explicitly requires it.
- Do not create a commit or open a pull request unless asked.

## 17. Communication and handoff

During implementation, report material discoveries, changed assumptions, and blockers early. At completion, provide:

1. the outcome in plain language;
2. the files changed and why;
3. migrations or configuration changes;
4. tests and checks actually run with their results;
5. remaining risks, deferred work, or manual deployment steps.

Do not hide uncertainty. Label assumptions and distinguish verified behavior from proposed behavior.

## 18. Decision control

Record meaningful architecture decisions in the repository rather than leaving them only in chat. Before implementing a feature affected by an unresolved decision in `PROJECT_ARCHITECTURE.md`, confirm the decision with the responsible product or engineering owner.

Changes to tenant isolation, payment flow, authentication, public API contracts, data retention, update strategy, or central-control behavior require an explicit architecture update and review.
