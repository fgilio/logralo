# Push notifications

Logralo buzzes a phone without a native app, without Firebase, and without an account anywhere. Web Push is a browser standard: the browser hands the app an endpoint, the app signs a request to that endpoint, and the push service behind it wakes the phone. There is no provider to sign up with and no per-message cost. Resend stays where it was, sending mail.

What this is not is Laravel Cloud's WebSockets, which is a managed Reverb cluster. That delivers to a page somebody already has open. Every event worth a notification here happens while the app is closed, which is the entire point.

## The three events, and why the list is this short

```text
somebody's streak hits a round number  → the rest of the group
a month closes and the recap posts     → everybody
a live run is about to break at cutoff → that one member
```

The temptation is to notify on marks and reactions. With five friends on three goals each, marks alone are around fifteen events a day, so everyone gets a dozen buzzes about people going to the gym. That is how an app gets its notifications switched off, and a member who does that stops hearing the month recap too.

So the bar is the one `StreakMilestone` already sets for the in-app celebration: rare, round numbers, worth interrupting somebody for. `docs/mvp-v1-scope.md` decided reactions get no notification at all and that still holds. Reactions are found by looking.

The nudge is the one that had to be argued down hardest. "You have goals left today" fires every evening for anybody who does not mark all five, which is most people most days. What ships instead only fires when a run actually ends: `StreakCalculator::atRiskOn` returns zero unless a streak reaches the closing day and that day is unmarked and unpaused. Nothing at risk, no buzz.

## The iOS constraint decides the UI

Safari gives `Notification` and `PushManager` to a web app on the home screen and to nothing else. A tab on an iPhone cannot subscribe, and cannot be talked into it. This is not a fallback path, it is the normal case for half the group, so the profile toggle detects it and says "add Logralo to your home screen and come back" rather than offering a button that throws.

Chrome, Firefox and Safari on the desktop have no such rule. The states the toggle can be in are therefore: unconfigured (no VAPID keys in the environment), needs-install, unsupported, denied, off, on. It starts at `loading` and draws nothing until it has read the browser, so no branch flashes on the way there.

A subscription belongs to a browser, not to a member. The same person on a phone and a laptop is two rows, and both get the buzz.

## The keys

`artisan webpush:vapid` generates the pair, once per environment, and writes it into `.env`. They are then stored and never regenerated: the public key is baked into every subscription the group holds, so a new pair silently orphans all of them and every member has to toggle push off and on again.

Production carries them as Laravel Cloud environment variables. An environment with no keys is a valid environment. Push is simply off there, and the toggle says so.

The private key signs a JWT per push service; nothing about it ever reaches the browser. The public key does, which is what `applicationServerKey` is.

## The endpoint is a credential

A subscription endpoint is a URL that anyone holding it can push to. It is a bearer capability with no other access control, in the same family as this app's 24-character share tokens.

So it is never logged. `SubscribeToPush` records `logralo.push_service` — the host, `fcm.googleapis.com` or `updates.push.services.mozilla.com` — and stops there. A test asserts the endpoint is absent from the context the Action leaves behind.

It arrives from the client like any other Livewire argument, so it is checked rather than trusted: an https URL, inside the package's length limit, on a named host and the default port. The last two matter because the app later signs a request to whatever it stored, and `url:https` on its own is happy with `https://10.0.0.5:8080/x`.

That is deliberately less than a review asked for. The full ask was an allowlist of push-service origins, or resolving the host and refusing private, loopback and link-local addresses, with a re-check at connection time against DNS rebinding. Neither shipped. An allowlist goes stale in silence — a browser ships a new push host, the member who upgraded stops receiving anything, and nothing in the logs says why — and resolve-then-connect is a guarantee only the HTTP client can make, which here is Guzzle inside the package. What remains is a blind request to an https host on behalf of somebody who is already an invited member of a group of twelve. If Logralo ever admits people who are not, this is the first thing to revisit.

## The reminder sweep

`logralo:push-reminders` runs hourly, for the same reason `logralo:close-months` does: the window it is looking for opens at a different instant for every member, and an hourly sweep finds it without cron arithmetic.

The window is `LOGRALO_PUSH_REMINDER_LEAD_HOURS` before a member's grace cutoff, so with the defaults a member in Montevideo is a candidate from 09:00 to 12:00 on the day yesterday closes. A window rather than an exact hour is what makes a late or missed tick harmless.

Which means the sweep sees the same member as a candidate up to three times, and the schedule is not what makes the nudge once-a-day. A cache key is: `Cache::add` is atomic, the key is the member plus the closing date, and it expires with the window it belongs to. It is claimed only once there is something to say, so a member with nothing at risk at 09:00 can still be nudged at 11:00.

`notify()` is not wrapped in `rescue` here, unlike the two group announcements. Those hang off a mark and a recap row that are already written and must not fail; here the notification is the entire deliverable, and a queue refusing it is worth an exception.

## What the service worker does with it

`public/sw.js` stays online-only. It caches nothing, and the push handlers did not change that: they draw a notification and open a window, neither of which needs a cache.

The payload is the flat object `WebPushMessage` produces (`title`, `body`, `icon`, `tag`, `data`). A push whose payload never arrived or will not parse still draws the fallback, because a push that shows nothing is one Safari can drop the subscription over.

On click, an already-open tab is focused rather than a second one opened. Logralo is one screen, so there is nowhere else for the tap to land.

## Expiry takes care of itself

Push services answer a dead subscription with 404 or 410, and the package's `ReportHandler` deletes the row when they do. Nothing has to sweep for stale endpoints: a browser that cleared its site data, or a phone that was reset, cleans itself up on the next send.
