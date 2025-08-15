<?php
/**
 * Plugin Name:       ModDash Notifier
 * Description:       SureDash Comment Notification Plugin - Get Notifications by Slack, Email and Discord.
 * Tested up to:      6.8.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Version:           0.9.3
 * Author:            reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       moddash-notifier
 * Website:           https://reallyusefulplugins.com
 */

// Define plugin constants
define('RUP_MODDASH_NOTIFIER_VERSION', '0.9.3');
define('RUP_MODDASH_NOTIFIER_SLUG', 'moddash-notifier'); // Replace with your unique slug if needed
define('RUP_MODDASH_NOTIFIER_MAIN_FILE', __FILE__);
define('RUP_MODDASH_NOTIFIER_DIR', plugin_dir_path(__FILE__));
define('RUP_MODDASH_NOTIFIER_URL', plugin_dir_url(__FILE__));



if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * SETTINGS (Settings ▸ Discussion)
 * ================================================================ */
add_action( 'admin_init', function () {

	add_settings_section(
		'community_post_notify_section',
		'Community Post Moderators',
		function () {
			echo '<p>Select users to receive notifications for <code>community-post</code> comments awaiting moderation, and optionally enable Slack/Discord webhooks.</p>';
		},
		'discussion'
	);

	// Moderators (user IDs)
	register_setting( 'discussion', 'community_post_moderators', [
		'type'              => 'array',
		'sanitize_callback' => function( $ids ){
			$ids = array_map( 'intval', (array) $ids );
			return array_values( array_unique( array_filter( $ids ) ) );
		},
		'default'           => [],
	] );

	add_settings_field(
		'community_post_moderators',
		'Moderation recipients',
		function () {
			$selected = (array) get_option( 'community_post_moderators', [] );
			$users = get_users( [
				'capability' => 'moderate_comments',
				'orderby'    => 'display_name',
				'order'      => 'ASC'
			] );
			echo '<select multiple size="8" style="min-width:360px" name="community_post_moderators[]">';
			foreach ( $users as $u ) {
				$label = sprintf( '%s (%s) — %s', $u->display_name ?: $u->user_login, $u->user_login, $u->user_email );
				printf(
					'<option value="%d" %s>%s</option>',
					$u->ID,
					in_array( $u->ID, $selected, true ) ? 'selected' : '',
					esc_html( $label )
				);
			}
			echo '</select>';
			echo '<p class="description">Only users with the <code>moderate_comments</code> capability are listed.</p>';
		},
		'discussion',
		'community_post_notify_section'
	);

	// Slack
	register_setting( 'discussion', 'cpn_slack_enabled', [
		'type'              => 'boolean',
		'sanitize_callback' => fn($v) => (bool) $v,
		'default'           => false,
	] );
	register_setting( 'discussion', 'cpn_slack_webhook', [
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	] );

	add_settings_field(
		'cpn_slack',
		'Slack notifications',
		function () {
			$enabled = (bool) get_option( 'cpn_slack_enabled', false );
			$url     = (string) get_option( 'cpn_slack_webhook', '' );
			echo '<label><input type="checkbox" name="cpn_slack_enabled" value="1" '.checked( $enabled, true, false ).'> Enable Slack</label><br />';
			printf(
				'<input type="url" name="cpn_slack_webhook" value="%s" placeholder="https://hooks.slack.com/services/XXX/YYY/ZZZ" style="width:100%%;max-width:560px;">',
				esc_attr( $url )
			);
			echo '<p class="description">Incoming Webhook URL for the Slack channel. Buttons are simple links (no app required).</p>';
		},
		'discussion',
		'community_post_notify_section'
	);

	// Discord
	register_setting( 'discussion', 'cpn_discord_enabled', [
		'type'              => 'boolean',
		'sanitize_callback' => fn($v) => (bool) $v,
		'default'           => false,
	] );
	register_setting( 'discussion', 'cpn_discord_webhook', [
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	] );

	add_settings_field(
		'cpn_discord',
		'Discord notifications',
		function () {
			$enabled = (bool) get_option( 'cpn_discord_enabled', false );
			$url     = (string) get_option( 'cpn_discord_webhook', '' );
			echo '<label><input type="checkbox" name="cpn_discord_enabled" value="1" '.checked( $enabled, true, false ).'> Enable Discord</label><br />';
			printf(
				'<input type="url" name="cpn_discord_webhook" value="%s" placeholder="https://discord.com/api/webhooks/..." style="width:100%%;max-width:560px;">',
				esc_attr( $url )
			);
			echo '<p class="description">Discord channel Webhook URL. Includes link buttons.</p>';
		},
		'discussion',
		'community_post_notify_section'
	);
} );

