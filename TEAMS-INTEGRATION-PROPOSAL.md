# MS Teams Integration — AsyncStandUp Proposal

**Date**: 2026-08-18 (revised v2)
**Author**: Team Lead
**Status**: Approved architecture — awaiting build instruction

---

## Decisions received (2026-08-18)

| Question | Decision |
|---|---|
| DMs vs channel-only | **DMs required** — prompts must go to private messages, not channels |
| Bot Framework approach | **Accepted** — free Azure Bot Service registration; code runs on own server |
| Answer in Teams | **Yes** — users answer directly in the Teams DM Adaptive Card |
| Fallback mode | **Yes** — email prompts + Teams channel summary (no bot, no Azure) also supported |

---

## Summary

AsyncStandUp will support Microsoft Teams across **three notification modes**. Each team picks one:

| Mode | Prompt delivery | Answer method | Summary | Azure required |
|---|---|---|---|---|
| `email` | Email (existing) | Web form | Email | None |
| `teams` | Teams DM (Adaptive Card) | Directly in Teams | Teams channel | Bot registration (free) |
| `teams-summary` | Email | Web form | Teams channel | None — Incoming Webhook only |

`teams-summary` is the zero-Azure fallback: users still get email prompts but the daily summary posts to a Teams channel. Ideal for teams that want Teams visibility without bot setup.

---

## Revised Architecture: Bot Framework (Recommended)

### Why Bot Framework, not Graph API

Direct answer submission in Teams requires a **bot** — Teams needs to know where to POST the user's card responses. A bot provides exactly this: Teams posts every interaction (card submissions, messages) to a webhook URL on your server.

The **Microsoft Bot Framework** is the correct tool. Here is what "Azure involvement" actually means:

| Component | Where it lives | What it does |
|---|---|---|
| Bot registration | Azure Bot Service (free tier, no cost) | Issues `AppId` + `AppSecret`; tells Teams where your server is |
| Bot code | **Your PHP server** | Receives messages from Teams; sends DMs; processes answers |
| Channel summary | Your PHP server → Incoming Webhook URL | No bot required for outbound channel posts |

**The bot runs entirely on your server.** Azure Bot Service is only a registration — equivalent to registering for any OAuth provider (GitHub OAuth, Google OAuth, etc.). You are not deploying code to Azure, not paying for Azure compute, not running any Azure services.

If "Azure involvement" means "no Azure compute / hosting" → Bot Framework is fully compatible.
If it means "absolutely no Azure account of any kind" → see Fallback Options below.

---

## How It Works (Bot Framework)

### Flow: Standup Prompt → DM → Answer

```
Cron fires (e.g. 09:00 team timezone)
    │
    ├─ [Teams mode] TeamsBot::sendDmPrompt(user, token)
    │       → POST https://smba.trafficmanager.net/... (Bot Framework API)
    │           → Teams DM to user (Adaptive Card with input fields)
    │
    └─ [Email mode] sendStandupPrompt() — unchanged
```

```
User fills in the Adaptive Card and clicks Submit
    │
    └─ Teams POSTs the card data to:
           https://your-server.com/bot/webhook
               → AsyncStandUp reads answers
               → Saves submission (same saveSubmission() as web form)
               → Sends confirmation DM
```

### Flow: Daily Summary → Channel

```
Cron fires (after all prompts processed)
    │
    └─ TeamsNotifier::postChannelSummary(webhookUrl, summaryData)
           → POST to Teams Incoming Webhook URL (no bot required)
               → Adaptive Card posted to channel
```

Channel summaries use **Incoming Webhooks** only — no bot registration needed for this part.

---

## Adaptive Card: Standup Prompt DM

