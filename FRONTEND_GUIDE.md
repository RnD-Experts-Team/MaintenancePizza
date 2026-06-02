# MaintenancePizza — Frontend Workflow Guide

This guide tells a frontend developer everything needed to build the UI for the
ticket system: which endpoint to call at each step, the request/response shapes,
and what each page should look like and contain.

The full machine-readable contract lives in [`public/openapi.json`](public/openapi.json)
(load it into Swagger UI / Redoc / Insomnia / Postman). This document is the
narrative companion.

---

## 1. Conventions you must know first

| Topic | Rule |
|---|---|
| **Base URL** | `http://localhost:8000/api` (dev). All paths below are relative to this. |
| **Auth** | Every request needs `Authorization: Bearer <token>`. There is **no** login/registration endpoint in this service — your auth layer supplies the token. For local dev, run `php artisan db:seed` and copy the printed **DEV API TOKEN**. |
| **Headers** | Always send `Accept: application/json`. (The API forces JSON anyway, but be explicit.) |
| **Store id** | The `{store}` path segment is the **store number string**, e.g. `03795-00001` — never a numeric id. |
| **Responses** | Single records: `{ "data": { ... } }`. Lists: `{ "data": [ ... ], "links": {...}, "meta": {...} }` (Laravel pagination). |
| **No edits** | There are **no** PUT/PATCH endpoints. To "fix" a leaf record (part, diagnosis, attendance, pay) you call its `/mistaken` action and create a new one. Lifecycle changes are explicit POST actions. |
| **Ticket status is derived** | Never sent by you. It is computed from the issues and returned as `data.status`. |
| **Enums** | Priority/status fields are returned as `{ "value": "...", "label": "..." }`. When you POST, send the raw `value` string. |
| **Errors** | `401 {"message":"Unauthenticated."}`, `422 {"message":"...","errors":{"field":["..."]}}`, `404` for unknown store/ticket/issue. |

### Enum values
- **Priority:** `urgent`, `high`, `medium`, `low`
- **Issue status:** `pending`, `assigned`, `in_progress`, `complete`, `deferred`
- **Ticket status (derived):** `pending`, `assigned`, `in_progress`, `complete`

### How the ticket status is derived (so your badges match the API)
1. any issue `in_progress` → **In Progress**
2. else any issue `assigned` → **Assigned**
3. else all issues `complete`/`deferred` → **Complete**
4. else → **Pending**

---

## 2. The big picture (lifecycle)

```
File ticket (issues = pending)
        │
        ▼
Assign issue(s) to technician(s) + date     → issue: assigned   → ticket: assigned
        │  (optionally Delay / Change technicians)
        ▼
Set issue in_progress                        → ticket: in_progress
        │  (add diagnoses, parts, attendance, pay, warranty along the way)
        ▼
Complete issue  ── or ── Defer issue (reason → spawns a new pending child)
        │
        ▼
All issues complete/deferred                 → ticket: complete
        │
        ▼
Set final note
```

Everything attached to issues (diagnosis, attendance, parts, pay, warranty,
assignment, technicians) can target **one or many** issues at once via
`ticket_issue_ids` — that is how "grouping" works. A part can target a single
issue while an attendance targets several.

---

## 3. Reference / catalog data (build dropdowns from these)

Load these once and cache them; they populate selectors throughout the UI.

| Data | List | Create | Delete | Restore |
|---|---|---|---|---|
| Issues | `GET /issues` | `POST /issues` | `DELETE /issues/{id}` (soft) | `POST /issues/{id}/restore` |
| Technicians | `GET /technicians` | `POST /technicians` | `DELETE /technicians/{id}` (soft) | `POST /technicians/{id}/restore` |
| Categories | `GET /categories` | `POST /categories` | `DELETE /categories/{id}` (hard) | — |
| Parts | `GET /parts` | `POST /parts` | `DELETE /parts/{id}` (soft) | `POST /parts/{id}/restore` |

- Add `?trashed=with` to include soft-deleted, `?trashed=only` for just deleted (lets you build a "restore" screen). Soft-deleted items must not appear in selection dropdowns.
- Deleting a **category** does not delete its technicians; it just clears their category.

---

## 4. Workflow step-by-step (endpoints + payloads)

### 4.1 File a ticket
`POST /stores/{store}/tickets`
```json
{
  "issues": [
    { "issue_id": 1, "priority": "high", "description": "Oven not heating" },
    { "other_title": "Strange smell near vents", "priority": "low", "description": "Investigate" }
  ]
}
```
- Each line is **either** `issue_id` (from the issue catalog) **or** `other_title` (free text) — not both. You may mix freely and repeat "other" as many times as you like.
- Returns the ticket with `status.value = "pending"` and its `issues[]`.

