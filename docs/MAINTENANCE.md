# Maintenance — domain rules and API contract

_Last updated: 2026-10-31_

This is the contract the backend implements and the dashboard calls. Responses
are `{ "data": ... }` (lists: `{ "data": [...], "meta": {pagination} }` where
paginated), keys are snake_case, money is a string with 2 decimals, hours a
string with 2 decimals, datetimes ISO-8601. Every path below is under `/api`.
Everything is behind `auth.token.store` except `public/trips/{token}/…`.
Validation errors are Laravel 422s; a refused workflow step is a 422 with
`errors.status` (or the field at fault) saying why in plain words.

Vocabulary (use these words in labels and messages, never the old ones):
technician (never "payee"), supplier, payment, payment line, trip, visit /
stop, session (a clock-in → clock-out), inspection, approval, stock count,
Bought / Used / Returned / Moved / Opening stock / Stock count / Damaged or
lost / Undone.

Settled while building (2026-10-30):
- An issue's `status` is a plain string, with `status_label`, `step` and
  `whose_turn` beside it; priority, assigned_priority, impact and resolution
  are `{value, label}`.
- Workflow actions return `{data: <issue>, trip_link?: {url_token,
  expires_after_hours, trip_id}}` — `trip_link` sits next to `data`, only when a
  new link was issued.
- `costs.total_actual` = non-mistaken payment lines linked to the issue (each
  line counts in full on every issue it names) + stock used at cost;
  `paid_total` is the lines alone; unpaid purchases are shown, not added.
- `store_id` in request bodies is the numeric `stores.id`, never the store
  number (paths use the number).
- The finances trend is grouped by the server: ISO weeks (keyed by their
  Monday) up to 92 days, months beyond. There is no grouping parameter.
- A payment-level lump sum from Daily Pay ("one price for the day") was
  converted to an Inspection line, as the owner asked.
- The technician link is throttled at 60 requests a minute per link and IP.

---------------------------------------------------------------------------

## 1. Catalog

### Technicians
Technician = person OR company. Fields: `name` (unique ignoring case and
repeated spaces → 422 `name: "There is already a technician called X."`),
`phone`, `contact_person`, `category_id` (editable list), `location`,
`coverage_notes`, `hourly_rate`, `emergency_available` (bool),
`emergency_notes`, `coverage_stores` (store numbers, replaces the list),
`user_id` (always null for now; read-only).

Presented technician adds: `category {id,name}`, `coverage_stores [{id,
store_number}]`, `covered_issues [{issue_id, title, rating|null, notes,
call_first}]` (when loaded), `average_rating` (string|null: average of
non-null per-issue ratings, 1 decimal), `covered_issue_count`, notes,
attachments, `deleted_at`.

- `GET technicians?search=&category_id=&emergency=1&store=<store_number>&issue_id=&with_trashed=1`
  — `store`/`issue_id` filter to those who cover it. Rank order when
  `issue_id` or `store` given: covers store → covers issue → emergency (if
  `urgent=1`) → call first on that issue → rating desc → name. Each row adds
  `standing {covers_store, covers_issue, call_first, rating}` when ranking.
  Every list row (ranked or not) adds `paid_this_month` (money): non-mistaken
  payments to the technician with `paid_at` in the current calendar month,
  summed by one aggregate subquery. List rows only, not show.
- `POST technicians`, `GET technicians/{t}`, `PATCH technicians/{t}`,
  `DELETE technicians/{t}`, `POST technicians/{t}/restore`
- Coverage of issues: `PUT technicians/{t}/abilities/{issue}` `{rating?:1-5|null, notes?, call_first?}`
  — an entry with nothing set is kept ("covers this issue, not rated yet";
  moving the call-first pin away also keeps the old holder covering it),
  `DELETE technicians/{t}/abilities/{issue}` takes the issue off,
  `GET technician-abilities` (exists; drop the `overall` part).
- REMOVED: `PATCH technicians/{t}/rating` (overall rating is computed now).

### Technician categories
`GET/POST categories`, `PATCH/DELETE categories/{c}` — `name` unique the same
way. Seeded: In-house, Company, Independent.

