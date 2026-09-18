# 006: User registration, approval, and account-state model

| Field              | Value              |
| ------------------ |--------------------|
| **Created By**     | Martin Yde Granath |
| **Date**           | 2026-06-12         |
| **Decision Maker** | ITK Dev team       |
| **Stakeholders**   | ITK Dev developers |
| **Status**         | Draft              |

## Context

ai-reolen serves Danish public-sector organisations. The intended
onboarding flow is:

1. A representative from an approved organisation self-registers at
   `/register` using their work e-mail.
2. The system only accepts the registration if the e-mail domain is on
   a project-managed allow-list (kommune domains, ministries, etc.).
3. Even after a successful registration, the new account does **not**
   get application access until an existing trusted user (a domain
   manager) approves it from an admin queue.
4. An approved account can later be **blocked** without being deleted
   (audit trail, possible un-block).

Before we build any of that we need to settle one design question that
ripples through the rest of the work: **how is the "can this person
sign in" state modelled on the `User` entity?**

The two candidate approaches the team weighed informally were:

- **A — Identity-state field(s) on `User`.** A `status` enum
  (`pending | approved | blocked`) or a pair of booleans (`approved`,
  `active`) that's explicit and independent of authorisation.
- **B — Role-based gating.** Treat "no roles" as "no access" — a
  pending or blocked user simply has no entries in the `roles` column.

