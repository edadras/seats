# ADR-0004: A module architecture, and what a module may and may not do

- Status: Accepted
- Date: 2026-09-08

## Context

The platform is being asked to grow in directions that are open-ended by nature: payment gateways
(Iranian and international), messaging channels (SMS, messengers, email), report sources, page
blocks, and integrations nobody has thought of yet. Adding each one by editing core code means the
core keeps growing, every addition risks the seat inventory it has no business touching, and an
organiser who needs one specific Iranian PSP has to wait for us.

The requirement is explicit: the system should be module-based, modules should be addable, and it
should be possible to *write* a module rather than only install one.

Three shapes were considered:

1. **Composer packages with auto-discovery.** Laravel's own mechanism. Powerful, but a package is
   installed at the server level: one tenant's need becomes every tenant's code, and "install this"
   means a deploy.
2. **Runtime-uploaded PHP.** A module is uploaded through the panel and executed. Genuinely
   flexible, and genuinely a remote-code-execution feature: a multi-tenant platform that runs
   customer-supplied PHP in its own process has no tenant boundary left worth the name.
3. **Declared modules with typed extension points.** Modules live in the repository (or are
   installed by an operator), declare what they extend through a manifest, and are switched on
   *per tenant*. Third parties write them against a published contract.

## Decision

**Declared modules with typed extension points (3).**

A module is a directory under `modules/<vendor>/<name>/` with a `module.json` manifest and a PHP
service provider. It is registered with the platform once, by an operator; it is then switched on
or off per tenant, with its own settings per tenant. Enabling a module is a tenant decision.
Installing one is an operator decision. Those are different acts, and conflating them is how a
platform ends up running code one customer chose in another customer's request.

### 1. Extension points are typed, and there are no others

A module does not "hook into" the platform generically. It contributes to a fixed, versioned list
of extension points, each with an interface:

| Point | Interface | What it contributes |
| --- | --- | --- |
| `payments` | `PaymentGateway` | A way to take money |
| `messaging` | `MessageChannel` | A way to reach a buyer |
| `reports` | `ReportSource` | A queryable dataset for the report builder |
| `blocks` | `BlockType` | A block an organiser can put on a page |
| `themes` | `ThemeProvider` | Site themes |
| `panel` | `PanelScreen` | A nav entry and a screen in the panel |
| `settings` | `SettingsSchema` | Typed, validated per-tenant configuration |
| `events` | listeners | Reactions to domain events (order confirmed, ticket scanned, …) |

Adding a *kind* of extension is a core change and an ADR amendment. That is deliberate: an
open-ended hook system is an open-ended security review, and this is a system where the thing on
the other side of the boundary is somebody's ticket revenue.

### 2. A module never touches inventory directly

Modules receive domain events and call published services. They do not write to `seats`, `holds`,
`allocations` or `tickets`, and they do not get a database connection that could. The guarantees in
ADR-0002 are the product; a module that could reach around them would make them opinions.

This is enforced, not merely asked for: the module container binds a read-only projection of the
domain, and `ModuleBoundaryTest` asserts that no shipped module references an inventory model.

### 3. Settings are typed, and secrets are write-only

A module declares its settings as a schema — name, type, required, secret. Values are stored per
tenant, encrypted at rest when marked secret, and **never** returned by the API once written. The
panel shows "set" or "not set" and offers to replace. A gateway secret that can be read back from a
panel session is a gateway secret that a stolen panel session has.

### 4. Module code is versioned with the platform

Modules ship in the repository and are tested by the same CI. A third party writing a module writes
it against the published interfaces and the operator deploys it; there is no upload-and-run path,
and there will not be one. `docs/MODULES.md` is the contract they write against.

### 5. Failure is contained and honest

A module that throws does not fail the request that triggered it. Listener failures are caught,
recorded against the module with the exception, and surfaced in the panel as that module's health.
A module that fails repeatedly is disabled for that tenant with a reason the organiser can read —
never silently, because a messaging module that has quietly stopped sending tickets looks exactly
like one that is working.

## Consequences

- Payment gateways, messaging channels and report sources in this release are themselves modules.
  The first-party ones are not privileged; if the module system cannot express them, it is not good
  enough for anybody else's.
- An organiser cannot install arbitrary code. This is a real limitation, stated plainly: it is the
  price of being able to promise that one tenant's choices cannot reach another tenant's seats.
- Core stays small. What grows is a directory of modules, each with its own tests.
