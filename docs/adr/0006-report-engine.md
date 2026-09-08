# ADR-0006: A report builder that cannot be turned into a query console

- Status: Accepted
- Date: 2026-09-08

## Context

Organisers need reports they define themselves: sales by section, by price category, by day; who
came in and who did not; refunds; performance against capacity — assembled into report pages they
can come back to, and export.

The obvious implementation is a SQL box. It is also the implementation that ends the tenant
boundary. `seats` is a multi-tenant database whose entire safety story is a global scope on an
Eloquent model; a feature that accepts SQL from a customer and runs it has thrown that away, and no
amount of statement parsing puts it back. Read-only replicas and per-tenant database users move the
problem without solving it: the platform still has to decide, from a string, whether a query is
allowed to see a row.

## Decision

**Reports are built from typed sources, not from SQL.** There is no query box, and there will not
be one.

### 1. A source is a declared, tenant-scoped dataset

A `ReportSource` declares its dimensions (groupable fields), its measures (aggregatable fields),
its filters and their types, and a base query that is already scoped to the current tenant by the
same global scope everything else uses. The builder composes a query from *identifiers the source
declared*, never from user text. A field name that is not in the declaration does not reach the
database; it is a validation error before a query is built.

First-party sources: orders, allocations, tickets, check-ins, holds, events, sites. Modules add
their own (ADR-0004), which is how a payment module contributes settlement reporting without core
knowing what a settlement is.

### 2. A report is a saved definition, not a saved result

`{source, dimensions[], measures[], filters[], sort, limit}`. It is re-run when read, so a report
is never stale and never a copy of data that has since been corrected. Expensive reports are cached
by definition hash for a short window, which is a performance decision, not a correctness one.

### 3. Report pages are made of the same blocks as sites

A report page is a layout of report widgets — table, bar, line, stat, funnel — over saved reports.
This reuses the block model the site builder already has (ADR-0003 §4), so an organiser learns one
editor rather than two, and a chart on a report page and a chart on a public site page are the same
component with different permissions.

### 4. Exports and schedules are the same definition, run elsewhere

CSV and XLSX export re-run the definition in a queued job and hand back a signed, expiring link.
Schedules re-run it and send it through a messaging channel. Neither path has its own query
implementation, because two implementations of "what this report means" is how a scheduled report
and its screen start disagreeing.

### 5. Permission is per report and per source

A source declares the permission it needs; `reports.orders.view` is not `reports.checkins.view`. A
report inherits the permission of its source, so sharing a report cannot widen what its viewer may
see. Money is separated from operations: a volunteer coordinator can be given attendance without
being given revenue.

### 6. Row limits and timeouts are part of the contract

Every report query carries a statement timeout and a hard row cap. A report that exceeds either
returns a clear refusal naming the limit, not a spinner and a database under load. An organiser who
wants everything gets it through export, which is queued and paginated.

## Consequences

- An organiser cannot write an arbitrary query. Stated plainly: some questions will need a new
  source, and that is a code change. The alternative is a feature that can read another
  organiser's ticket sales, and that trade is not close.
- Because sources are declarations, the same declaration drives the builder UI, validation, the
  export, the schedule and the API contract. There is one place to add a field.