### Suppliers (new)
`name` (unique, same rule), `phone`, `website`, `description`.
`GET suppliers?search=&with_trashed=1`, `POST suppliers`, `GET suppliers/{s}`,
`PATCH suppliers/{s}`, `DELETE suppliers/{s}`, `POST suppliers/{s}/restore`,
`POST suppliers/{s}/notes`, `POST suppliers/{s}/attachments`.
Presented: id, name, phone, website, description, `total_paid` (money:
non-mistaken payments to the supplier — on list rows too, one aggregate
subquery, and on show/create/update/restore), notes, attachments, deleted_at.

### Parts
Adds `brand`, `model`, `part_number`, `unit` (each|box|pack|ft|m|gal|l|kg|lb|set),
`track_serials` (bool). `name` unique the same way.
- `GET parts?search=&trashed=with|only&per_page=` — `search` matches name,
  brand, model or part number, ignoring capitals.
- `GET parts/{part}` → the list row plus `units_count` (part units recorded,
  every status). Deleted parts are readable (withTrashed).
- `GET parts/{part}/units?status=` → part units (serial_number, status
  in_stock|installed|returned|damaged_lost, storage_location {id,name},
  ticket_issue {id, ticket_id, store_number, title}, installed_at,
  warranty_until).
- `PATCH part-units/{unit}` `{serial_number?, warranty_until?}`

---------------------------------------------------------------------------

## 2. Tickets and the issue workflow

Statuses (`App\Enums\IssueStatus`):

| value | label | whose turn |
|---|---|---|
| new | New | office |
| in_review | In review | office |
| waiting_on_store | Waiting on store | store |
| inspection | Inspection | technician |
| waiting_approval | Waiting for approval | approver |
| scheduled | Scheduled | technician |
| work_done | Work done (checking) | office / store |
| closed | Closed | — |
| cancelled | Cancelled | — |

Ticket status stays DERIVED from its issues (`TicketStatusService` + its SQL
twin), first match wins: any waiting_on_store → waiting_on_store; any
waiting_approval → waiting_approval; any scheduled → scheduled; any
inspection → inspection; any work_done → work_done; any in_review →
in_review; any new → new; all cancelled → cancelled; otherwise closed.
`TicketStatus` enum mirrors IssueStatus.

Step numbers for the progress strip (`IssueWorkflowService::stepOf`):
new 1, in_review 2, waiting_on_store 3, inspection 5, waiting_approval 7,
scheduled 8, work_done 10, closed/cancelled 11.

### Create a ticket
`POST stores/{store}/tickets` and `POST tickets` (other store) — unchanged
plus per issue: `impact` (none|slowed|partly_down|closed),
`contact_name`, `contact_phone`. Troubleshooting confirmation stays required
exactly as today. New issues start `new`.

### Read
`GET stores/{store}/tickets/{ticket}/issues/{ticketIssue}` (and the list)
returns the issue with:
- core: id, ticket_id, title, issue {id,title}, other_title, description,
  priority, assigned_priority, impact, contact_name, contact_phone, status,
  status_label, step, target_completion_at, next_action, work_performed,
  resolution, store_confirmed_at, store_confirmed_working,
  store_confirmed_by_name, closed_at, created_at, creator {id,name}.
- `technicians` (assigned to): [{id, name, phone, category}]
- `info_requests`: [{id, question, asked_by {name}, created_at, answer,
  answered_by_name (typed, optional), answered_by {name}, answered_at, mistaken}]
- `inspections`: [{id, technician {id,name}, status scheduled|done|cancelled,
  scheduled_date, scheduled_time, scheduled_time_to, inspection_fee, done_at, findings, recommended_repair,
  parts_needed, estimated_cost, attachments, notes, mistaken}]
- `approvals`: [{id, amount, level, level_label, decision approved|rejected,
  approver_name, decided_at, note, inspection_id, attachments, mistaken}]
