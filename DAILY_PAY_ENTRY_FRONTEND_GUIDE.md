# Daily Pay Entry — Frontend Integration Guide

## Overview

The Daily Pay Entry feature records end-of-day technician payments. One **entry** covers an entire day and contains any number of **lines**, where each line represents one technician working at one store. A technician who worked at multiple stores appears as multiple lines within the same entry.

All four endpoints are **global** (not store-scoped) and require the standard `Authorization: Bearer <token>` header.

Base path: `/api/daily-pay-entries`

---

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/daily-pay-entries` | Paginated list with filters |
| `POST` | `/daily-pay-entries` | Create a new entry |
| `GET` | `/daily-pay-entries/{id}` | Full detail + revision history |
| `POST` | `/daily-pay-entries/{id}/edit` | Edit (full replace) |

---

## Create & Edit Payload

Both `POST /daily-pay-entries` and `POST /daily-pay-entries/{id}/edit` accept the **same payload shape**.

Use `multipart/form-data` when uploading files; `application/json` otherwise.

### JSON shape

```json
{
  "date": "2026-06-10",
  "lines": [
    {
      "technician_id": 3,
      "store_id": 7,
      "total_working_hours": 8.5,
      "gas": 25.00,
      "invoices": 150.00,
      "hourly_payment_rate": 18.00,
      "money_owed": 153.00,
      "travel_time": 1.5,
      "total_break_time": 0.5,
      "ticket_issue_ids": [5, 12],
      "notes": [
        { "body": "Technician arrived early", "type": null }
      ]
    },
    {
      "technician_id": 3,
      "store_id": 9,
      "total_working_hours": 4.0,
      "money_owed": 72.00,
      "hourly_payment_rate": 18.00
    }
  ]
}
```

**Field rules:**
- `date` — required, ISO date string `YYYY-MM-DD`
- `lines` — required array, at least one element
- `lines[].technician_id` — required, must exist
- `lines[].store_id` — required, must exist
- All numeric fields (`total_working_hours`, `gas`, `invoices`, `hourly_payment_rate`, `money_owed`, `travel_time`, `total_break_time`) — optional, numeric ≥ 0
- `lines[].ticket_issue_ids[]` — optional; each technician must already be assigned to the issue (see Validation Errors below)
- `lines[].notes[].body` — required when the notes element is present
- `lines[].notes[].type` — optional free-form string

### Uploading files (multipart/form-data)

Use bracket notation for nested fields in `FormData`:

```js
const form = new FormData();

form.append('date', '2026-06-10');

// Line 0 fields
form.append('lines[0][technician_id]', '3');
form.append('lines[0][store_id]', '7');
form.append('lines[0][total_working_hours]', '8.5');
form.append('lines[0][money_owed]', '153.00');

// Direct attachment on line 0
form.append('lines[0][files][]', receiptFile);

// Note on line 0
form.append('lines[0][notes][0][body]', 'Some note text');

// File attached to that note
form.append('lines[0][notes][0][files][]', notePhotoFile);

// Second line (same technician, different store)
form.append('lines[1][technician_id]', '3');
form.append('lines[1][store_id]', '9');
form.append('lines[1][total_working_hours]', '4.0');