This ADR is scoped to that choice and the surrounding registration /
approval architecture. Tracked in
[#60](https://github.com/itk-dev/ai-reolen/issues/60).

### Drivers

- **Functional:**
  - Self-signup gated by an allow-list of e-mail domains.
  - Distinct "pending" (never approved) and "blocked" (approved, then
    revoked) states for the admin UX.
  - A single, declarative place for "should this credential succeed?"
    so the login flow stays auditable.
- **Non-functional:**
  - Stay within Symfony Security idioms — don't fight the framework's
    User abstraction or its `UserCheckerInterface` extension point.
  - Keep authorisation (`roles`, voters) orthogonal from identity
    state so changes to one don't accidentally weaken the other.

### Options Considered

1. **Status enum on `User` (`pending | approved | blocked`).**
   - Pros: single source of truth for the identity lifecycle;
     distinguishes "never approved" from "approved then revoked";
     trivial to query (`status = 'pending'` powers the approval
     queue); ergonomic with Symfony's `UserCheckerInterface`.
   - Cons: extending the lifecycle later means adding enum cases
     (Doctrine + migration), not just toggling a flag.
2. **Two booleans (`approved` + `active`).**
   - Pros: smaller migrations to introduce.
   - Cons: two flags that mostly want to move in lockstep, with one
     impossible / undefined state (`approved=false, active=true`) that
     code must remember not to produce; #45's existing `active` field
     would need to grow a partner.
3. **Role-based gating (`roles` empty → no access).**
   - Pros: no schema change beyond what #12 already implies.
   - Cons: Symfony's generated `User::getRoles()` returns
     `array_unique([...$this->roles, 'ROLE_USER'])`, so "empty roles"
     does **not** actually mean "no access" without removing that
     guarantee and fighting framework idioms; can't distinguish
     "pending" from "blocked"; conflates *what* a signed-in user can
     do with *whether* they may sign in at all.

## Decision

Adopt **option 1 — a `status` enum** on the `User` entity:

```php
enum UserStatus: string
{
    case AwaitingEmailConfirmation = 'awaiting_email_confirmation';
    case Pending                   = 'pending';
    case Approved                  = 'approved';
    case Blocked                   = 'blocked';
}
```

`User::getStatus(): UserStatus` becomes the single source of truth
for the identity lifecycle. Authorisation (`roles`, voters,
`domainManager`) remains orthogonal — those answer "what may a
signed-in user do", not "may this person sign in".

The intended state transitions are:

- `AwaitingEmailConfirmation → Pending` once the user has proven they
  control the email address. Until then they cannot sign in and do
  not appear in the domain manager's approval queue.
- `Pending → Approved` once a domain manager (or an admin acting
  across all domains) approves the account from the queue.
- `Pending → Blocked` (rejection) and `Approved → Blocked`
  (revocation) preserve the row for audit; un-blocking is the same
  action in reverse (`Blocked → Approved`).
- `AwaitingEmailConfirmation → Blocked` if the email is never
  confirmed and the row is rejected administratively.

The mechanism that actually sends the confirmation email and
verifies the token is out of scope for this ADR — it's a separate
implementation issue. This ADR only commits to the state existing
and being enforced.

### Registration

- Anonymous endpoint `/register` accepts `email`, `password`, and
  `name`.
- The submitted e-mail's domain must match an entry on an allow-list.
  The list is sourced from a comma-separated env var
  (`REGISTRATION_ALLOWED_EMAIL_DOMAINS=aarhus.dk,kk.dk,…`) for the
  first cut, with a clear migration path to a dedicated `Domain`
  entity if the list later needs CRUD admin tooling.
- A successful registration creates a `User` with
  `status = UserStatus::Pending`. The user is shown a "waiting for
  approval" page and cannot sign in.

### Login gating

- A `Symfony\Component\Security\Core\User\UserCheckerInterface`
  implementation (`App\Security\AccountStatusChecker`) rejects login
  for any user whose `status` is not `Approved`, with localised
  messages for `Pending` vs. `Blocked`.
- Wired in `security.yaml` via `user_checker:` on the `main` firewall.

### Approval queue

- A route (e.g. `/admin/users/pending`) lists users with
  `status = Pending`. Restricted to users with `ROLE_DOMAIN_MANAGER`
  (or `ROLE_ADMIN` via role hierarchy) via the voter described in
  "Domain manager — a role, not a flag" below.
- The approver can **approve** (`status = Approved`) or **reject**
  (`status = Blocked` — preserves the row for audit; a separate
  "delete" action can come later if needed).
- An already-approved user can later be blocked (`Approved` →
  `Blocked`) from the same admin surface; un-blocking is just the
  same action in reverse.

### Domain manager — a role, not a flag

Following the same reasoning as the `status` decision above —
capability and identity stay orthogonal — "domain manager" is
modelled as the **role** `ROLE_DOMAIN_MANAGER` on the existing
`User.roles` column rather than a dedicated boolean field.

- Promotion / demotion is just adding or removing the role from the
  array.
- A user's **scope** (which domain they manage) is derived from the
  part of their own e-mail address after the `@`. No separate
  column.
- Authorisation flows through a small voter that combines:
  1. `is_granted('ROLE_DOMAIN_MANAGER')` on the acting user, and
  2. `emailDomain(currentUser) === emailDomain(targetUser)`.
- Site admin gets `ROLE_ADMIN`. Symfony's `role_hierarchy` is
  configured so `ROLE_ADMIN` implies `ROLE_DOMAIN_MANAGER`, and the
  voter short-circuits the domain-match check for admins so they can
  manage users across all domains from the same screen.
- The user-management view is a single controller. The list query
  is scoped: `ROLE_ADMIN` sees everyone; `ROLE_DOMAIN_MANAGER` sees
  only users whose email domain matches their own.

If we later need a user to manage a domain *other than* their own
email's, or an organisation that owns multiple e-mail domains, we
introduce a `Domain` entity and a relation. Defer until needed —
removing that complexity later would be the painful direction.

### Implication for #45

Both `active` and `domainManager` from
[#45](https://github.com/itk-dev/ai-reolen/issues/45) are **superseded**:

- `active` → replaced by the `status` enum.
- `domainManager` → replaced by the `ROLE_DOMAIN_MANAGER` role on the
  existing `User.roles` column.

When #45's implementation lands, the entity ships with **only `name`**
on top of the auth fields from
[#2](https://github.com/itk-dev/ai-reolen/issues/2). #45 should be
updated to reflect this so the migration doesn't end up needing
immediate amendment.

## Consequences

### Positive

- Identity state and authorisation stay orthogonal; reasoning about
  either in isolation is easier.
- The approval queue is a one-line query (`WHERE status = 'pending'`);
  audit and compliance questions ("show me all blocked accounts")
  fall out of the same model.
- `UserCheckerInterface` is Symfony's documented hook for exactly this
  — no custom event listeners or controller checks scattered around.
- The enum's three named cases read better in code, templates, and
  the admin UI than two booleans would.

### Negative / Trade-offs

- Doctrine string-backed enums need a small `Type` mapping (or use
  Symfony's built-in support); a tiny amount of extra setup compared
  to a bare boolean column.
- Extending the enum domain later (e.g. `expired`) means a small
  migration. We accept this — it's exactly the kind of change an
  ADR should make deliberate. The `AwaitingEmailConfirmation` case
  is already part of the decided model above.
- The env-var allow-list is the right starting point but will need to
  graduate to a `Domain` entity if domains grow or need per-domain
  metadata (e.g. a different approver per organisation). Tracked as a
  follow-up only if it actually happens.
