# OAuth 2.0 Migration Plan

> Garmin retires OAuth 1.0 on **31 Dec 2026**. This is the tracking doc: update the checklists as work lands. Rewritten 2026-11-02; replaces the February plan, which moved every app install to Bearer tokens.

**Status (2026-11-02):** nothing built except the deregistration script, which is committed but not yet run. Garmin has not been contacted.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Existing users, app → server | **Unchanged**: keep the OAuth 1.0 signature | The server checks it against its own stored secret, Garmin isn't involved. No re-login, no app update |
| Existing users, server → Garmin | **Token exchange, run by the server** | Must happen for everyone we keep. Invisible to users |
| New logins, app → server | **Server-issued `server_token`** (Bearer) | OAuth 2.0 gives no secret to sign with, and access tokens change on refresh, so they can't identify a user |
| OAuth 2.0 code exchange at login | **Server does it** | The server has to hold and refresh the tokens anyway, and the client secret stays off the device |
| Dormant users | **Deregister with a 1 year cutoff, before the exchange** | Stops Garmin pushing their activities, and leaves ~5k tokens to move instead of ~27k |
| Moving existing installs to Bearer | **Not needed for the deadline** | Optional later |
| App release | **One version supporting both, released early** | Signed-in users: the stored credential decides how to sign. New logins: a server setting picks the flow, so OAuth 2.0 can be turned on, or back off, without an App Store release |
| Server release | **Handles both, deployed before Garmin's conversion** | No global switch: each case decides from the data (see below). Until the conversion nobody has OAuth 2.0 tokens, so everything keeps using OAuth 1.0 and nothing changes for users |

## How identity works

Two separate relationships use the Garmin token today, plus webhooks:

| | Today | After migration, existing user | After migration, new login |
|---|---|---|---|
| App → server | OAuth 1.0 signature with the token and secret | Same | `Authorization: Bearer <server_token>` + `token_id` |
| Server → Garmin | OAuth 1.0 signature | `Bearer <garmin_access_token>` | `Bearer <garmin_access_token>` |
| Garmin webhook → server | User found by `userAccessToken` | User found by `userId` | User found by `userId` |

**Consequence:** the OAuth 1.0 token and secret stop working at Garmin but remain the app's credentials with our server, like a username and password we own. They must never be removed while those app installs exist, and neither can the consumer key and secret in the server config.

The server code handles both from the start, and is inactive until Garmin converts the app:

| Part | Behaviour | Before the conversion |
|---|---|---|
| Calling Garmin | Bearer if the user has OAuth 2.0 tokens, otherwise OAuth 1.0 | Nobody has OAuth 2.0 tokens: all OAuth 1.0 |
| Webhooks | Find the user by `userId`, with or without a token | Old-format webhooks keep working |
| App → server auth | Accepts Bearer and OAuth 1.0 signatures | Only OAuth 1.0 arrives |
| OAuth 2.0 login endpoint | Off until `oauth2ClientSecret` is in the config | Unused |
| Scheduled refresh | Only acts on OAuth 2.0 tokens | Nothing to do |
| Exchange script | Run by hand | Not run |
| `garmin_login` setting | Not set means OAuth 1.0 | Not set |

Turning it on: add the secret to the config, test on one's own account, set `garmin_login`, run the exchange.

## Garmin's process

From Garmin's migration guide:

1. Email connect-support@developer.garmin.com with the consumer key(s). Garmin converts the app and a new OAuth 2.0 secret appears in the Developer Portal. Existing connections keep working, and new users can still use OAuth 1.0 for now.
2. Exchange tokens per user: `POST https://apis.garmin.com/partner-gateway/rest/user/token-exchange`, signed with OAuth 1.0, **200 requests/minute**. The response has the access and refresh tokens.
3. The OAuth 1.0 token stays valid for **30 days** after its exchange. Then that user's webhooks switch to the new format: **no `userAccessToken`, `userId` only**, and an extra `token` parameter on the callback URL.
4. Once we complete the migration, all new connections must use OAuth 2.0.

## Order constraints

- **Deregister before the exchange**: deregistering is signed with the OAuth 1.0 token.
- **Webhook changes live before requesting the conversion**: Garmin's guide asks partners to be ready for the new format first. A user's format actually changes 30 days after their exchange, and both formats arrive during the transition.
- **Ask Garmin early**: its answers can change the code, and how long a conversion takes is unknown. According to the guide, converting doesn't immediately affect existing users, and new users can still connect with OAuth 1.0.
- **Exchange by about 1 Dec**: this leaves the full 30 day overlap before retirement. A token not exchanged by 31 Dec probably can't be: that user has to log in again, on an app version with OAuth 2.0.
- **App released before Garmin's conversion is needed**: with the setting off it behaves as today, so it can ship early. Until the conversion its OAuth 2.0 login can only be tested against the mock, so leave time for a fix release.
- **Setting on before telling Garmin the migration is complete**, and before 31 Dec, when OAuth 1.0 login stops working. This needs the app live, Garmin's conversion and the server's login endpoint.