- `schedules`: [{id, kind work|inspection, technician {id,name}, date, time,
  expected_completion_date, trip_id, inspection_id, mistaken, ended_at,
  ended_reason, delays [...]}] — `ended_at`/`ended_reason` are set when the
  issue was closed or cancelled before the booking's day (see "Finished
  issues" below); an ended booking cannot be moved.
- `visits`: [{trip_id, trip_stop_id, technician {id,name}, store_number,
  arrived_at, left_at, task_status pending|done|not_done, done_at,
  not_done_reason, purpose work|inspection}]
- `costs`: {estimated (latest non-mistaken inspection estimate|null),
  approved (latest approved approval amount|null), labor, inspection,
  parts, gas, other, total_actual} — sums of non-mistaken payment lines linked
  to this issue, plus `unpaid_purchases` (technician purchases not yet paid)
  and `stock_used_value` (Used movements from storage on this issue, at cost).
- `payments`: [{payment_id, line_id, paid_at, paid_to {type
  technician|supplier, id, name}, type, amount, invoice_number, has_invoice,
  invoices [{id, original_name, url}]}] — `invoices` are the attachments on
  that payment.
- `purchases`: technician purchases on this issue (see §4).
- `stock_used`: [{movement_id, moved_at, part {id,name}, quantity, value,
  storage_location {id,name}, serials[]}]
- `warranties`: existing shape.
- `workflow`: `{ step, status, next: [{action, label, primary: bool}],
  checklist: [{key, label, done: bool}], approval_level_for: {amount,
  level, label} | null }` — `next` lists the actions valid now (first one
  primary). The checklist is the close checklist (§ close).
- `status_changes`, `notes`, `attachments`, troubleshooting fields — as today.
  An attachment carries `kind`: `before` | `after` for a job photo (its
  `original_name` starts "Before - " / "After - ", §3), else null.
- `timeline`: one merged, newest-first list of {at, kind, text, actor}
  (status changes, info requests/answers, inspections, approvals,
  schedules, visits, payments, purchases, stock used, notes).

Ticket lists (`GET tickets`, `GET stores/{store}/tickets`, and the ticket
returned by create/restore) keep their light issue rows (no costs, no
timeline) and add `technicians` [{id, name}] per issue — who it is assigned
to, removed technicians included, eager-loaded (no query per issue).

### Issue lookup (pickers)
`GET ticket-issues?search=&store=<store_number>&statuses[]=&limit=` — global
(every store), newest first, `limit` default 20, max 100. `search` matches the
catalog title, `other_title`, the description (ignoring capitals) or the
ticket id (digits, a leading `#` allowed). Issues of a deleted ticket are left
out. Rows: `{id, ticket_id, title, status, status_label, priority {value,
label}, store {id, store_number}|null, other_store}` — never the full read.

### Workflow actions
All under `stores/{store}/tickets/{ticket}/issues/{ticketIssue}/…`, POST,
return the presented issue. Each moves the status as shown; a step that does
not fit the current status → 422 with a plain reason.

