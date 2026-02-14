# freescout-mailbox-auto-distributor
Automatically assigns new incoming customer conversations in a mailbox to a selected agent pool

<img width="800" height="900" alt="Screenshot 2026-02-14 143256" src="https://github.com/user-attachments/assets/b00f7099-9346-4ef1-8156-f7091f23d7eb" />

- **Round-robin (rotate)**
- **Least open (even by workload)** — assigns to the agent with the fewest **Active + Pending** conversations in the same mailbox.

## Installation

1. Download the module ZIP.
2. In FreeScout: **Manage → Modules → Upload Module**.
3. Activate **Mailbox Auto-Distributor**.

## Configuration (per mailbox)

Go to **Mailboxes → (your mailbox) → Edit Mailbox** and scroll to **Auto-Distributor**.

- Enable Auto-Distributor
- Choose a distribution mode
- Select eligible agents

Settings are stored **per mailbox** (mailbox meta), so each mailbox can have its own pool and mode.

## Permissions

The settings section is shown only to users who can update mailbox settings:

- Admins
- Mailbox managers/users with the mailbox permission to **Update Settings**

If a user cannot update mailbox settings, they will not see or be able to change Auto-Distributor configuration.

## Behavior

- Runs on **incoming customer-created conversations**.
- Assigns only if the conversation is **unassigned**.
- Does not change tags/statuses/threads.

## Notes

- Least-open counts only **Active + Pending** conversations.
- If the eligible agent pool is empty or invalid, the module does nothing (conversation stays unassigned).

## License

AGPL-3.0-or-later. See `LICENSE`.