## Timeline

Target dates, proposed 2026-11-02.

| By | Step |
|---|---|
| Now | Email Garmin the open questions. Deregistration: deploy and run |
| 9 Nov | Webhook changes deployed, then request the conversion, `garmin_dev` key first if possible |
| 16 Nov | Rest of the server deployed, inactive: token storage and refresh, login, exchange script |
| 16 Nov | App submitted: supports both flows, setting off. Ideally after one real test login, if the conversion has come through |
| Once converted | Test OAuth 2.0 login on one's own account, then turn the setting on |
| ~1 Dec | Token exchange on all kept tokens. **If the conversion is late, this date is the one at risk**: the later it runs, the less overlap before retirement |
| Before 31 Dec | Setting on for good, tell Garmin the migration is complete |
| ~1 Jan | Old-format webhooks should have stopped; remove what's no longer needed (see Cleanup) |

## Work

### 1. Deregistration (`api/connectstats/deregister_dormant_users.php`)

Selection measured on prod 2026-11-02: **21,713 tokens to deregister** (21,680 users), 5,010 kept. About 3.6 hours at 100 calls/minute.

- [x] Script: dry run by default, skips anything recent, resumable, stops after repeated failures
- [ ] Deploy, add `url_user_registration` to the config
- [ ] Dry run, `--execute --limit=1`, `--limit=50`, then the full run
- [ ] Decide what to do with tokens that keep failing

Deregistered users keep their rows. Coming back, they get a 401, and the app asks for a login.

### 2. Webhooks (server)

The code assumes `userAccessToken` is always present. Without it:

| Where | What happens today |
|---|---|
| `process()` in `api/shared.php` | **`Missing end point field`: the activity or file is not saved** |
| Linking a row to its user (`cs_user_id`, in the update step after `process()`) | Skipped |
| FIT download (`userAccessToken` lookup before `get_url_data()`) | Fails with "unregistered user" |
| `deregister_user()` | **Silently does nothing**: the token stays live |

- [ ] `process()`: `userId` required, `userAccessToken` optional
- [ ] Add a lookup by `userId`, used wherever `user_info()` takes a webhook's token. Several rows can share a `userId`: pick the newest row with live Garmin credentials
- [ ] Index `tokens.userId`
- [ ] `deregister_user()`: handle `userId`, and clear the secret, Garmin tokens and server token on all that user's rows
- [ ] Check nothing depends on `activities.userAccessToken` being set, such as `search` with `activities_garmin_health` output
- [ ] Test the callback URL with the extra `token` parameter. The download uses the URL unchanged and signs its query parameters, so it should work with both schemes

### 3. Token storage and calling Garmin (server)

- [ ] Schema version bump in `ensure_schema()`. On `tokens`: `garmin_access_token`, `garmin_refresh_token`, `garmin_token_expires_at`, `garmin_refresh_expires_at`
- [ ] `Auth::get_url_data()` and callers: use Bearer if the user has an OAuth 2.0 token, otherwise OAuth 1.0
- [ ] Refresh the access token when expired. Take a lock (`GET_LOCK` per user) and re-read after getting it: queue workers run concurrently, and a refresh may replace the refresh token
- [ ] **Scheduled refresh job**: on-demand refresh isn't enough. Downloads are skipped for users idle over 45 days, so their tokens would never refresh and the refresh token would expire (about 90 days in Garmin's documented response; to confirm)
- [ ] Refresh failing for good: clear the row's app credentials, so the app gets a 401 and asks for a login, as with deregistration. Log it

### 4. Login for new users (server + app)

Flow:

1. The app creates the PKCE verifier and challenge, and opens Garmin's authorization page with the `client_id`, challenge, `state` and redirect URI (`https://connect.garmin.com/oauth2Confirm`).
2. Garmin redirects back with a `code`. The app checks `state`.
3. The app sends `code`, `code_verifier` and `redirect_uri` to a new endpoint, by POST so they don't end up in URLs and logs.
4. The server exchanges them using the client secret (`https://diauth.garmin.com/di-oauth2-service/oauth/token`), gets the `userId` with the new token (`url_user_id`), and creates or reuses the `users` row and adds a `tokens` row with no OAuth 1.0 token or secret.
5. The server returns `token_id`, `cs_user_id` and a new `server_token`. The app keeps these in the Keychain. It never sees Garmin's tokens.

Server:

- [ ] New endpoint `api/connectstats/user_register_oauth2.php`
- [ ] Config: `oauth2ClientId`, `oauth2ClientSecret`
- [ ] `server_token`: 32 random bytes; store only its SHA-256 (`tokens.server_token_hash`), so a database leak doesn't expose it
- [ ] `Auth::authenticate_header()`: accept `Bearer`, and check the row matches `token_id`. Every endpoint using it gets this automatically (`search`, `json`, `file`, `sync`, `validateuser`, `notifications/register`)
- [ ] Check code that assumes `userAccessToken` is set on a `tokens` row (`user_info()`, `Auth::authorization_header_for_token_id()`, `api/garmin/sign.php`)
- [ ] `/api/status/api` returns the setting from the config: `{"status":1, "garmin_login":"oauth2"}`. Not set means OAuth 1.0