```
┌─────────────────────────────────────────────────────────┐
│ 🤖 AsyncStandUp — Daily Standup                         │
│ Engineering Team · Monday 18 August                     │
├─────────────────────────────────────────────────────────┤
│ What did you do since yesterday?                        │
│ ┌─────────────────────────────────────────────────┐    │
│ │                                                 │    │
│ └─────────────────────────────────────────────────┘    │
│                                                         │
│ What will you do today?                                 │
│ ┌─────────────────────────────────────────────────┐    │
│ │                                                 │    │
│ └─────────────────────────────────────────────────┘    │
│                                                         │
│ Anything blocking your progress?                        │
│ ┌─────────────────────────────────────────────────┐    │
│ │                                                 │    │
│ └─────────────────────────────────────────────────┘    │
├─────────────────────────────────────────────────────────┤
│              [  Submit  ]                               │
│                                                         │
│ ⏰ Expires 10:30 Europe/Paris                          │
└─────────────────────────────────────────────────────────┘
```

Questions are dynamically loaded from the team's configured questions — the same question set used for email prompts. The `token` value is embedded in the card's hidden data, not visible to the user.

On Submit: Teams sends the filled form to `POST /bot/webhook`. AsyncStandUp saves the answers, marks the token used, sends a confirmation DM.

---

## Adaptive Card: Channel Summary

```
┌─────────────────────────────────────────────────────────┐
│ 📋 Daily Standup Summary — Engineering Team             │
│ Monday 18 August 2026 · 2/3 responded (67%)            │
├─────────────────────────────────────────────────────────┤
│ ✅ Alice  ·  09:12                                      │
│   Yesterday  Reviewed PR #42, deployed staging         │
│   Today      Start feature/us-36                       │
│ ⚠️ Blockers  Waiting for DB credentials                │
├─────────────────────────────────────────────────────────┤
│ ✅ Bob  ·  09:45                                        │
│   Yesterday  Fixed login timeout bug                   │
│   Today      Write tests for auth module               │
│   Blockers   None                                      │
├─────────────────────────────────────────────────────────┤
│ ❌ Charlie — No response                               │
├─────────────────────────────────────────────────────────┤
│ 😊 Avg mood: 4.1/5                                     │
└─────────────────────────────────────────────────────────┘
```

---

## Schema Changes

### `teams` table

```sql
ALTER TABLE teams
    ADD COLUMN notification_channel VARCHAR(10) NOT NULL DEFAULT 'email',
    -- 'email' | 'teams'

    ADD COLUMN teams_webhook_url VARCHAR(500) NULL,
    -- Incoming Webhook URL for channel summary

    ADD COLUMN teams_channel_name VARCHAR(100) NULL,
    -- Display label only (not used in API calls)

    ADD COLUMN teams_conversation_ref TEXT NULL;
    -- JSON: Bot Framework conversation reference per team (for proactive DMs)
```

### `users` table

```sql
ALTER TABLE users
    ADD COLUMN teams_aad_id VARCHAR(100) NULL,
    -- Azure AD object ID — set on first bot interaction (self-resolving)
    -- Can also be set manually via admin UI

    ADD COLUMN teams_conversation_ref TEXT NULL;
    -- JSON: saved conversation reference for sending proactive DMs
```

### Global config (`config/config.php`)

```php
'teams_bot' => [
    'app_id'           => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'app_secret'       => 'your-bot-secret',
    'bot_webhook_path' => '/bot/webhook',   // your server must be HTTPS-accessible
],
```

---

## New Source Files

| File | Purpose |
|---|---|
| `src/TeamsBot.php` | Send proactive DMs; build prompt Adaptive Cards; save conversation refs |
| `src/TeamsNotifier.php` | Post channel summaries via Incoming Webhook |
| `src/TeamsMessageBuilder.php` | Build Adaptive Card JSON for prompts + summaries |
| `public/bot/webhook.php` | Receives Teams activity POST; validates Bot Framework JWT; routes to handler |
| `src/BotActivityHandler.php` | Processes `invoke` (card submit), `message`, `conversationUpdate` activities |

### Modified files

| File | Change |
|---|---|
| `src/StandupEmailer.php` | Branch on `notification_channel`; call `TeamsBot::sendDmPrompt()` if teams |
| `src/SummaryEmailer.php` | Branch on `notification_channel`; call `TeamsNotifier::postChannelSummary()` if teams |
| `public/teams/edit.php` | Teams webhook URL + channel name fields; notification channel selector |
| `config/config.example.php` | Document new `teams_bot` block |