/* ================================================================
 * ONE-TAP FRONT-END MODERATION (login redirect + nonce regen)
 * ================================================================ */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['cpn_moderate'] ) ) return;

	$action     = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
	$comment_id = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;
	$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	$regen      = isset( $_GET['cpn_regen'] );

	if ( ! $comment_id || ! in_array( $action, [ 'approve', 'unapprove', 'spam', 'trash' ], true ) ) {
		wp_die( esc_html__( 'Invalid request.', 'cpn' ) );
	}

	$comment = get_comment( $comment_id );
	$post    = $comment ? get_post( $comment->comment_post_ID ) : null;
	$back    = $comment ? get_comment_link( $comment ) : home_url( '/' );

	// Not logged in → login and return to same URL (strip stale nonce)
	if ( ! is_user_logged_in() ) {
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$current_url = remove_query_arg( '_wpnonce', $current_url );
		wp_safe_redirect( wp_login_url( $current_url ) );
		exit;
	}

	if ( ! current_user_can( 'moderate_comments' ) ) wp_die( esc_html__( 'You do not have permission to moderate comments.', 'cpn' ) );

	$action_key = "cpn-front-{$action}-{$comment_id}";
	if ( ! $nonce || ! wp_verify_nonce( $nonce, $action_key ) ) {
		if ( ! $regen ) {
			$url = add_query_arg(
				[
					'cpn_moderate' => 1,
					'action'       => $action,
					'c'            => $comment_id,
					'_wpnonce'     => wp_create_nonce( $action_key ),
					'cpn_regen'    => 1,
				],
				home_url( '/' )
			);
			wp_safe_redirect( $url );
			exit;
		}
		wp_die( esc_html__( 'Security check failed.', 'cpn' ) );
	}

	if ( ! $post || $post->post_type !== 'community-post' ) wp_die( esc_html__( 'Invalid post type.', 'cpn' ) );

	switch ( $action ) {
		case 'approve':   wp_set_comment_status( $comment_id, 'approve' ); break;
		case 'unapprove': wp_set_comment_status( $comment_id, 'hold' );    break;
		case 'spam':      wp_spam_comment( $comment_id );                  break;
		case 'trash':     wp_trash_comment( $comment_id );                 break;
	}
	wp_safe_redirect( $back );
	exit;
} );

/* ================================================================
 * ACTION LINKS (front-end + admin)
 * ================================================================ */
function cpn_action_links( $comment_id ) {
	$actions  = [ 'approve', 'unapprove', 'spam', 'trash' ];
	$base     = home_url( '/' );
	$front    = [];
	foreach ( $actions as $a ) {
		$front[$a] = add_query_arg(
			[
				'cpn_moderate' => 1,
				'action'       => $a,
				'c'            => $comment_id,
				'_wpnonce'     => wp_create_nonce( "cpn-front-{$a}-{$comment_id}" ),
			],
			$base
		);
	}
	$admin = [
		'approve'   => wp_nonce_url( admin_url( "comment.php?action=approve&c={$comment_id}" ),     "approve-comment_{$comment_id}" ),
		'unapprove' => wp_nonce_url( admin_url( "comment.php?action=unapprove&c={$comment_id}" ),   "unapprove-comment_{$comment_id}" ),
		'spam'      => wp_nonce_url( admin_url( "comment.php?action=spamcomment&c={$comment_id}" ), "spam-comment_{$comment_id}" ),
		'trash'     => wp_nonce_url( admin_url( "comment.php?action=trashcomment&c={$comment_id}" ),"trash-comment_{$comment_id}" ),
		'edit'      => wp_nonce_url( admin_url( "comment.php?action=editcomment&c={$comment_id}" ), "edit-comment_{$comment_id}" ),
	];
	return [ 'front' => $front, 'admin' => $admin ];
}

