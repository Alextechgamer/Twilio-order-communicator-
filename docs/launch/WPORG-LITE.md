# wordpress.org Lite

**Built** as `orderring-lite/` (v1.0.0). Submit that folder — not the Pro tree.

The **sold** plugins (30-day trial + license/updater) must not be submitted to WordPress.org — see [WORDPRESS-ORG.md](./WORDPRESS-ORG.md).

**Ship as a separate plugin folder** (`orderring-lite/`) — do not strip the Pro tree in place.

| In Lite | Out of Lite (Pro) |
|---------|-------------------|
| BYO Twilio SMS | Voice calls |
| One mapped status (Ready for Pickup) | Shipped auto-notify, WhatsApp |
| Checkout consent + STOP/HELP/START | Two-way chat inbox, bulk reminders, scheduled reminders |
| | CSV export, delivery alerts, role matrix, license/updater |

Keep: fail-open messaging, HPOS, nonces, caps, Twilio attribution, A2P note, “you pay Twilio directly, zero markup.”

If Pro is active, Lite idles (admin notice). Pro imports Lite Twilio settings when Pro has none yet.

Do not load Pro files behind a license check in the same zip — wordpress.org review rejects that pattern. Two products, two zips.