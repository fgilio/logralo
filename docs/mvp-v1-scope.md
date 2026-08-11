# MVP v1 Scope

The functional first version of Logralo: usable daily by the founding group of friends, and nothing more. Decided in the scope interview on 2026-08-04. This file is the source of truth for v1. Where it disagrees with [mvp-decisions.md](mvp-decisions.md) or [kickoff-meeting-notes.md](kickoff-meeting-notes.md), this file wins.

Two non-negotiables drove every call below:

1. Seamless logging of today's goals (and yesterday's, during grace).
2. Group accountability. Friends see each other's proof without going looking.

## The one screen

The app opens directly to "Hoy". It is the only screen in v1. Top to bottom:

1. **Pulse strip.** Every member's avatar with a completion ring for today (for example 3/4). Tapping the strip slides up the monthly standings sheet.
2. **Grace banner.** Visible only while yesterday is still open (until 12:00 local). One tap marks yesterday, with the same flow as today.
3. **Goal cards.** Up to 5 cards with big tap targets. Card states: pending, done with photo (photo thumbnail, full flame), and ghost (dimmed, dashed border). Each card shows its streak flame with day count.
4. **The feed.** Everyone's marks as cards, newest first, grouped by day dividers, infinite scroll into the past. The feed is the only history mechanism. Goal cards always show today and never time-travel.

No tabs and no menus. Goal management, timezone, and logout live behind the profile avatar.

## Logging and the photo rule

- **Tap a pending card**: marked done instantly with optimistic UI. The mark is a ghost until it has a photo.
- **Hold a card**: opens the native camera input (`<input capture>`). The resulting mark is full. A short note can be added from the same hold action (photo, note, or both). Check Flux Pro for a file upload component to build on.
- **Forced camera.** Per goal, after 2 consecutive ghost marks, the 3rd tap does not mark. It opens the camera directly with the copy "Pics or it didn't happen 📸". Taking the photo creates a full mark and resets the counter. Backing out of the camera leaves the day unmarked.
- **Un-mark.** Tapping an already-done card removes the mark (and its photo) while the day is still open. Marks become immutable when the day closes.
- **Grace** uses the exact same flow for yesterday. Days before yesterday can never be logged.

## Time, streaks, and scoring

- **Time.** Each user has a timezone (set at seed, editable in profile). A user's day D accepts marks from D 00:00 until D+1 12:00 local, then closes forever. All cutoff logic runs on the user's timezone.
- **Streaks** are per goal: consecutive days with a mark. Ghost marks keep a streak alive (streaks reward showing up, photos are scored instead). The streak breaks when a day closes unmarked. Archived goals freeze their streak.
- **Monthly score.** Completion percentage of the user's own possible marks so far this month, with a full mark worth 1 and a ghost mark worth 0.5: `(full × 1 + ghost × 0.5) / (days elapsed × active goals)`. This keeps a 2-goal user competitive against a 5-goal user and does not inflate leaders early in the month. Calendar month per user's timezone. Ties share the podium.
- **Standings sheet** (tap the pulse strip): current month only, one bar per member split into a solid segment (full marks) and a hatched segment (ghost marks at half value), so the scoring rule explains itself visually.
- **Month end.** A scheduled job closes the month and posts a recap card into the feed: winner, runner-up, and best streak of the month, with a WhatsApp share button.

## Feed, reactions, notes, and sharing

- **Feed cards** show avatar, name, goal emoji and name, relative time, the photo full-bleed (or the ghost treatment: "marcó sin foto 🌫️ · 2ᵃ vez seguida"), the flame count, and the note if present. Photos tap to full-screen.
- **Reactions.** One-tap emoji from a small fixed set (💪 🔥 👏 😂 🫵), one reaction per user per card, tap again to remove. Counts show on the card. No notifications. Reactions are discovered by looking.
- **Sharing.** A share action on every feed card and on the month-end recap, via the Web Share API with the photo attached plus a text line (for example "🔥 12 días seguidos de Gym — Logralo"). WhatsApp is the expected target. If sharing gets heavy use, comments come in v1.1.
- **Live-ness.** The feed refreshes on focus and on pull. No websockets.

## Onboarding, goal management, and PWA

- **Onboarding.** Built on the Livewire starter kit's teams setup with one team (the group). An artisan command seeds each friend with name, email, and timezone, and produces a signed magic link to share over WhatsApp. The link logs them in and asks for a password on first visit. Standard email plus password login after that. No registration page and no invite flow.
- **Goal management** lives behind the profile avatar: create (emoji plus name, max 5 active), rename anytime, archive anytime. Archiving keeps history, photos, and past scores. The goal leaves the grid and stops counting from that day. At least 1 active goal is required to appear in standings. New members land on a "create your first goal" empty state.
- **PWA.** Manifest, icons, and full-screen display so it installs to the home screen. Online-only. No reminders or notifications in v1.

## Out of v1

- Money pool, entry fees, pay-to-recover-streak.
- AI difficulty weighting.
- Categories, virality, boost and follower features.
- Comments and threads (revisit after observing sharing usage).
- Retroactive photo upgrade for ghost marks.
- Grid day-swipe (the feed carries history).
- Push or email reminders.
- Offline logging.
- Dedicated ranking screen (graduates in with the money pool).
- Multiple groups and public registration.

## Launch checklist

v1 is done when:

1. Deployed on Laravel Cloud with a real domain and photos in object storage.
2. The real group is seeded and magic links are sent over WhatsApp.
3. Every friend can, from their phone's home screen: install the PWA, create goals, tap and hold to log with camera, see the grace banner work across a real noon cutoff, scroll everyone's photos, react, check the standings sheet, and share a card to WhatsApp.
4. The forced-camera 3rd tap, streak break, and month-end recap job are covered by tests, with the kickoff skill's quality gates passing (Pest, PHPStan level 8, 100% type coverage).