/* ================================================================
 * EMAIL (mobile-first HTML) + per-recipient sender
 * ================================================================ */
add_action( 'wp_mail_failed', function( $wp_error ){
	error_log( 'CPN wp_mail_failed: ' . print_r( $wp_error->get_error_message(), true ) );
} );

function cpn_build_html_email( WP_Post $post, WP_Comment $comment, array $links ) : string {
	$post_title   = wp_strip_all_tags( get_the_title( $post ) );
	$post_url     = get_permalink( $post );
	$comment_url  = get_comment_link( $comment );
	$author_name  = $comment->comment_author ?: 'Anonymous';
	$author_email = $comment->comment_author_email ?: 'n/a';
	$submitted    = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $comment->comment_date_gmt ) ), 'Y-m-d H:i' );
	$comment_txt  = wp_strip_all_tags( $comment->comment_content );

	$btnBase = 'display:block;text-align:center;text-decoration:none;font-weight:600;padding:12px 16px;border-radius:8px;margin:6px 0;';
	$btnPri  = $btnBase . 'background:#2271b1;color:#fff;';
	$btnSec  = $btnBase . 'background:#f6f7f7;color:#1d2327;border:1px solid #c3c4c7;';
	$btnWarn = $btnBase . 'background:#d63638;color:#fff;';
	$btnAlt  = $btnBase . 'background:#f0f6fc;color:#1d2327;border:1px solid #c3c4c7;';

	ob_start(); ?>
	<!doctype html><html><head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	</head><body style="margin:0;padding:0;background:#f5f5f5;">
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
	<tr><td align="center" style="padding:16px;">
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
	<tr><td style="padding:16px 20px;background:#f8faff;border-bottom:1px solid #e5e7eb;">
	<div style="font-size:16px;font-weight:700;color:#111827;line-height:1.3;">Comment awaiting moderation</div>
	<div style="font-size:13px;color:#6b7280;margin-top:4px;"><?php echo esc_html( $post_title ); ?></div>
	</td></tr>
	<tr><td style="padding:16px 20px;">
	<div style="font-size:14px;color:#111827;margin:0 0 8px 0;"><strong>Post:</strong> <a href="<?php echo esc_url( $post_url ); ?>" style="color:#2271b1;"><?php echo esc_html( $post_url ); ?></a></div>
	<div style="font-size:14px;color:#111827;margin:0 0 8px 0;"><strong>Commenter:</strong> <?php echo esc_html( $author_name ); ?> &lt;<?php echo esc_html( $author_email ); ?>&gt;</div>
	<div style="font-size:12px;color:#6b7280;margin:0 0 12px 0;"><strong>Submitted:</strong> <?php echo esc_html( $submitted ); ?></div>
	<div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:6px 0 14px 0;background:#fbfbfb;white-space:pre-wrap;font-size:14px;color:#111827;line-height:1.4;"><?php echo nl2br( esc_html( $comment_txt ) ); ?></div>
	<div>
		<a href="<?php echo esc_url( $links['front']['approve'] ); ?>" style="<?php echo esc_attr( $btnPri ); ?>">Approve</a>
		<a href="<?php echo esc_url( $links['front']['unapprove'] ); ?>" style="<?php echo esc_attr( $btnSec ); ?>">Unapprove</a>
		<a href="<?php echo esc_url( $links['front']['spam'] ); ?>" style="<?php echo esc_attr( $btnWarn ); ?>">Mark as Spam</a>
		<a href="<?php echo esc_url( $links['front']['trash'] ); ?>" style="<?php echo esc_attr( $btnWarn ); ?>">Trash</a>
	</div>
	<div style="margin-top:10px;">
		<a href="<?php echo esc_url( $comment_url ); ?>" style="<?php echo esc_attr( $btnAlt ); ?>">View on site</a>
		<a href="<?php echo esc_url( $links['admin']['edit'] ); ?>" style="<?php echo esc_attr( $btnAlt ); ?>">Open in Admin</a>
	</div>
	<div style="font-size:11px;color:#6b7280;margin-top:12px;">If you’re not logged in, you’ll be asked to sign in first, then we’ll bring you back here to complete the action.</div>
	</td></tr></table></td></tr></table></body></html>
	<?php
	return (string) ob_get_clean();
}