App, one version supporting both:

Signed-in users need no mode: a stored `server_token` means Bearer, otherwise OAuth 1.0 as today. Whether the server has exchanged that user's Garmin token makes no difference to the app. Only a new login needs the setting, to choose the flow.

- [ ] `GCConnectStatsRequest.m`, `signIn`: use the PKCE flow if the setting says `oauth2`, otherwise `signInGarminStep`
- [ ] `isSignedIn`: currently requires a secret, must accept a `server_token` instead
- [ ] `preparedUrlRequest:params:`: Bearer if the app has a `server_token`, otherwise the existing OAuth 1.0 signing
- [ ] `signout`: also clears the `server_token`
- [ ] 401 keeps forcing a new login (already the case, in `checkNoErrors`)
- [ ] `GCConnectStatsRequestAPICheck.swift`: read `garmin_login` and save it, like `redirect`. The app calls this just before login on each sync
- [ ] Fresh install: make sure login waits for the status check. If there's no answer, use the last saved value, or OAuth 1.0 if none

Versions without this only have OAuth 1.0 login. They keep working for anyone signed in, but after 31 Dec anyone signed out, including deregistered users, has to update first.

### 5. Token exchange (server)

- [ ] New script, same shape as the deregistration script: dry run by default, limit, rate, resumable, stops after repeated failures. Logs IDs only
- [ ] Rate: at most 200/minute. About 25 minutes for ~5k tokens
- [ ] Store the response immediately, and skip rows that already have an OAuth 2.0 token on a re-run
- [ ] **One exchange per Garmin user**, on the newest live token (~4.9k users, ~5k tokens). Other rows keep their secret for app auth and get Garmin credentials through `userId`. To confirm with Garmin
- [ ] Before running: fill in missing `userId` values with `url_user_id`, and decide on tokens without a `cs_user_id`. On 2026-11-02, 7 tokens had no `userId`, and 7 live tokens had no `cs_user_id`
- [ ] Run on one's own account first, check downloads for a few days, then a small batch, then all

## Cleanup (January 2027)

- [ ] Remove OAuth 1.0 from server → Garmin calls, and the OAuth 1.0 branch in webhook handling once no webhook has carried a token for a while
- [ ] Remove `deregister_dormant_users.php` and the exchange script. Deregistering any user afterwards needs Bearer
- **Keep** OAuth 1.0 signature checking, `userAccessToken`, `userAccessTokenSecret` and the consumer key and secret: existing installs depend on them

## Testing

There's no dev instance on the new droplet: test on the local stack (`docker compose`, see [testing](testing.md)).

- **Local:** extend the Garmin mock (`docker/dev/mock/garmin`) with token exchange, token and refresh endpoints, and old and new webhook formats. Add `test_full.py` cases: old-format webhook, new-format webhook, mixed batch, Bearer auth (valid, wrong `token_id`, invalid), OAuth 1.0 auth after exchange, refresh, refresh failure, deregistration by `userId`
- **Real Garmin:** the `garmin_dev` key, if Garmin converts it first, with a test account through login, exchange and a real activity
- **Prod:** own account first (see Work 5)

Watch during the transition: 401 rate, `error_*` tables, refresh failures, activities with no `cs_user_id`, FIT download failures.

## Rollback

- **Webhooks, Bearer auth:** additive, old paths keep working; revert the deploy
- **OAuth 2.0 login:** turn the setting off, which works until the migration is declared complete or 31 Dec. Users who already logged in with OAuth 2.0 keep their `server_token`
- **Exchange, first 30 days:** the OAuth 1.0 tokens are still valid at Garmin, so calls can go back to OAuth 1.0
- **Exchange, after 30 days:** no rollback, only fixing forward
- **Deregistration:** no rollback; the user logs in again

## Open questions for Garmin

- How long does a conversion take?
- Does converting the app affect the `garmin_dev` key, and can it be converted first to test?
- Does anything change in webhooks at conversion, or only 30 days after each user's exchange? What does the new format look like?
- Does deregistration return a 204 for this endpoint, and what does it return for a token that's already dead? Is there a rate limit for ~21.7k calls?
- Can a Garmin user with several OAuth 1.0 tokens end up with several OAuth 2.0 tokens? Is exchanging one enough?
- Token lifetimes, and does a refresh replace the refresh token? (the 24 hour / 90 day values come from an example response)
- After 31 Dec, can a token that wasn't exchanged still be exchanged?
- When a user disconnects under OAuth 2.0, what does the deregistration webhook contain?
- Which host and base path does each endpoint use? We currently call `healthapi.garmin.com`, while the guide uses `apis.garmin.com`

## References

- Garmin's *OAuth 1 to OAuth 2 Migration* guide (Developer Portal); questions to connect-support@developer.garmin.com
- [authentication](authentication.md), [garmin-integration](garmin-integration.md), [droplet-migration](droplet-migration.md)