### 4.2 Open the ticket detail ("everything in one look")
`GET /stores/{store}/tickets/{ticket}/issues`
- Returns every issue of the ticket, each with its **full history**: `diagnoses`, `attendance_entries`, `part_usages`, `pay_entries`, `warranties`, `assignments` (incl. `delays`), `technicians`, `status_changes`, and `children` (deferral chain).
- `GET .../issues/{ticketIssue}` returns a single issue the same way.

### 4.3 Assign issues to technicians
`POST /stores/{store}/tickets/{ticket}/assignments`
```json
{ "ticket_issue_ids": [1], "technician_ids": [3, 5], "assigned_date": "2026-06-10", "assigned_hour": "09:30" }
```
- Attaches technicians to the issues and moves them to `assigned`. `assigned_hour` is optional (`HH:MM`).

**Delay an assignment** (keeps history): `POST .../assignments/{assignment}/delays`
```json
{ "new_date": "2026-06-12", "new_hour": "14:00", "reason": "Part on backorder" }
```
**Change technicians without rescheduling** (NOT a delay): `POST .../assignments/{assignment}/change-technicians`
```json
{ "technician_ids": [2] }
```

### 4.4 Move an issue through statuses
`POST /stores/{store}/tickets/{ticket}/issues/status`
```json
{ "ticket_issue_ids": [1, 2], "status": "in_progress" }
```
- Allowed: `pending`, `assigned`, `in_progress`, `complete`. **Not** `deferred` (use defer).
- Every status change is recorded in the issue's `status_changes` history with the acting user.

### 4.5 Defer an issue (with reason → spawns a child)
`POST /stores/{store}/tickets/{ticket}/issues/{ticketIssue}/defer`
```json
{ "reason": "Needs HVAC specialist" }
```
- Marks the issue `deferred` and returns a **new pending child issue** (same issue/priority/description, `parent_id` set). The reason is stored in the parent's `status_changes`.

### 4.6 Add workflow records (each targets one-or-many issues)

| Record | Endpoint | Body | Files? |
|---|---|---|---|
| Diagnosis | `POST .../diagnoses` | `ticket_issue_ids[]`, `body?` | yes (`files[]`) |
| Attendance | `POST .../attendance-entries` | `ticket_issue_ids[]`, `technician_id`, clock fields | yes |
| Part usage | `POST .../part-usages` | `ticket_issue_ids[]`, `part_id`, `cost` | yes |
| Pay/driving | `POST .../pay-entries` | `ticket_issue_ids[]`, `technician_id`, money fields | no |
| Warranty | `POST .../warranties` | `ticket_issue_ids[]`, `body` | yes |
| Attach techs | `POST .../technicians` | `ticket_issue_ids[]`, `technician_ids[]` | no |

- **Attachments**: send the record as `multipart/form-data` with `files[]` and array fields as `ticket_issue_ids[]`. The response's attachments include a ready-to-use `url`.
- **Attendance clocks** (`start_clock`, `end_clock`, `start_break`, `end_break`, `start_parts_run`, `end_parts_run`) are all optional datetimes and unconstrained — the dispatcher can set any subset. The technician must already be attached to at least one of the target issues (assign or attach them first).
- **Pay** has two independent rate groups: attendance (`base_pay`, `performance_pay` per hour) and driving (`driving_base_pay`, `driving_performance_pay` per hour, plus `driving_time`, `miles_driven`, `per_mile_rate`). All optional. Multiple entries per technician allowed.

**Mark a leaf record mistaken** (the "undo" since there are no edits):
`POST .../diagnoses/{id}/mistaken`, `.../attendance-entries/{id}/mistaken`, `.../part-usages/{id}/mistaken`, `.../pay-entries/{id}/mistaken`. The record stays for history (`mistaken: true`) and drops out of cost rollups; render it struck-through/greyed.

### 4.7 Close out the ticket
- Complete or defer every issue; the ticket auto-derives to `complete`.
- `POST /stores/{store}/tickets/{ticket}/final-note` with `{ "final_note": "..." }` (send `null` to clear).

### 4.8 Delete / restore a ticket
`DELETE /stores/{store}/tickets/{ticket}` (soft-delete) and `POST .../restore`.

---

## 5. Listing & filtering tickets

