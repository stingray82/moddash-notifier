# Community Post — Moderation Notifier (Email + Slack + Discord)

A tiny WordPress plugin that makes moderating comments on your **`SureDash Community Posts`** fast and phone-friendly.

- **Real-time alerts** via **email** (mobile-first HTML), **Slack**, and **Discord**
- **One-tap moderation** links: Approve / Unapprove / Spam / Trash / View
- If you’re not logged in, the link **redirects to login and comes back** to finish the action
- **Queued sending** (via WP-Cron ~15s later) so comment submission stays snappy
- Choose **which moderators** get emails in **Settings → Discussion**

> Works great with SureDash;

------

## Features

- ✅ **Moderator picker** (multi-select) — only users with `moderate_comments` are listed
- 📬 **Per-recipient email** (HTML, big tap-targets)
- 💬 **Slack** message with Block Kit buttons (no app needed – plain webhooks)
- 🛡️ **Discord** message with link buttons; also includes raw links as a fallback
- 🔐 **Secure**: capability checks + nonces; if the email nonce is stale, the plugin **re-mints** one after login and retries once
- 🚀 **Async**: notifications are queued and sent by WP-Cron (defaults to ~15s)
- 🧰 **Small surface area**: a single plugin with about 500 Lines of code.

------

## Requirements

- WordPress **5.9+** (tested on 6.x)
- PHP **7.4+** (works on 8.x)
- Your site must be able to send mail (SMTP recommended) and make outbound HTTP requests (for Slack/Discord webhooks)

------

## Installation

1. Install as you would any other plugin

------

## Setup