| path | body | effect |
|---|---|---|
| `review` | target_completion_at?, next_action?, assigned_priority? | new → in_review. In every other open status (in_review, waiting_on_store, inspection, waiting_approval, scheduled, work_done) it only updates these fields and the status stays; refused once closed/cancelled |
| `info-requests` | question (required), files[] | → waiting_on_store; outbox `maintenance.issue.info_requested` |
| `info-requests/{request}/answer` | answer (required), files[], answered_by_name? (who answered, free text, max 120) | → in_review; outbox `maintenance.issue.info_answered` |
| `inspections` | technician_id, scheduled_date, scheduled_time?, scheduled_time_to? (a window: the end needs a start and comes after it), inspection_fee? (what the inspection itself costs; it is the issue's inspection cost until a live payment line with that `issue_inspection_id` covers it — never both, `costs.inspection_fees` = fees still uncovered; a fee also ticks `cost_recorded`), instructions? | → inspection; creates a `kind=inspection` schedule + an inspection(status scheduled) + adds the issue to the technician's open trip for that date (TripService::scheduleIssue). Response adds `trip_link` {url_token, expires_after_hours} when a NEW token was issued. |
| `inspections/{inspection}/result` | findings (required), recommended_repair?, parts_needed?, estimated_cost?, files[] | marks the inspection done; status unchanged (stays inspection) |
| `approval-request` | amount (required), inspection_id? | → waiting_approval; outbox `maintenance.issue.approval_needed` with level |
| `approvals` | amount, decision approved\|rejected, approver_name, note?, inspection_id?, files[] | records it; → in_review (next_action set to "Schedule the work" / "Find another option") |
| `schedule` | technician_id, date, time?, time_to? (window), expected_completion_date?, instructions? | → scheduled; creates a `kind=work` schedule, adds the issue to the technician's trip (same as inspections), puts the technician on the issue. `trip_link` as above. |
| `schedules/{assignment}/move` | date, time?, time_to?, reason | writes an assignment delay; moves the trip stop to the matching trip. The old day's trip is REMOVED when that leaves it with no stops and it was never used (no session ever, link never opened, no purchases / notes / files, not mistaken) |
| `work-done` | work_performed (required), files[] (before/after photos), warranty? {body, expiry_date} | scheduled → work_done (the specialist recording it for the technician) |
| `confirmation` | working (bool), note?, confirmed_by_name? (who said so, free text, max 120) | records store_confirmed_*; working=false → in_review with next_action "Schedule more work" |
| `close` | resolution repaired\|fixed_by_troubleshooting\|not_needed\|replaced, final_note (REQUIRED, ≥ 10 chars: what was wrong and how it was fixed), what_we_learned?, override_reason? | → closed. Checklist keys: `resolved` (store confirmed working, or resolution fixed_by_troubleshooting/not_needed), `cost_recorded` (a payment line on the issue, or resolution fixed_by_troubleshooting/not_needed), `invoice_attached` (a linked payment has an attachment, same exemption), `notes_completed` (work_performed set, same exemption). Any not done → 422 unless `override_reason` is given (logged in the status change reason). |
| `jump` | status (any), reason (required) | the escape hatch: any status → any status, logged |
| `cancel` | reason | existing endpoint, keep |
| `assigned-priority` | existing, keep | |

Finished issues: when an open issue becomes closed or cancelled (close,
cancel, ticket cancel, jump — `TicketStatusService::changeIssueStatus` calls
`TripService::releaseFinishedIssue`), what was still planned for it stops:
- its PENDING tasks on open trips: on a trip with no clocked session the
  task is taken off (an empty stop nobody went to goes too, and an unused
  trip left empty is removed, as for a move); on a trip with clocked time
  the task stays as history, `task_status` not_done with `not_done_reason`
  "No longer needed: the issue was closed." / "…cancelled.", so the
  technician sees it. Done / not done tasks are never touched.
- its bookings dated today or later (not mistaken) get `ended_at` and
  `ended_reason` (same words). Past bookings stay as they were.
- its inspections still `scheduled` become `cancelled`.

Approval levels come from `maintenance_settings.approval_levels`, default
`[{"max": 300, "level": "specialist", "label": "Maintenance Specialist"},
{"max": 1000, "level": "manager", "label": "Maintenance Manager"},
{"max": null, "level": "management", "label": "Management"}]` (`max`
exclusive upper bound, null = no limit).

Settings: `GET maintenance-settings`, `PUT maintenance-settings`
`{approval_levels?, trip_link_idle_hours?}`.

REMOVED from tickets: issues/status, defer, wait, assignments (+delays,
change-technicians, mistaken), diagnoses, attendance-entries (+events),
part-usages, pay-entries, warranties (creation moves into work-done),
tickets/{t}/technicians attach, `GET unpaid-work` (moved, §4),
daily-pay-entries/*, attendance-entries (global).

---------------------------------------------------------------------------

## 3. Trips (clocking)

Event kinds (`App\Enums\TripEventKind`): break_start, break_end,
travel_start, travel_end, parts_run_start, parts_run_end. Clock in/out are
the session itself. Paid time of a session = (clock_out − clock_in) − breaks.
Durations per session: work (minus travel, parts run, breaks), travel,
parts_run, break, paid (= work + travel + parts_run), all in minutes.

Trip statuses: open | closed. A trip link works while: status open, not
mistaken, and `last_used_at` (or created_at) is within `link_idle_hours`.
Opening the link counts as use. Token: 40 random chars, URL-safe; only the
sha256 hash is stored; the plain token is returned ONCE when issued.

`TripService` public methods other services call:
- `scheduleIssue(TicketIssue $issue, Technician $t, string $date, ?string $time, string $purpose /* work|inspection */, ?string $instructions, User $by): array{trip: Trip, stop: TripStop, token: ?string}`
  — finds the technician's OPEN trip whose planned_date is that date (or
  creates one), finds/creates the stop for the issue's store, attaches the
  issue (purpose). `token` is non-null only when the trip was created.
- `issueLink(Trip $trip): string` — rotate and return the plain token.

Dashboard endpoints (MOS):
- `GET trips?status=open|closed&technician_id=&from=&to=&on_clock=1&search=` (paginated)
  — `history_before=YYYY-MM-DD` (the viewer's today) keeps the History tab's
  trips: planned before that day, plus closed trips of any day (a future open
  trip is never history).
- `POST trips` `{technician_id, planned_date, instructions?, stops?: [{store_id?|other_store?, ticket_issue_ids[]}]}` → trip + `trip_link`
- `GET trips/{trip}`, `PATCH trips/{trip}` `{planned_date?, instructions?, link_idle_hours?}`
- `POST trips/{trip}/link` → new `trip_link`; `POST trips/{trip}/close`; `POST trips/{trip}/reopen`; `POST trips/{trip}/mistaken`
- `POST trips/{trip}/stops` `{store_id?|other_store?, ticket_issue_ids[], purpose?}`; `POST trips/{trip}/stops/{stop}/mistaken`
- `POST trips/{trip}/sessions` `{clock_in, clock_out?, using_car?, odometer_in?, odometer_out?, reason}` (specialist adds one)
- `PATCH trips/{trip}/sessions/{session}` `{clock_in?, clock_out?, odometer_in?, odometer_out?, reason}`; `POST …/sessions/{session}/mistaken` `{reason}`
- `POST trips/{trip}/sessions/{session}/events` `{kind, at, reason}`; `PATCH …/events/{event}` `{at, reason}`; `POST …/events/{event}/mistaken`
- `POST trips/{trip}/notes`, `POST trips/{trip}/attachments`
- `GET trip-sessions/unpaid?technician_id=` → sessions with no payment claim (not mistaken, closed), with durations and the ticket issues of their trip's stops.

Dashboard edits write `trip_audits` (before/after, reason, user).

Presented trip: id, technician {id, name, phone}, status, planned_date,
instructions, link {active: bool, expires_at|null, last_used_at}, on_clock
(bool), open_session_id, `state` (off_clock | on_clock | on_break |
travelling | parts_run — `TripService::state`, the same reading as the link;
on list rows and show), totals {paid_minutes, break_minutes, miles},
stops [{id, store {id, store_number}|null, other_store, status
planned|arrived|done|not_done, arrived_at, left_at, arrival_odometer,
not_done_reason, attachments, issues [{ticket_issue_id, ticket_id, title,
status, priority, purpose, task_status, done_at, not_done_reason}]}],
sessions [{id, clock_in, clock_out, using_car, odometer_in, odometer_out,
miles, source technician|specialist|migrated, mistaken, paid (bool),
payment_id|null, durations {...}, events [{id, kind, label, at,
mistaken}], attachments}], purchases [...], notes, attachments, audits
(on show only), created_at.

Public endpoints (`public/trips/{token}`, middleware `trip.token`, throttle
60/min; 404 for an unknown token, 410 `{message}` for an expired/closed one):
- `GET public/trips/{token}` → the trip as above minus audits/private notes
  (`state` included), plus `stores` (for adding a stop: stores of open issues assigned to him + his
  coverage stores).
- `POST …/clock-in` `{using_car?, odometer?, photo?}` — refuses if he is
  already clocked in anywhere (one open session per technician).
- `POST …/clock-out` `{odometer?, photo?}` — closes open events first.
- `POST …/events` `{kind}` — at = now; must make sense (no break_end without
  break_start, only on the clock).
- `POST …/stops` `{store_id}` — add a store visit.
- `POST …/stops/{stop}/arrive` `{odometer?, photo?}`, `POST …/stops/{stop}/leave`,
  `POST …/stops/{stop}/not-done` `{reason}`
- `POST …/stops/{stop}/issues/{ticketIssue}/done` `{note?, findings?, estimated_cost?, files[], before[], after[]}`
  — purpose work: the issue → work_done (work_performed = note), outbox
  `maintenance.issue.work_done`; purpose inspection: the open inspection
  gets findings/estimate and is done (status unchanged). Files go on the issue:
  `files[]` as sent, `before[]` / `after[]` with `original_name` prefixed
  "Before - " / "After - " (no column of their own;
  `AttachmentService::BEFORE_PREFIX`), read back as attachment `kind`
  before|after.
- `POST …/stops/{stop}/issues/{ticketIssue}/not-done` `{reason}`
- `POST …/purchases` `{description, part_id?, quantity?, total_cost, supplier_id?|supplier_name?, ticket_issue_id?, serial_number?, receipt[]}`
  (supplier_name creates the supplier when new, same uniqueness).
- `POST …/notes` `{body, stop_id?, files[]}` — always non-private.
- `GET …/suppliers?search=`, `GET …/parts?search=` — name lookups.

Every technician action stamps `last_used_at`.

---------------------------------------------------------------------------

## 4. Payments

Line types (`App\Enums\PaymentLineType`): hourly, fixed, inspection, parts,
gas, other. Methods (`App\Enums\PaymentMethod`): cash, check, card,
bank_transfer, zelle, other (null = not recorded, migrated rows).

A payment is paid to exactly one technician XOR one supplier.
Every line has exactly one of store_id / other_store / storage_location_id
(storage only for parts going into storage).

Line amount rules (server computes, client previews):
- hourly: `hours` = sum of paid minutes of `trip_session_ids` / 60 unless
  `hours` is sent (then `hours_overridden` = true); `rate` defaults to the
  technician's hourly_rate; amount = hours × rate. A session already paid →
  422.
- parts: amount = quantity × unit_cost. `destination`: `storage` (needs
  storage_location_id, optional `place` [{level_id, value_id}]) → a Bought
  stock movement (payment_line_id set); `ticket` (needs ticket_issue_ids) →
  linked to the issue(s), no stock movement. Parts with track_serials need
  `serials[]` (count = quantity) → part_units (in_stock at the location, or
  installed on the first issue). `technician_purchase_ids[]` marks those
  purchases paid back by this line.
- gas: amount sent, or miles × rate.
- fixed / inspection / other: amount sent. inspection may carry
  issue_inspection_id. other may carry `technician_purchase_ids[]` too: it
  pays back what the technician bought that is not a catalog part (zip ties,
  a cleaner), same checks as on a parts line (his own, not mistaken, not paid
  yet). Any other line type naming purchases → 422.
Payment total = sum of its lines.

- `GET payments?from=&to=&technician_id=&supplier_id=&category_id=&store=&type=&search=&include_mistaken=1` (paginated; search matches invoice_number, reference, names)
  → rows {id, paid_at, paid_to {type, id, name, category}, payment_method {id, name}|null, reference,
  invoice_number, total, line_count, stores [store_number…], types [...],
  has_invoice, mistaken, mistaken_reason (the body of its `mistaken` note, or
  null), created_at}, and `meta.totals {count, total}`.
- `POST payments` `{paid_at, technician_id?|supplier_id?, payment_method_id?, reference?, invoice_number?, note?, notes[]?, files[], lines: [{type, store_id?|other_store?|storage_location_id?, description?, amount?, hours?, rate?, trip_session_ids[]?, ticket_issue_ids[]?, part_id?, quantity?, unit_cost?, bought_from_supplier_id?, destination?, place?, serials[]?, technician_purchase_ids[]?, miles?, issue_inspection_id?, notes[]?, files[]?}]}`
  (multipart allowed: lines as JSON string in `lines`, or bracket notation).
  payment_method_id picks from the editable list `GET/POST payment-methods`,
  `PATCH/DELETE payment-methods/{id}` (names unique ignoring case and spaces;
  delete refused with 422 while a payment uses it; seeded with Cash, Check,
  Card, Bank transfer, Zelle, Other). The old `payments.method` column stays
  for older readers and is no longer written. reference / invoice_number are
  optional and the dashboard no longer asks for them. Payments can be
  recorded for closed issues too (an invoice that comes in late). Notes and files, as many as wanted, on the payment and on
  each line: `notes[]` (strings; blank ones dropped, `note` still accepted)
  and `files[]` (≤ 10 MB each) on the payment; `lines[i][notes][]` and
  `lines[i][files][]` on a line. A line's files need bracket notation (they
  cannot ride inside a JSON-string `lines`). A parts line into storage writes
  a Bought stock movement whose `moved_at` is now when `paid_at` is today,
  else 12:00 UTC on `paid_at` (so it reads as that day in every time zone).
- `GET payments/{payment}` → the row (mistaken_reason included) + lines [{…,
  store {id,store_number}, storage_location {id,name}, part {id,name},
  bought_from {id,name}, place (parts going to storage: where that part sits
  in the location NOW — the stock balance's address [{level_id, level,
  value_id, value}], `[]` when nobody has said; null on every other line),
  ticket_issues [{id, ticket_id, title, store_number}], trip_sessions [{id,
  clock_in, clock_out, paid_minutes}], stock_movement_id, serials[],
  technician_purchases[], notes [the line's notes], attachments [the line's
  files]}], notes, attachments, revisions [{id, edited_by, created_at}]
- `POST payments/{payment}/edit` — same body as create + `expected_updated_at`
  (409 when stale). Replaces the lines: undoes the old lines' stock movements
  (reversal, `undone`), releases their session claims and purchases, then
  writes the new lines. Saves a revision snapshot first.
  Notes and files on edit:
  - the payment's own stay; `remove_note_ids[]` / `remove_attachment_ids[]`
    take the named ones off (soft-deleted; an id not on this payment → 422 on
    that field). New `notes[]` / `files[]` are added.
  - a line's saved notes and files belong to the line being replaced, so a
    new line names the ones it keeps: `lines[i][keep_note_ids][]`,
    `lines[i][keep_attachment_ids][]` (ids from the payment's current lines;
    they move to the new line). One kept by no line is removed
    (soft-deleted). An id that is not on a line of this payment → 422
    `lines.i.keep_note_ids`; the same id on two lines → 422. New
    `lines[i][notes][]` / `lines[i][files][]` are added on top.
  - the revision snapshot keeps everything as it was before the edit.
  `remove_*` and `keep_*` are refused on create.
- `POST payments/{payment}/mistaken` `{reason}` — mistaken=true, same undo
  as edit, lines kept for history (with their notes and files).
- `POST payments/{payment}/notes`, `POST payments/{payment}/attachments`
- `GET unpaid-work?technician_id=` → per technician {technician, sessions
  [unpaid sessions as in trip-sessions/unpaid], purchases [unpaid technician
  purchases], totals {paid_minutes, purchases_total}}.

Inspection lookup (the payment dialog's Inspection line):
`GET issue-inspections?technician_id=&unpaid=1&search=&limit=` — global,
newest first, mistaken inspections never, `limit` default 50, max 100.
`search` matches the issue's title (catalog or `other_title`, ignoring
capitals) or the ticket id. Rows: `{id, ticket_issue {id, ticket_id, title,
store {id, store_number}|null, other_store}, technician {id, name}|null,
status, status_label, done_at, scheduled_date, estimated_cost, paid}` —
`paid` = a line of a non-mistaken payment has `issue_inspection_id` = this;
`unpaid=1` keeps the ones that are not.

Technician purchases (dashboard): `GET technician-purchases?technician_id=&unpaid=1`,
`POST technician-purchases` (same body as the public one + technician_id,
trip_id?), `POST technician-purchases/{p}/mistaken`.
Presented: id, technician {id,name}, trip_id, ticket_issue {id, ticket_id,
title, store_number}|null, part {id,name}|null, description, quantity,
total_cost, supplier {id,name}|null, serial_number, bought_at, paid (bool),
payment_id|null, receipts (attachments), mistaken.

---------------------------------------------------------------------------

## 5. Storage

Movement types (`App\Enums\StockMovementType`) and direction:
bought +1, used −1, returned +1, moved (line says), opening_stock +1,
stock_count (line says), damaged_lost −1, undone (line says).
`POST stock-movements` accepts used, returned, moved, opening_stock,
damaged_lost, each with an optional `technician_id` (who did it; presented as
`technician {id, name}`). bought only comes from a parts payment line; stock_count
only from applying a count; undone only from `/mistaken`. `used` may carry
`ticket_issue_id` (from the ticket page or storage page); serial-tracked
parts need `serials[]` on the line (units must be in_stock at that location;
used → installed on that issue; damaged_lost → damaged_lost; returned →
in_stock).
Presented movements add `type_label`, `ticket_issue`, `payment` {id,
paid_to name}, `stock_count_id`, line `serials`.

Stock counts:
- `GET stock-counts?storage_location_id=&status=`
- `POST stock-counts` `{storage_location_id, storage_place_value_id?}` → draft
  with a line per part that has a balance there (or in that place), expected
  = current balance, counted null.
- `GET stock-counts/{count}` → lines {id, part {id,name,unit}, expected,
  counted, difference, unit_cost (average cost), value_difference}, totals
  {missing_quantity, missing_value, extra_quantity, extra_value,
  counted_lines, total_lines}. While the count is a draft, `expected` (and
  so difference and totals) is the balance there NOW — what apply will use —
  so stock moved after the sheet was made never shows as a correction.
- `PATCH stock-counts/{count}` `{lines: [{part_id, counted|null}], notes?}`
  (also adds parts not on the sheet) — draft only.
- `POST stock-counts/{count}/apply` — one stock_count movement with a line per
  difference (expected recomputed at apply time; difference vs counted);
  status applied; values frozen.
- `DELETE stock-counts/{count}` — drafts only.

Existing storage-locations, place-levels, stock-balances keep their routes.

---------------------------------------------------------------------------

## 6. Analytics

`GET maintenance-analytics/{section}` adds sections `finances` and `coverage`.

finances (`from`, `to` required, any range; `stores[]`, `category_id`,
`technician_id`, `supplier_id` optional; mistaken excluded):
`{ totals {spent, previous_spent, change_pct, payments, lines},
by_type [{type, label, amount}], trend [{period (YYYY-MM or ISO week start), amount, by_type {…}}],
by_store [{store_number|other, amount}], by_technician [{id, name, category, amount}],
by_supplier [{id, name, amount}], by_category [{id|null, name, amount}],
stock {on_hand_value, bought_value, used_value, count_losses, count_gains, damaged_lost_value},
tickets {cost_per_ticket_avg, top_tickets [{ticket_id, store_number, title, amount}],
by_issue [{issue_id, title, amount, tickets}], estimate_vs_actual [{ticket_issue_id, title, estimated, actual}],
approvals_by_level [{level, label, count, amount}], recurring_costly [{store_number, issue_id, title, tickets, amount}]},
unpaid {sessions, paid_minutes, purchases, purchases_total} }`

coverage (`stores[]`, `issue_ids[]`, `category_id` optional):
`{ kpis {technicians_for_stores, technicians_for_issues, technicians_for_both,
emergency_available}, cells [{store_number, issue_id, title, technicians,
emergency, average_rating|null, gap: technicians <= 1}], gaps (count) }`

summary / activity / watchlist: as today, with the new statuses.
`GET tickets/analytics`: status breakdown uses the new statuses.

## Changes 2026-11-04

- Tickets can no longer be deleted (`DELETE stores/{store}/tickets/{ticket}` removed).
- `GET maintenance-analytics/finances` takes `technician_ids[]` (several technicians together); `technician_id` still works for one.