- **Store-scoped list:** `GET /stores/{store}/tickets`
- **Global list (all stores):** `GET /tickets`

Both accept these query filters (combine freely):

| Filter | Meaning |
|---|---|
| `store=03795-00001` | (global list) limit to a store |
| `status=in_progress` | by derived ticket status |
| `issue_id=5` | tickets containing catalog issue 5 |
| `issue_status=deferred` | tickets with ≥1 issue in that status |
| `priority=urgent` | tickets with ≥1 issue of that priority |
| `created_from` / `created_to` | ticket creation date range |
| `assigned_from` / `assigned_to` | scheduled assignment date range |
| `part_cost_single_gt=100` | ≥1 issue whose (non-mistaken) part cost exceeds N |
| `part_cost_total_gt=500` | whole-ticket part cost exceeds N |
| `technician_id=3` | tickets with an issue assigned to that technician |
| `creator_id=2` | tickets filed by that user |
| `trashed=with` / `trashed=only` | include / only soft-deleted |
| `sort=created_at&dir=desc` | ordering (default newest first) |
| `per_page=25` | page size |

---

## 6. Export
`GET /export/excel` → downloads `maintenancepizza-export.xlsx`, a multi-sheet
workbook with every entity (stores, tickets, issues, status changes, assignments,
delays, diagnoses, attendance, parts, pay, warranties, catalogs, attachments).
Render as a simple "Export to Excel" button that hits this URL with the auth header.

---

## 7. Suggested screens (what each page shows)

### A. Ticket list (per store and global)
- **Top bar:** store selector (global view), filter controls mirroring §5 (status, priority, date ranges, technician, "parts total >", "has deferred issue", trashed toggle), sort.
- **Table rows:** ticket id, store number, **status badge** (color by `status.value`), issues count, created-by, created-at. Row click → ticket detail.
- **Actions:** "File ticket" button; per-row soft-delete; a "Trashed" tab using `?trashed=only` with restore buttons.

### B. File ticket (modal or page)
- Repeatable "issue line" rows. Each row: a toggle between **"Pick from catalog"** (searchable `GET /issues` dropdown) and **"Other (free text)"** (title input), plus a **priority** select and a **description** textarea.
- "Add another issue" button. Submit → §4.1.

### C. Ticket detail — the heart of the app ("whole lifecycle in one look")
Header: ticket id, store number, big derived **status badge**, created-by/at, final-note (with a "Set final note" action), soft-delete button.

Body: one **issue card** per issue (from §4.2), each showing:
- Title (`display_title`), priority badge, **status badge**, description, and — if it's a deferral child — a link to its parent / its `children`.
- A vertical **history timeline** built from the issue's collections, ordered by `created_at`:
  - **Status changes** (`status_changes`): "from → to" with reason (for deferrals) and who/when.
  - **Assignments** (`assignments`): scheduled date/hour, assigned technicians, and a nested **delay log** (`delays`: old → new + reason).
  - **Diagnoses**: body text + an attachment gallery (use `attachments[].url`). Mistaken ones greyed.
  - **Attendance entries**: the clock/break/parts-run times as a small timeline per technician, with attachments. Mistaken greyed.
  - **Part usages**: part name + cost + attachments. Mistaken greyed and excluded from the cost subtotal.
  - **Pay entries**: per-technician attendance vs driving pay breakdown.
  - **Warranties**: text + attachments.
- Per-card action buttons: Assign, Add diagnosis, Add part, Add attendance, Add pay, Add warranty, Set status, Defer, Attach technicians. Each opens a small form that posts to the matching §4.6 endpoint. All forms let the user pick **which issues** the record applies to (default: this issue; allow multi-select for grouping).

A ticket-level **"Total parts cost"** summary = sum of non-mistaken `part_usages.cost` across all issues.

### D. Catalog management (Issues / Technicians / Categories / Parts)
- Simple list + "Add" form per §3, with a trashed/restore view for the soft-deletable ones. Technician form includes a category dropdown.

---

## 8. Local quickstart for the frontend dev
```bash
# 1. Ensure MySQL is running and .env points to it, then:
php artisan migrate:fresh --seed     # creates schema + sample data + prints DEV TOKEN
php artisan serve                    # http://localhost:8000

# 2. Use the printed token:
curl -H "Authorization: Bearer <TOKEN>" -H "Accept: application/json" \
     http://localhost:8000/api/stores/03795-00001/tickets
```
Seeded stores: `03795-00001`, `03795-00002`, `03795-00003`. Seeded issues, technicians, categories and parts are ready to reference.