function cpn_send_email_to_each( array $emails, string $subject, string $html, string $alt = '' ) : int {
	$ok_count = 0;
	$headers  = [ 'Content-Type: text/html; charset=UTF-8' ];
	foreach ( array_values( array_unique( array_filter( $emails ) ) ) as $to ) {
		add_action( 'phpmailer_init', function( $phpmailer ) use ( $alt ) {
			if ( ! empty( $alt ) ) $phpmailer->AltBody = $alt;
		} );
		if ( wp_mail( $to, $subject, $html, $headers ) ) $ok_count++; else error_log( 'CPN: wp_mail failed for ' . $to );
	}
	return $ok_count;
}

/* ================================================================
 * SLACK + DISCORD HELPERS
 * ================================================================ */
function cpn_truncate( $text, $len = 400 ) {
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );
	return ( strlen( $text ) > $len ) ? mb_substr( $text, 0, $len - 1 ) . '…' : $text;
}

function cpn_build_slack_payload( WP_Post $post, WP_Comment $comment, array $links ) : array {
	$title   = wp_strip_all_tags( get_the_title( $post ) );
	$summary = "*Comment awaiting moderation* on <" . get_permalink( $post ) . "|" . $title . ">";
	$meta    = "*Commenter:* " . ($comment->comment_author ?: 'Anonymous') . ' <' . ($comment->comment_author_email ?: 'n/a') . ">\n"
	         . "*Submitted:* " . get_date_from_gmt( $comment->comment_date_gmt, 'Y-m-d H:i' );
	$body    = cpn_truncate( wp_strip_all_tags( $comment->comment_content ), 600 );

	return [
		'text'   => 'Moderation needed', // fallback for very old clients
		'blocks' => [
			[ 'type'=>'section', 'text'=>['type'=>'mrkdwn','text'=>$summary] ],
			[ 'type'=>'context', 'elements'=>[['type'=>'mrkdwn','text'=>$meta]] ],
			[ 'type'=>'section', 'text'=>['type'=>'mrkdwn','text'=>"```{$body}```"] ],
			[
				'type' => 'actions',
				'elements' => [
					[ 'type'=>'button','text'=>['type'=>'plain_text','text'=>'Approve','emoji'=>true],'style'=>'primary','url'=>$links['front']['approve'] ],
					[ 'type'=>'button','text'=>['type'=>'plain_text','text'=>'Unapprove','emoji'=>true],'url'=>$links['front']['unapprove'] ],
					[ 'type'=>'button','text'=>['type'=>'plain_text','text'=>'Spam','emoji'=>true],'style'=>'danger','url'=>$links['front']['spam'] ],
					[ 'type'=>'button','text'=>['type'=>'plain_text','text'=>'Trash','emoji'=>true],'style'=>'danger','url'=>$links['front']['trash'] ],
					[ 'type'=>'button','text'=>['type'=>'plain_text','text'=>'View','emoji'=>true],'url'=>get_comment_link( $comment ) ],
				],
			],
		],
	];
}

function cpn_build_discord_payload( WP_Post $post, WP_Comment $comment, array $links ) : array {
	$title = wp_strip_all_tags( get_the_title( $post ) );
	$body  = cpn_truncate( wp_strip_all_tags( $comment->comment_content ), 600 );

	$approve   = $links['front']['approve'];
	$unapprove = $links['front']['unapprove'];
	$spam      = $links['front']['spam'];
	$trash     = $links['front']['trash'];
	$view      = get_comment_link( $comment );

	// Put raw links in content so it's always actionable,
	// even if the server/channel strips components (buttons).
	$content = "**Comment awaiting moderation**\n"
		. "Approve: {$approve}\n"
		. "Unapprove: {$unapprove}\n"
		. "Spam: {$spam}\n"
		. "Trash: {$trash}\n"
		. "View: {$view}";

	return [
		'content' => $content, // guarantees clickable links
		'embeds'  => [[
			'title'       => $title,
			'url'         => get_permalink( $post ),
			'description' => $body,
			'color'       => 15105570,
			'fields'      => [
				[ 'name'=>'Commenter', 'value'=> ($comment->comment_author ?: 'Anonymous') . ' <' . ($comment->comment_author_email ?: 'n/a') . '>', 'inline'=>true ],
				[ 'name'=>'Submitted', 'value'=> get_date_from_gmt( $comment->comment_date_gmt, 'Y-m-d H:i' ), 'inline'=>true ],
			],
		]],
		// Try buttons too; they’ll show where supported.
		'components' => [[
			'type' => 1, // action row
			'components' => [
				[ 'type'=>2,'style'=>5,'label'=>'Approve','url'=>$approve ],
				[ 'type'=>2,'style'=>5,'label'=>'Unapprove','url'=>$unapprove ],
				[ 'type'=>2,'style'=>5,'label'=>'Spam','url'=>$spam ],
				[ 'type'=>2,'style'=>5,'label'=>'Trash','url'=>$trash ],
				[ 'type'=>2,'style'=>5,'label'=>'View','url'=>$view ],
			],
		]],
	];
}


