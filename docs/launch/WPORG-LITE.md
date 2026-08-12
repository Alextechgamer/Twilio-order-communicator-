# wordpress.org Lite (optional)

Not built in this pass. Spec so a later packaging PR stays small.

**Ship as a separate plugin folder** (new slug, e.g. `orderring-lite/`) — do not strip the Pro tree in place.

| In Lite | Out of Lite (Pro) |
|---------|-------------------|
| BYO Twilio SMS | Voice calls |
| One mapped status (Ready for Pickup) | Shipped auto-notify, WhatsApp |
| Checkout consent + STOP/HELP/START | Two-way chat inbox, bulk reminders, scheduled reminders |
| | CSV export, delivery alerts, role matrix, license/updater |

Keep: fail-open messaging, HPOS, nonces, caps, Twilio attribution, A2P note, “you pay Twilio directly, zero markup.”

Do not load Pro files behind a license check in the same zip — wordpress.org review rejects that pattern. Two products, two zips.