1. **Settings → Discussion**

   ![Community Post Moderators Settings](https://github.com/stingray82/repo-images/raw/main/moddash-notifier/moddash-settings.png)

   

   - **Moderation recipients**: select one or more users (must have `moderate_comments`). 

     **Email Example**

     ![Email Example](https://github.com/stingray82/repo-images/raw/main/moddash-notifier/moddash-email-notification.png)

   - **Slack notifications** *(optional)*: check **Enable Slack** and paste your **Incoming Webhook URL**.

   ![Slack Notificiation](https://github.com/stingray82/repo-images/raw/main/moddash-notifier/moddash-slack-notification.png)

   - **Discord notifications** *(optional)*: check **Enable Discord** and paste your **Webhook URL**.

     ![Discord Notification](https://github.com/stingray82/repo-images/raw/main/moddash-notifier/moddash-discord-notification.png)

2. That’s it. When a new **pending** comment is added to a `community-post`:
   - Email is queued and sent to each selected moderator (one message per person).
   - Slack/Discord posts appear with Approve/Unapprove/Spam/Trash/View buttons.
   - Tapping a button logs you in (if needed) and completes the action.

------

## How it works (under the hood)

- Hooks `comment_post` → if the comment is **pending** (`0`/`hold`) and belongs to **`community-post`**, a small payload is pushed to an option queue.
- A single-run WP-Cron event (`cpn_send_queued_notifications`) is scheduled for ~**15 seconds** later.
- The cron job:
  - Re-checks that the comment is still pending.
  - Builds **front-end moderation links** with nonces (Approve/Unapprove/Spam/Trash/View).
  - Sends **one email per moderator** (HTML + text alt), and posts to Slack/Discord if enabled.
- **One-tap moderation** routes through `/?cpn_moderate=1&action=approve|…&c={id}&_wpnonce=…`
  - If not logged in, you’re sent to the login page with the original URL as `redirect_to`.
  - After login, the handler **regenerates a fresh, user-bound nonce** and finishes the action, then redirects you to the comment on the site.

------

## Settings & stored options

- `community_post_moderators` — array of user IDs
- `cpn_slack_enabled` (bool), `cpn_slack_webhook` (string URL)
- `cpn_discord_enabled` (bool), `cpn_discord_webhook` (string URL)
- `cpn_notification_queue` — transient queue of pending notifications (cleared as they’re sent)

------

## Security

- Only users with **`moderate_comments`** can perform actions.
- All action links are **nonce-protected**. After login, a new nonce is minted and the request is retried once.
- Front-end handler refuses actions for posts that aren’t **`community-post`**.

------

## Troubleshooting

**No emails arrive**

- Install/configure an SMTP plugin (many hosts disable `mail()`).
- Check your logs for `CPN wp_mail_failed:` entries.
- Confirm recipients are selected and have valid email addresses.

**Slack/Discord not posting**

- Make sure the server can make outbound HTTP requests.
- Verify the webhook URLs are correct.
- Check PHP error logs for `CPN webhook` messages.
- Some Discord channels don’t render **button components**; this plugin also includes **raw links** in the message content so it’s always actionable.

**Comment form feels slow**

- This build **queues** notifications; comment submission should be fast.
   If you previously had an inline‐send version, remove it.
   You can change the delay by editing:

  ```
  wp_schedule_single_event( time() + 15, 'cpn_send_queued_notifications' );
  ```

**Actions say “Security check failed.” after login**

- That’s typically a stale nonce. This plugin regenerates one automatically on first retry. If you still see it, clear caches and try again.

------

## Extending

Lightweight helpers you can reuse:

- `cpn_action_links( $comment_id )` → returns `['front' => [...], 'admin' => [...]]`
- `cpn_build_html_email( $post, $comment, $links )` → HTML email body
- `cpn_build_slack_payload( $post, $comment, $links )` → Slack Block Kit payload
- `cpn_build_discord_payload( $post, $comment, $links )` → Discord payload (buttons + raw links)
- `cpn_send_email_to_each( $emails, $subject, $html, $alt )` → per-recipient sender

Want filters/actions? Easy to add if you need pluggable templates or custom routing—open an issue or tweak the functions above.

------

## FAQ

**Why does ModDash Notifier only work with the `community-post` post type?**  
ModDash Notifier was built for SureDash-powered communities, which use the `community-post` custom post type for discussions. This focus ensures that notifications are only sent for the relevant comments moderators care about, rather than every comment on your WordPress site.

**Can I use ModDash Notifier with regular WordPress comments or blog posts?**  
No. The plugin is designed to trigger only for comments on `community-post` items. It won’t activate for standard WordPress posts or other content types to avoid unnecessary notifications outside your SureDash workflow.

**How do I connect ModDash Notifier to Slack or Discord for comment alerts?**  
Go to **Settings → Discussion** in your WordPress dashboard. Under the Slack and Discord sections, check **Enable** and paste your Incoming Webhook URL. Once saved, new pending comments on `community-post` items will appear in those channels with action buttons for quick moderation.

**Do moderators need special permissions to receive and act on notifications?**  
Yes. Only WordPress users with the `moderate_comments` capability can be selected as notification recipients. This ensures only authorized moderators can approve, unapprove, mark as spam, or trash comments from email, Slack, or Discord links.

**How fast will notifications be delivered after a comment is posted?**  
Notifications are queued and sent by WP-Cron approximately 15 seconds after a comment is submitted. This keeps comment submissions fast for visitors while ensuring moderators get near real-time alerts.

**What happens if a moderator clicks an action link but isn’t logged in?**  
If you’re not logged in when clicking a moderation link, the plugin will redirect you to the login page and then return you to the requested action. It also regenerates a fresh, secure nonce so the action can complete successfully after login.

------

## Changelog

**1.1**

- Added Slack & Discord notifications with action buttons
- Login redirect + nonce regeneration for one-tap links
- Queue + WP-Cron sending (15s) to keep comment submissions fast
- Per-recipient email to avoid hosts that only deliver to the first “To”

**1.0**

- Initial HTML email + front-end moderation links

------

## License

GPL-2.0-or-later

------

## Credits

Built for teams using SureDash who want **zero-friction comment moderation** from email or chat.