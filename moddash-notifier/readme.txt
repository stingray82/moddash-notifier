=== ModDash Notifier ===
Contributors: reallyusefulplugins
Donate link: https://reallyusefulplugins.com/donate
Tags: Comment Notification,SureDash,Discord,Slack
Requires at least: 6.5
Tested up to: 6.8.2
Stable tag: 0.9.4
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ModDash Notifier - SureDash Comment Notification Plugin - Get Notifications by Slack, Email and Discord.


== Description ==
ModDash Notifier - SureDash Comment Notification Plugin - Get Notifications by Slack, Email and Discord.


== Installation ==

1. Upload the `moddash-notifier` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Its now ready to use

== Frequently Asked Questions ==

= How do I add use the plugin? =
You need to call the required functions in Flowmattic or your Automator of choice as needed

= Why does ModDash Notifier only work with the community-post post type? =
ModDash Notifier was built for SureDash-powered communities, which use the community-post custom post type for discussions. This focus ensures that notifications are only sent for the relevant comments moderators care about, rather than every comment on your WordPress site.

= Can I use ModDash Notifier with regular WordPress comments or blog posts =
No. The plugin is designed to trigger only for comments on community-post items. It won’t activate for standard WordPress posts or other content types to avoid unnecessary notifications outside your SureDash workflow.

= How do I connect ModDash Notifier to Slack or Discord for comment alerts? =
Go to Settings → Discussion in your WordPress dashboard. Under the Slack and Discord sections, check Enable and paste your Incoming Webhook URL. Once saved, new pending comments on community-post items will appear in those channels with action buttons for quick moderation.

= Do moderators need special permissions to receive and act on notifications =
Yes. Only WordPress users with the moderate_comments capability can be selected as notification recipients. This ensures only authorized moderators can approve, unapprove, mark as spam, or trash comments from email, Slack, or Discord links.

= How fast will notifications be delivered after a comment is posted? =
Notifications are queued and sent by WP-Cron approximately 15 seconds after a comment is submitted. This keeps comment submissions fast for visitors while ensuring moderators get near real-time alerts.

= What happens if a moderator clicks an action link but isn’t logged in? = 
If you’re not logged in when clicking a moderation link, the plugin will redirect you to the login page and then return you to the requested action. It also regenerates a fresh, secure nonce so the action can complete successfully after login.
== Changelog ==
= 0.9.4 15 August 2025 =
New: Automatic Update Test

= 0.9.3 15 August 2025 =
New: Automatic Update Test

= 0.9.2 15 August 2025 =
New: Initial launch
New: Slack Notification
New: Discord Notification
New: Prep for Automatic Update Test
