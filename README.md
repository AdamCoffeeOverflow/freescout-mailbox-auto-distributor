<img width="300" height="300" alt="icon" src="https://github.com/user-attachments/assets/8672d8bc-2038-41a2-b7a6-e494c43cd452" />

# Mailbox Auto-Distributor

Automatically assigns new incoming customer conversations in a mailbox to a selected agent pool.

<img width="800" height="900" alt="Screenshot" src="https://github.com/user-attachments/assets/b00f7099-9346-4ef1-8156-f7091f23d7eb" />

## Features

Core distribution modes:
- **Round-robin (rotate)**
- **Least open (even by workload)** — assigns to the agent with the fewest **Active + Pending** conversations in the same mailbox.

Optional (per mailbox):
- **Workflows-first (deferred assignment)** for compatibility with Workflows/other automation
- **Sticky assignment** (same customer + same normalized subject)
- **Exclusion tags** (skip auto-assignment when specific tags are present)
- **Fallback assignee** (optional)
- **Lightweight audit log** (optional)

## Installation

1. Download the module ZIP.
2. In FreeScout: **Manage → Modules → Upload Module**.
3. Activate **Mailbox Auto-Distributor**.
4. Run migrations (required for deferred assignments/audit):

```bash
php artisan migrate
```

## Configuration (per mailbox)

Go to **Mailboxes → (your mailbox) → Edit Mailbox** and scroll to **Mailbox Auto-Distributor**.

- Enable Auto-Distributor
- Choose a distribution mode
- Select eligible agents

Optional:
- Workflows-first (defer assignment)
- Sticky assignment + lookback window
- Exclusion tags
- Fallback assignee
- Audit log

Settings are stored **per mailbox** (mailbox meta), so each mailbox can have its own pool and mode.

## Permissions

The settings section is shown only to users who can update mailbox settings:
- Admins
- Mailbox managers/users with the mailbox permission to **Update Settings**

## Behavior

- Runs on **incoming customer-created conversations**.
- Assigns only if the conversation is **unassigned**.
- Does not change tags/statuses/threads.

## Workflows-first (deferred assignment)

If you use the FreeScout **Workflows** module (or any automation that assigns tickets), enable **Workflows-first (defer assignment)**.

How it works:
- On a new customer ticket, Auto-Distributor enqueues a pending assignment and waits.
- When processed, it assigns **only if the conversation is still unassigned**.

### Cron (recommended)

Add this to your server cron (every minute is typical):

```bash
php artisan mailboxautodistributor:process
```

If you cannot run cron, enable **Web fallback (no-cron mode)**. This is less reliable and processes pending assignments only when new customer tickets arrive.

## License

AGPL-3.0-or-later. See `LICENSE`.