// Multiple files on line 1
form.append('lines[1][files][]', file1);
form.append('lines[1][files][]', file2);
```

Max file size: **10 MB per file**.

---

## List Response

`GET /daily-pay-entries` returns a standard Laravel paginated response:

```json
{
  "data": [
    {
      "id": 1,
      "date": "2026-06-10",
      "lines": [
        {
          "id": 1,
          "daily_pay_entry_id": 1,
          "technician_id": 3,
          "technician": { "id": 3, "name": "John Smith", "category": {...} },
          "store_id": 7,
          "store": { "id": 7, "store_number": "03795-00001" },
          "total_working_hours": "8.50",
          "gas": "25.00",
          "invoices": "150.00",
          "hourly_payment_rate": "18.0000",
          "money_owed": "153.00",
          "travel_time": "1.50",
          "total_break_time": "0.50",
          "created_by": 42,
          "created_at": "2026-06-10T18:30:00Z",
          "updated_at": "2026-06-10T18:30:00Z"
        }
      ],
      "created_by": 42,
      "created_at": "2026-06-10T18:30:00Z",
      "updated_at": "2026-06-10T18:30:00Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 3, "per_page": 15, "total": 42, ... }
}
```

**Note:** List items do **not** include `notes`, `attachments`, `ticket_issue_ids`, or `revisions` on lines. Use the detail endpoint for those.

---

## Detail Response

`GET /daily-pay-entries/{id}` returns the full entry with **all nested relations populated**, including creator users at every level. Wrapped in `{ "data": {...} }`:

```json
{
  "data": {
    "id": 1,
    "date": "2026-06-10",
    "lines": [
      {
        "id": 1,
        "technician_id": 3,
        "technician": { "id": 3, "name": "John Smith", "category": {...} },
        "store_id": 7,
        "store": { "id": 7, "store_number": "03795-00001" },
        "total_working_hours": "8.50",
        "gas": "25.00",
        "invoices": "150.00",
        "hourly_payment_rate": "18.0000",
        "money_owed": "153.00",
        "travel_time": "1.50",
        "total_break_time": "0.50",
        "ticket_issues": [
          {
            "id": 5,
            "ticket_id": 10,
            "ticket": {
              "id": 10,
              "store_id": 7,
              "store": { "id": 7, "store_number": "03795-00001" },
              "created_by": 42,
              "creator": { "id": 42, "name": "Dispatcher Dan", "email": "dan@example.com" }
            },
            "issue_id": 3,
            "issue": {
              "id": 3,
              "title": "Refrigerator not cold",
              "description": "...",
              "created_by": 41,
              "creator": { "id": 41, "name": "Manager Mary", "email": "mary@example.com" }
            },
            "other_title": null,
            "priority": "high",
            "description": "Details...",
            "status": "complete",
            "technicians": [
              {
                "id": 3,
                "name": "John Smith",
                "created_by": 40,
                "creator": { "id": 40, "name": "Admin", "email": "admin@example.com" }
              }
            ],
            "created_by": 42,
            "creator": { "id": 42, "name": "Dispatcher Dan", "email": "dan@example.com" },
            "created_at": "...",
            "updated_at": "..."
          }
        ],
        "notes": [
          {
            "id": 10,
            "body": "Technician arrived early",
            "type": null,
            "type_label": null,
            "attachments": [],
            "created_by": 42,
            "creator": { "id": 42, "name": "Dispatcher Dan", "email": "dan@example.com" },
            "created_at": "2026-06-10T18:30:00Z",
            "updated_at": "2026-06-10T18:30:00Z"
          }
        ],
        "attachments": [
          {
            "id": 5,
            "original_name": "receipt.jpg",
            "mime_type": "image/jpeg",
            "size": 204800,
            "url": "http://localhost:8000/storage/attachments/xxxx.jpg",
            "created_at": "..."
          }
        ],
        "created_by": 42,
        "creator": { "id": 42, "name": "Dispatcher Dan", "email": "dan@example.com" },
        "created_at": "2026-06-10T18:30:00Z",
        "updated_at": "2026-06-10T18:30:00Z"
      }
    ],
    "revisions": [
      {
        "id": 1,
        "daily_pay_entry_id": 1,
        "snapshot": {
          "id": 1,
          "date": "2026-06-09",
          "lines": [...],
          "revisions": [],
          "created_by": 42,
          "created_at": "...",
          "updated_at": "..."
        },
        "edited_by": 42,
        "created_at": "2026-06-10T20:00:00Z"
      }
    ],
    "created_by": 42,
    "created_at": "2026-06-10T18:30:00Z",
    "updated_at": "2026-06-10T20:00:00Z"
  }
}
```

**Revisions are ordered newest first.** Each revision's `snapshot` is a full copy of the entry as it was *before* that edit.

**Ticket issues are fully hydrated** — the `ticket_issues` array on each line contains the complete TicketIssue objects (not just IDs), including:
- Related ticket details (store, creator user)
- Related issue details (title, creator user)
- Assigned technicians with their category and creator user
- The TicketIssue creator user

All `created_by` fields are accompanied by a `creator` object containing the user's id, name, and email.

---

## Edit Flow

The edit endpoint is a **full replace** — all existing lines are deleted and new ones are created from the submitted payload. Files/notes on old lines do not carry over automatically.

Typical UI flow:
1. Load the entry via `GET /daily-pay-entries/{id}`
2. Pre-populate the form with the current `data`
3. User makes changes
4. Submit to `POST /daily-pay-entries/{id}/edit`
5. The response contains the updated entry plus a new `revisions` entry at the top of the `revisions` array

The user who performed the edit is recorded in `revisions[0].edited_by`.

---

## Filters (GET list)

All filters are optional query parameters.

```
GET /api/daily-pay-entries
  ?technician_ids[]=3&technician_ids[]=5    (multi-select; checkbox)
  &store_ids[]=7&store_ids[]=9              (multi-select; checkbox)
  &date=2026-06-10                          (exact date)
  &date_from=2026-06-01&date_to=2026-06-30  (date range)
  &filled_by=42                             (who submitted)
  &created_from=2026-06-10T00:00:00Z        (submission timestamp range)
  &created_to=2026-06-10T23:59:59Z
  &sort=date&dir=desc                       (sort: date|created_at, dir: asc|desc)
  &per_page=20
```

For multi-select checkboxes, send each selected ID as a separate `technician_ids[]` parameter. In Axios:

```js
axios.get('/api/daily-pay-entries', {
  params: {
    technician_ids: [3, 5],
    store_ids: [7, 9],
    date_from: '2026-06-01',
    date_to: '2026-06-30',
  },
  paramsSerializer: params => qs.stringify(params, { arrayFormat: 'brackets' }),
})
```

---

## Validation Errors

On 422 the response body is `{ "message": "...", "errors": { "field": ["reason"] } }`.

**Technician-issue assignment error** (technician not assigned to linked ticket issue):
```json
{
  "errors": {
    "lines.0.ticket_issue_ids": [
      "Technician is not assigned to issue(s): 12."
    ]
  }
}
```

This means the technician in `lines[0]` does not have an assignment to ticket issue ID 12. You need to assign the technician to that issue via the ticket workflow first.

Other common errors follow standard Laravel dot-notation field paths: `lines.0.technician_id`, `lines.1.store_id`, `date`, etc.

---

## Authentication

All requests require:

```
Authorization: Bearer <token>
```

The token is issued by the external auth service. The API does not expose login/register endpoints.
