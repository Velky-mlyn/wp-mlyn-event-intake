# Mlýn Event Intake

A restricted, profile-based monthly event-intake workflow for The Events Calendar plugin.

## Workflow

1. An administrator creates a WordPress user with the **Event Organizer** role.
2. The administrator creates and publishes one **Organizer Profile**, maps the user, selects allowed values, and configures defaults.
3. The organizer edits active events in the current-year/next-year monthly table and saves them.
4. Configured administrators can receive an email notification after a changed save.
5. An administrator imports every changed active row for that profile. New TEC events are drafts; later imports update the exact linked event while preserving its current publication status.
6. Removing an imported row causes the linked TEC event to be moved to Trash on the next import.

Organizer-editable event specifics are authoritative on every import. Currency, event status, hidden/sticky/featured settings, and selector whitelists remain controlled by the organizer profile.

An empty selector whitelist allows all existing values; selecting one or more values restricts the organizer to that selection. On narrow screens the 24 month tabs become a month selector. Server-side validation errors preserve all submitted text and field values for ten minutes, although browsers require local image files to be selected again.

Fee values distinguish three states: empty stores no fee and hides the fee row in the Velký mlýn event template, `0` means a free event, and a positive number is a paid amount.

The **Název**, **Začátek**, **Konec**, and **Vstupné** headers sort the
currently displayed rows in the browser. An arrow identifies the active sort
column and direction. The initial order is **Začátek** ascending, matching the
database query; sorting is not persisted after leaving the page.

## Changelog

### 0.2.5

- Added a pointer cursor and hover tooltip to the event-row remove button.

### 0.2.4

- Fixed sorting for WordPress locales such as `cs_CZ` by passing JavaScript the valid `cs-CZ` language tag.
- Added visible direction indicators and accessible state for sortable table headers.
- Made the first click on the initially ascending **Začátek** header sort descending.

## Data retention

Organizer profiles and intake rows are deliberately retained on uninstall. Intake rows use stable UUIDs and store the linked TEC event ID so synchronization never relies on matching titles or dates.
