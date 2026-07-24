# Tool approval requires a complete decision set; the resume payload is cumulative

**Status:** accepted

## Context & decision

`ToolApproval` gates tool execution on a human decision delivered via the inbound resume **payload**
(see ADR 0001). The payload is keyed by tool callId:
`['<callId>' => 'approve' | 'reject' | ['reject', $reason]]`. The question is what happens when the
payload is *partial* — when some approval-requiring tools carry no decision — and what the safe
default for a missing decision is.

The prior implementation iterated *every* tool in the tool-call message and treated an absent entry
as "approved". Because most tools in a call don't require approval (and so correctly have no entry),
that default was right for them — but it was applied indiscriminately, so an approval-requiring tool
missing a decision was also silently approved. The practical effect: **approving one tool in a batch
auto-approved every other approval-requiring tool in the same batch.** For a middleware whose purpose
is safety-gating, that inverts the guarantee.

We decide:

- **A tool runs if and only if it is explicitly approved.** Silence is never consent. A missing
  decision never authorizes execution.
- **Approval requires a complete decision set.** `ToolApproval::before()` proceeds only when the
  payload carries a decision for *every* approval-requiring action. If any is missing, the workflow
  re-suspends — with an `ApprovalRequest` whose actions reflect the decisions already delivered (so
  the human sees progress, not a fresh blank slate).
- **The payload is cumulative.** It carries every decision made so far, not only the newest ones.
  `ApprovalRequest::generatePayload()` is the producer: it emits one entry per *decided* action and
  omits pending ones, so a request progressively filled with decisions yields a cumulative payload.
- **`ToolApproval` stays stateless.** It does not persist accumulated decisions. Accumulation lives
  with the caller (the caller holds the `ApprovalRequest`, fills in decisions, and calls
  `generatePayload()`); the payload *is* the middleware's only memory.

The "stateless middleware ⇒ cumulative payload" implication is load-bearing: `before()`
deterministically re-derives the full approval-requiring tool set on every resume (replay-by-rerun,
per ADR 0001) and has no recollection of decisions delivered in prior resumes. So the only way for a
resume to look "complete" is for the payload to re-state every prior decision. There is no stateless
incremental option — accumulation must live somewhere, and the payload is where.

As part of making the approval model honest, `ActionDecision` loses `Edit`: it was advertised in the
payload (`['edit', $args]`) but silently ignored by `processDecisions` (the tool ran with its original
arguments), and `Action` had no field to source the edited arguments from. A non-functional decision
type is worse than none. `ActionDecision` is now `Pending | Approved | Rejected`.

## Considered options

- **(A) Block-absent — treat a missing decision as rejected and proceed.** Re-derive the
  approval-requiring tools; for each, explicit approve ⇒ run, explicit reject ⇒ block, absent ⇒ block
  (treated as a rejection). Non-approval tools are untouched and run normally. Safe-by-default and
  simple, but it lets the agent proceed on a partial review (only the approved tools run, the rest are
  dropped). Rejected in favour of (C): for a safety gate, refusing to proceed until the review is
  complete is safer than proceeding with a subset.
- **(B) Bulk-approve — silence means consent.** Keep absent = approved, deliberately. One resume is
  one human review of the whole batch; not objecting counts as approving. Rejected: it is the least
  safe default, and "silence = consent" is exactly the property a human-in-the-loop gate must not
  have unless the docs scream it — and even then the safer default wins.
- **(C-stateful) — middleware accumulates decisions in `WorkflowState`.** Lets the payload be
  incremental (only new decisions), at the cost of making `ToolApproval` the only middleware that
  persistently reads-modifies-writes its own state — a new pattern that then begs to be generalized.
  Rejected: the cumulative-payload contract achieves the same incremental *UX* (re-suspend shows only
  what's left) without middleware state, by pushing accumulation to the caller where
  `generatePayload()` already lives.

## Consequences

- **The resume contract for approvals is cumulative.** A caller resuming a `ToolApproval` interrupt
  must deliver a payload containing a decision for every approval-requiring action decided *to date*.
  Delivering only new decisions re-suspends (the prior ones look pending again). `generatePayload()`
  makes this natural: fill the request, call it, resume.
- **`ToolApproval` remains pure/stateless middleware** — consistent with every other middleware and
  with the determinism contract (no read-modify-write on state to keep idempotent across replay).
- **Re-suspensions are progressive, not punishing.** The outbound `ApprovalRequest` on a re-suspend
  carries the already-delivered decisions on its actions, so the UI can render "A approved — decide
  B, C" rather than re-asking from scratch.
- **`ActionDecision::Edit` and `Action::edit()` are removed.** The `['edit', $args]` payload arm goes
  with them. `ToolApproval::extractRejectionReason` loses its edit branch.

## Why this is irreversible / surprising

The cumulative-payload contract is public API: callers (and the Cloud SDK) resume with a payload
shape whose completeness semantics are now load-bearing. A future reader seeing a partial-approval
resume *re-suspend* — and seeing the caller resend decisions it already made — will reasonably ask
why the middleware doesn't "remember" them. The answer (stateless `before()` re-derives the tool set
every resume, so the payload is the only memory; accumulation belongs to the caller, not the engine)
is not obvious from the code and is the reason this ADR exists.