function cpn_http_json_post( string $url, array $payload ) : bool {
	$res = wp_remote_post( $url, [
		'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
		'body'    => wp_json_encode( $payload ),
		'timeout' => 5,
	] );
	if ( is_wp_error( $res ) ) {
		error_log( 'CPN webhook error: ' . $res->get_error_message() );
		return false;
	}
	$code = wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'CPN webhook HTTP ' . $code . ' body: ' . wp_remote_retrieve_body( $res ) );
		return false;
	}
	return true;
}

// Discord fallback: if components fail, post a plain message with raw links.
function cpn_http_json_post_with_fallback( string $url, array $primary, array $fallback ) : void {
	$res = wp_remote_post( $url, [
		'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
		'body'    => wp_json_encode( $primary ),
		'timeout' => 5,
	] );
	if ( is_wp_error( $res ) || ( $code = wp_remote_retrieve_response_code( $res ) ) < 200 || $code >= 300 ) {
		wp_remote_post( $url, [
			'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
			'body'    => wp_json_encode( $fallback ),
			'timeout' => 5,
		] );
	}
}

/* ================================================================
 * QUEUE ON COMMENT, SEND VIA CRON (~15s)
 * ================================================================ */
add_action( 'comment_post', function( $comment_id, $comment_approved = null ){
	$comment = get_comment( $comment_id );
	if ( ! $comment ) return;
	$post = get_post( $comment->comment_post_ID );
	if ( ! $post || $post->post_type !== 'community-post' ) return;

	// Only pending
	if ( ! in_array( (string) $comment->comment_approved, [ '0', 'hold' ], true ) ) return;

	$recipient_ids = (array) get_option( 'community_post_moderators', [] );
	if ( empty( $recipient_ids ) ) {
		$mods = get_users( [ 'capability' => 'moderate_comments', 'fields' => 'ID' ] );
		$recipient_ids = array_map( 'intval', $mods );
	}

	$queue = get_option( 'cpn_notification_queue', [] );
	if ( ! is_array( $queue ) ) $queue = [];

	$key = 'c_' . absint( $comment_id ); 
	$queue[ $key ] = [
		'comment_id' => absint( $comment_id ),
		'recipients' => array_values( array_unique( array_map( 'intval', $recipient_ids ) ) ),
		'time'       => time(),
	];

	update_option( 'cpn_notification_queue', $queue, false );

	if ( ! wp_next_scheduled( 'cpn_send_queued_notifications' ) ) {
		wp_schedule_single_event( time() + 15, 'cpn_send_queued_notifications' );
	}
}, 10, 2 );