---

## Deployment Compatibility & Graceful Degradation

The application selects the best mode that the server's network allows. Teams configuration is entirely optional — any combination of omitted fields gracefully falls back.

| Deployment | Bot credentials | Public HTTPS URL | Best available mode |
|---|---|---|---|
| Public server + bot setup | ✅ | ✅ | `teams` — DM prompt + card answers + channel summary |
| Public server, no bot | ✅/❌ | ✅ | `teams-summary` — email prompts + channel summary |
| Behind firewall (outbound only) | N/A | ❌ | `teams-summary` — email prompts + Teams channel summary (outbound POST only) |
| No outbound internet / restricted | N/A | N/A | `email` — fully standalone, no external dependencies |

**Key point**: Posting to a Teams Incoming Webhook is **outbound-only** — your server POSTs to Microsoft, Microsoft does not call back. This works from behind any firewall as long as outbound HTTPS is allowed. Only card submission answers (the bot's `/bot/webhook` endpoint) require an inbound connection.

### Fallback logic (automatic)

If a team is configured for `teams` mode but the bot webhook is unreachable (timeout, no public URL), the cron falls back to email for that user's prompt and logs a warning. The admin dashboard will show the fallback reason.

```
teams mode attempt
    → bot DM fails? → fall back to email prompt + log warning
    → channel webhook fails? → log warning, continue

teams-summary mode
    → channel webhook fails? → log warning, continue
    → email prompt fails? → log error (same as existing email failure handling)
```

This means the application **always works** regardless of infrastructure — Teams is an enhancement layer, never a dependency.

---

## Fallback Options (if even Bot Service registration is not acceptable)

| Approach | DMs | Direct answers | Azure involvement |
|---|---|---|---|
| **Bot Framework (recommended)** | ✅ Teams DM | ✅ In-card answers | Bot registration only (free) |
| **Email prompts + Teams summaries** | Email | Web form | None — Incoming Webhook only |
| **Teams prompt link + web form** | ✅ Teams DM via webhook | ❌ Link to web form | Bot registration for DMs |
| **No Teams at all** | Email | Web form | None |

If Azure Bot Service registration is truly not possible, the realistic compromise is:
- **Channel summaries**: Incoming Webhook (no Azure)
- **Prompts**: Email (unchanged)

This delivers Teams visibility of standup results without any Azure involvement, at the cost of prompts staying in email.

---

## Security

| Concern | Mitigation |
|---|---|
| Bot webhook spoofing | Validate Bot Framework JWT on every POST to `/bot/webhook` using Microsoft's public keys |
| Token replay (card submission) | Token marked `used_at` on first valid submission; second submit rejected with 409 |
| Conversation ref storage | Stored as JSON blob; encrypted at rest if SQLite encryption enabled |
| Bot secret exposure | Stored in `config/config.php`; never committed to git (existing `.gitignore` pattern) |

---

## Implementation Estimate

| Story | Scope | Est |
|---|---|---|
| **US-36** | Schema + per-team Teams config UI | ~4h |
| **US-37** | Channel summary via Incoming Webhook + Adaptive Card builder | ~6h |
| **US-38** | Bot registration setup + proactive DM prompts (`TeamsBot.php`) | ~10h |
| **US-39** | `/bot/webhook` endpoint + card submission handler + confirmation DM | ~10h |
| **US-40** | Fallback (email on Teams failure) + logging + admin visibility | ~4h |

**Total**: ~34 agent-hours | Human equivalent ~7–8 days

**Recommended order**: US-36 → US-37 → US-38 → US-39 → US-40

US-36 + US-37 can be done without any bot registration. US-38 onwards needs bot credentials.

---

## Next Step

To proceed:
1. Confirm **Bot Framework approach** is acceptable (free Azure Bot Service registration)
2. If yes: create a bot in [Azure Portal → Bot Services → Create → Free tier] — takes 5 minutes; share `AppId` and `AppSecret`
3. I'll plan all 5 stories (US-36–US-40)

If Bot Framework is not acceptable: confirm **email prompts + Teams summaries only** and I'll plan US-36 + US-37.