add_action( 'cpn_send_queued_notifications', function() {

	$queue = get_option( 'cpn_notification_queue', [] );
	if ( empty( $queue ) || ! is_array( $queue ) ) return;

	foreach ( $queue as $key => $item ) {
		$comment = get_comment( $item['comment_id'] );
		if ( ! $comment ) { unset( $queue[$key] ); continue; }

		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || $post->post_type !== 'community-post' ) { unset( $queue[$key] ); continue; }

		// Still pending?
		if ( ! in_array( (string) $comment->comment_approved, [ '0', 'hold' ], true ) ) { unset( $queue[$key] ); continue; }

		// Resolve recipient emails
		$emails = [];
		foreach ( (array) $item['recipients'] as $uid ) {
			$u = get_userdata( (int) $uid );
			if ( $u && user_can( $u, 'moderate_comments' ) && ! empty( $u->user_email ) ) {
				$emails[] = $u->user_email;
			}
		}
		if ( empty( $emails ) ) { unset( $queue[$key] ); continue; }

		$links   = cpn_action_links( $comment->comment_ID );
		$subject = sprintf( '[Moderate] New comment pending on: %s', wp_strip_all_tags( get_the_title( $post ) ) );
		$html    = cpn_build_html_email( $post, $comment, $links );
		$alt     = sprintf(
			"Comment awaiting moderation\nPost: %s\nCommenter: %s <%s>\n\nComment:\n%s\n\nApprove: %s\nUnapprove: %s\nSpam: %s\nTrash: %s\nView: %s\nAdmin: %s\n",
			get_permalink( $post ),
			$comment->comment_author ?: 'Anonymous',
			$comment->comment_author_email ?: 'n/a',
			wp_strip_all_tags( $comment->comment_content ),
			$links['front']['approve'], $links['front']['unapprove'], $links['front']['spam'], $links['front']['trash'],
			get_comment_link( $comment ), $links['admin']['edit']
		);

		// Email (one per recipient)
		cpn_send_email_to_each( $emails, $subject, $html, $alt );

		// Slack (Block Kit buttons)
		if ( get_option( 'cpn_slack_enabled' ) && ( $slack_url = get_option( 'cpn_slack_webhook' ) ) ) {
			$payload = cpn_build_slack_payload( $post, $comment, $links );
			cpn_http_json_post( $slack_url, $payload );
		}

		// Discord (buttons via components) with plain-text fallback
		if ( get_option( 'cpn_discord_enabled' ) && ( $discord_url = get_option( 'cpn_discord_webhook' ) ) ) {
			$payload  = cpn_build_discord_payload( $post, $comment, $links );
			$fallback = [
				'content' => "**Comment awaiting moderation**\n"
					. get_the_title( $post ) . " — " . get_permalink( $post ) . "\n\n"
					. cpn_truncate( wp_strip_all_tags( $comment->comment_content ), 600 ) . "\n\n"
					. "Approve: "   . $links['front']['approve']   . "\n"
					. "Unapprove: " . $links['front']['unapprove'] . "\n"
					. "Spam: "      . $links['front']['spam']      . "\n"
					. "Trash: "     . $links['front']['trash']     . "\n"
					. "View: "      . get_comment_link( $comment ),
			];
			cpn_http_json_post_with_fallback( $discord_url, $payload, $fallback );
		}

		unset( $queue[$key] ); // done; prevent duplicates
	}

	update_option( 'cpn_notification_queue', $queue, false );
} );

/* ================================================================
 * SAFETY: ensure CPT supports comments
 * ================================================================ */
add_action( 'init', function(){ add_post_type_support( 'community-post', 'comments' ); } );



// ──────────────────────────────────────────────────────────────────────────
//  Updater bootstrap (plugins_loaded priority 1):
// ──────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    // 1) Load our universal drop-in. Because that file begins with "namespace UUPD\V1;",
    //    both the class and the helper live under UUPD\V1.
    require_once __DIR__ . '/inc/updater.php';

    // 2) Build a single $updater_config array:
    $updater_config = [
        'plugin_file' => plugin_basename(__FILE__),             // e.g. "simply-static-export-notify/simply-static-export-notify.php"
        'slug'        => RUP_MODDASH_NOTIFIER_SLUG,           // must match your updater‐server slug
        'name'        => 'ModDash Notifier',         // human‐readable plugin name
        'version'     => RUP_MODDASH_NOTIFIER_VERSION, // same as the VERSION constant above
        'key'         => '',                 // your secret key for private updater
        'server'      => 'https://raw.githubusercontent.com/stingray82/moddash-notifier/main/uupd/index.json',
    ];

    // 3) Call the helper in the UUPD\V1 namespace:
    \RUP\Updater\Updater_V1::register( $updater_config );
}, 20 );

// MainWP Icon Filter

add_filter('mainwp_child_stats_get_plugin_info', function($info, $slug) {

    if ('moddash-notifier/moddash-notifier.php' === $slug) {
        $info['icon'] = 'https://raw.githubusercontent.com/stingray82/moddash-notifier/main/uupd/icon-128.png'; // Supported types: jpeg, jpg, gif, ico, png
    }

    return $info;

}, 10, 2);