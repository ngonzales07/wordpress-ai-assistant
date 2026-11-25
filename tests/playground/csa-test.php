<?php
/* Test harness: mocks the Claude API and drives the plugin through its REST routes. */
$GLOBALS['csa_q']    = array(); // scripted API responses
$GLOBALS['csa_reqs'] = array(); // captured request bodies
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'api.anthropic.com' ) ) return $pre;
	$GLOBALS['csa_reqs'][] = array( 'headers' => $args['headers'], 'body' => $args['body'] );
	$next = array_shift( $GLOBALS['csa_q'] );
	if ( ! $next ) return new WP_Error( 'mock', 'no scripted response' );
	return array( 'headers' => array(), 'body' => json_encode( $next ), 'response' => array( 'code' => $next['_code'] ?? 200, 'message' => 'OK' ), 'cookies' => array() );
}, 10, 3 );

function csa_t_resp( $content, $stop = 'end_turn', $extra = array() ) {
	return array( 'id' => 'msg_' . wp_rand(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => $content, 'stop_reason' => $stop, 'usage' => array( 'input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 500 ) ) + $extra;
}
function csa_t_tool( $id, $name, $input ) { return array( 'type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input ); }
function csa_t_req( $method, $path, $body = array() ) {
	$r = new WP_REST_Request( $method, '/claude-site-agent/v1' . $path );
	foreach ( $body as $k => $v ) $r->set_param( $k, $v );
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
}

add_action( 'init', function () {
	if ( ( $_GET['csa_test'] ?? '' ) !== 'run' ) return;
	header( 'Content-Type: text/plain' );
	$fails = 0;
	$ok = function ( $cond, $label ) use ( &$fails ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) $fails++; };

	wp_set_current_user( 1 );
	foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $old ) wp_delete_post( $old, true );
	foreach ( array( 'ed', 'sub' ) as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u->ID ); } }
	$s = CSA_Settings::get(); $s['api_key_enc'] = CSA_Settings::encrypt( 'sk-ant-test' ); $s['global']['settings'] = 1; $s['roles']['administrator']['settings'] = 1;
	update_option( 'csa_settings', $s );
	$ok( CSA_Settings::api_key() === 'sk-ant-test', 'API key encrypt/decrypt round trip' );

	// Seed content.
	$p1 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About Acme Ltd', 'post_content' => '<p>Acme Ltd was founded in 1999. Acme Ltd rocks.</p>' ) );
	$p2 = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'News', 'post_content' => '<p>Nothing here.</p>' ) );
	update_post_meta( $p2, 'tagline', 'Brought to you by Acme Ltd' );
	update_post_meta( $p2, 'serialized_thing', array( 'x' => 'Acme Ltd' ) );

	/* 1. read tools, thinking + empty-object input round trip */
	$GLOBALS['csa_q'][] = csa_t_resp( array(
		array( 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig123' ),
		csa_t_tool( 'tu_1', 'get_site_info', new stdClass() ),
		csa_t_tool( 'tu_2', 'get_acf_field_groups', array( 'post_type' => 'page' ) ),
	), 'tool_use' );
	list( $st, $v ) = csa_t_req( 'POST', '/sessions' );
	$sid = $v['id'];
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'Build a landing page' ) );
	$ok( 200 === $st && 'running' === $v['status'], 'read tools run immediately, status running (' . $v['status'] . ' ' . ( $v['notice'] ?? '' ) . ')' );
	$req0 = json_decode( $GLOBALS['csa_reqs'][0]['body'], true );
	$ok( 'claude-opus-5-5' === $req0['model'] && 'adaptive' === $req0['thinking']['type'] && 'medium' === $req0['output_config']['effort'] && 'default' === $req0['fallbacks'], 'request: model, adaptive thinking, effort, fallbacks' );
	$ok( '2026-10-21' === $GLOBALS['csa_reqs'][0]['headers']['anthropic-version'] && 'server-side-fallback-2026-07-01' === $GLOBALS['csa_reqs'][0]['headers']['anthropic-beta'], 'request headers' );
	$names = wp_list_pluck( $req0['tools'], 'name' );
	$ok( in_array( 'bulk_replace', $names, true ) && in_array( 'get_acf_field_groups', $names, true ), 'admin sees bulk + ACF tools (' . count( $names ) . ' tools)' );

	/* 2. continue -> create ACF page -> approval */
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'Here is the page.' ), csa_t_tool( 'tu_3', 'create_post', array(
		'post_type' => 'page', 'title' => 'Spring Sale', 'acf' => array(
			'intro_text' => 'Big savings',
			'page_blocks' => array(
				array( 'acf_fc_layout' => 'hero', 'heading' => 'Spring Sale', 'subheading' => 'Up to 50% off' ),
				array( 'acf_fc_layout' => 'features', 'items' => array( array( 'title' => 'Fast', 'text' => '<p>Quick</p>' ), array( 'title' => 'Cheap', 'text' => '<p>Low</p>' ) ) ),
				array( 'acf_fc_layout' => 'cta', 'text' => 'Shop now', 'button' => array( 'url' => '/shop', 'title' => 'Shop', 'target' => '' ) ),
			) ) ) ) ), 'tool_use' );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/continue" );
	$req1 = $GLOBALS['csa_reqs'][1]['body'];
	$ok( false !== strpos( $req1, '"signature":"sig123"' ) && false !== strpos( $req1, '"name":"get_site_info","input":{}' ), 'thinking block and empty {} input replayed unchanged' );
	$r1 = json_decode( $req1, true );
	$last = end( $r1['messages'] );
	$ok( 'user' === $last['role'] && 2 === count( $last['content'] ) && 'tool_result' === $last['content'][0]['type'], 'both tool results sent in one user message' );
	$ok( 'awaiting_approval' === $v['status'] && 1 === count( $v['pending'] ), 'create_post waits for approval' );
	$ok( false !== strpos( wp_json_encode( $v['pending'][0]['preview'] ), 'page_blocks' ) && 'single' === $v['pending'][0]['preview']['risk'], 'preview shows ACF layouts, risk single (draft)' );
	$ok( ! get_page_by_title( 'Spring Sale', OBJECT, 'page' ), 'nothing created before approval' );

	/* 3. approve -> page exists with ACF */
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'Created the draft.' ) ) );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/decide", array( 'decisions' => array( 'tu_3' => array( 'approve' => true ) ) ) );
	$ok( 'running' === $v['status'], 'after approve status running' );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/continue" );
	$ok( 'idle' === $v['status'] && 'Created the draft.' === end( $v['items'] )['text'], 'turn ends with assistant text' );
	$page = get_page_by_title( 'Spring Sale', OBJECT, 'page' );
	$ok( $page && 'draft' === $page->post_status, 'page created as draft' );
	$f = get_fields( $page->ID );
	$ok( 'Up to 50% off' === $f['page_blocks'][0]['subheading'] && 'Cheap' === $f['page_blocks'][1]['items'][1]['title'] && '/shop' === $f['page_blocks'][2]['button']['url'], 'ACF flexible content + nested repeater written' );
	$r2 = json_decode( $GLOBALS['csa_reqs'][2]['body'], true );
	$tr = end( $r2['messages'] )['content'][0];
	$ok( false !== strpos( $tr['content'], '"applied":true' ) && false !== strpos( $tr['content'], 'changeset_id' ), 'tool_result reports applied + change set id' );
	$cs_create = (int) json_decode( $tr['content'], true )['changeset_id'];

	/* 4. validation: unknown layout rejected before approval */
	$GLOBALS['csa_q'][] = csa_t_resp( array( csa_t_tool( 'tu_4', 'update_post', array( 'id' => $page->ID, 'acf' => array( 'page_blocks' => array( array( 'acf_fc_layout' => 'carousel' ) ) ) ) ) ), 'tool_use' );
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'OK' ) ) );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'add a carousel' ) );
	$ok( 'running' === $v['status'] && end( $v['items'] )['is_error'] && false !== strpos( end( $v['items'] )['result'], 'unknown layout' ), 'unknown ACF layout returned to Claude as an error' );
	csa_t_req( 'POST', "/sessions/$sid/continue" );

	/* 5. bulk replace incl. meta, skip serialized */
	$GLOBALS['csa_q'][] = csa_t_resp( array( csa_t_tool( 'tu_5', 'bulk_replace', array( 'search' => 'Acme Ltd', 'replace' => 'Acme Group' ) ) ), 'tool_use' );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'rename Acme Ltd to Acme Group everywhere' ) );
	$pv = $v['pending'][0]['preview'] ?? array();
	$ok( 'awaiting_approval' === $v['status'] && 'bulk' === $pv['risk'] && false !== strpos( $pv['summary'], '4 replacements in 2 items' ), 'bulk preview: ' . ( $pv['summary'] ?? '-' ) );
	$ok( false !== strpos( $pv['notes'] ?? '', 'serialized' ), 'serialized meta skipped and reported' );
	$ok( false !== strpos( get_post( $p1 )->post_content, 'Acme Ltd' ), 'bulk not applied before approval' );
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'Done' ) ) );
	csa_t_req( 'POST', "/sessions/$sid/decide", array( 'decisions' => array( 'tu_5' => array( 'approve' => true ) ) ) );
	clean_post_cache( $p1 );
	$ok( 'About Acme Group' === get_post( $p1 )->post_title && false === strpos( get_post( $p1 )->post_content, 'Ltd' ) && 'Brought to you by Acme Group' === get_post_meta( $p2, 'tagline', true ), 'bulk applied to title, content and meta' );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/continue" );
	list( $st, $list ) = csa_t_req( 'GET', '/changesets' );
	$bulk_cs = (int) $list[0]['id'];

	/* 6. undo bulk */
	list( $st, $u ) = csa_t_req( 'POST', "/changesets/$bulk_cs/undo" );
	clean_post_cache( $p1 ); wp_cache_delete( $p2, 'post_meta' );
	$ok( $u['ok'] && 'About Acme Ltd' === get_post( $p1 )->post_title && 'Brought to you by Acme Ltd' === get_post_meta( $p2, 'tagline', true ) && array( 'x' => 'Acme Ltd' ) === get_post_meta( $p2, 'serialized_thing', true ), 'undo restores title, content and meta (serialized meta intact)' );
	list( $st, $u ) = csa_t_req( 'POST', "/changesets/$bulk_cs/undo" );
	$ok( ! $u['ok'], 'cannot undo twice' );

	/* 7. undo conflict detection on the created page */
	sleep( 1 );
	wp_update_post( array( 'ID' => $page->ID, 'post_title' => 'Spring Sale (edited by hand)' ) );
	list( $st, $u ) = csa_t_req( 'POST', "/changesets/$cs_create/undo" );
	$ok( ! $u['ok'] && ! empty( $u['conflicts'] ), 'undo warns when item was edited afterwards' );
	list( $st, $u ) = csa_t_req( 'POST', "/changesets/$cs_create/undo", array( 'force' => true ) );
	$ok( $u['ok'] && 'trash' === get_post_status( $page->ID ), 'forced undo of a created page moves it to trash' );

	/* 8. reject with note */
	$GLOBALS['csa_q'][] = csa_t_resp( array( csa_t_tool( 'tu_6', 'trash_posts', array( 'ids' => array( $p1 ) ) ) ), 'tool_use' );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'clean up' ) );
	$ok( 'destructive' === $v['pending'][0]['preview']['risk'], 'trash is flagged destructive' );
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'Understood' ) ) );
	csa_t_req( 'POST', "/sessions/$sid/decide", array( 'decisions' => array( 'tu_6' => array( 'approve' => false, 'note' => 'keep the about page' ) ) ) );
	csa_t_req( 'POST', "/sessions/$sid/continue" );
	$body = end( $GLOBALS['csa_reqs'] )['body'];
	$ok( 'publish' === get_post_status( $p1 ) && false !== strpos( $body, 'keep the about page' ) && false !== strpos( $body, '"is_error":true' ), 'rejection keeps post and sends the note to Claude' );

	/* 9. hard guard on options */
	$GLOBALS['csa_q'][] = csa_t_resp( array( csa_t_tool( 'tu_7', 'update_site_options', array( 'options' => array( 'siteurl' => 'https://evil.example' ) ) ), csa_t_tool( 'tu_8', 'update_site_options', array( 'options' => array( 'csa_settings' => array() ) ) ), csa_t_tool( 'tu_9', 'update_site_options', array( 'options' => array( 'blogname' => 'New Name' ) ) ) ), 'tool_use' );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'change site url' ) );
	$errs = array_values( array_filter( $v['items'], fn( $i ) => 'tool' === $i['kind'] && in_array( $i['id'], array( 'tu_7', 'tu_8' ), true ) ) );
	$ok( 2 === count( array_filter( $errs, fn( $i ) => $i['is_error'] ) ) && 1 === count( $v['pending'] ), 'siteurl and plugin settings refused; blogname allowed for approval ' . wp_json_encode( array( $v['status'], $errs, count( $v['pending'] ) ) ) );
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'ok' ) ) );
	csa_t_req( 'POST', "/sessions/$sid/decide", array( 'decisions' => array() ) );
	csa_t_req( 'POST', "/sessions/$sid/continue" );
	$ok( 'New Name' !== get_option( 'blogname' ), 'no decision = rejected' );

	/* 10. theme path guard */
	$ok( is_wp_error( CSA_Guard::theme_read_path( '../../../wp-config.php' ) ) && is_wp_error( CSA_Guard::theme_read_path( 'functions.php/../../../../wp-config.php' ) ) && ! is_wp_error( CSA_Guard::theme_read_path( 'style.css' ) ), 'theme reads confined to theme folder' );

	/* 11. snippets + performance rules (front end checked separately) */
	$GLOBALS['csa_q'][] = csa_t_resp( array( csa_t_tool( 'tu_10', 'save_snippet', array( 'id' => 'test-css', 'type' => 'css', 'code' => 'body{outline:1px solid red}' ) ), csa_t_tool( 'tu_11', 'set_performance_rule', array( 'id' => 'defer-test', 'type' => 'defer_script', 'handle' => 'csa-test-script' ) ), csa_t_tool( 'tu_12', 'save_snippet', array( 'id' => 'bad', 'type' => 'css', 'code' => '</style><script>alert(1)</script>' ) ) ), 'tool_use' );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'css fix + defer' ) );
	$ok( 2 === count( $v['pending'] ), 'snippet and rule await approval; tag-breaking snippet refused' );
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'ok' ) ) );
	csa_t_req( 'POST', "/sessions/$sid/decide", array( 'decisions' => array( 'tu_10' => array( 'approve' => true ), 'tu_11' => array( 'approve' => true ) ) ) );
	csa_t_req( 'POST', "/sessions/$sid/continue" );
	$ok( isset( CSA_Tools_Assets::get_snippets()['test-css'] ) && isset( CSA_Tools_Performance::get_rules()['defer-test'] ), 'snippet and rule stored' );

	/* 12. refusal + API error */
	$GLOBALS['csa_q'][] = csa_t_resp( array(), 'refusal', array( 'stop_details' => array( 'type' => 'refusal', 'category' => 'cyber' ) ) );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'something' ) );
	$ok( 'idle' === $v['status'] && false !== strpos( $v['notice'], 'declined' ), 'refusal handled: ' . $v['notice'] );
	$GLOBALS['csa_q'][] = array( '_code' => 529, 'type' => 'error', 'error' => array( 'type' => 'overloaded_error', 'message' => 'Overloaded' ) );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/message", array( 'text' => 'again' ) );
	$ok( 'error' === $v['status'], 'API 529 -> error status with retry' );
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => 'Back' ) ) );
	list( $st, $v ) = csa_t_req( 'POST', "/sessions/$sid/continue" );
	$ok( 'idle' === $v['status'], 'continue retries after error' );
	$last_req = json_decode( end( $GLOBALS['csa_reqs'] )['body'], true );
	$roles = wp_list_pluck( $last_req['messages'], 'role' );
	$alt = true; for ( $i = 1; $i < count( $roles ); $i++ ) if ( $roles[ $i ] === $roles[ $i - 1 ] ) $alt = false;
	$ok( $alt && 'user' === $roles[0], 'message roles alternate after refusal + error (' . implode( ',', $roles ) . ')' );

	/* 13. editor permissions */
	$ed = wp_insert_user( array( 'user_login' => 'ed', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	wp_set_current_user( $ed );
	$tools = array_keys( CSA_Tool_Registry::for_user() );
	$ok( in_array( 'create_post', $tools, true ) && ! in_array( 'bulk_replace', $tools, true ) && ! in_array( 'save_snippet', $tools, true ) && ! in_array( 'update_site_options', $tools, true ), 'editor gets content/ACF/media only: ' . implode( ',', $tools ) );
	list( $st, $v ) = csa_t_req( 'GET', "/sessions/$sid" );
	$ok( 404 === $st, "editor cannot open admin's conversation" );
	$sub = wp_insert_user( array( 'user_login' => 'sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	list( $st, $v ) = csa_t_req( 'GET', '/sessions' );
	$ok( in_array( $st, array( 401, 403 ), true ), 'subscriber has no access (' . $st . ')' );
	wp_set_current_user( 1 );

	echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
	exit;
} );

// A script handle for the defer rule front-end check.
add_action( 'wp_enqueue_scripts', function () { wp_enqueue_script( 'csa-test-script', includes_url( 'js/wp-embed.min.js' ), array(), null, false ); } );

add_action( 'init', function () {
	if ( ( $_GET['csa_test'] ?? '' ) !== 'demo' ) return;
	wp_set_current_user( 1 );
	$p = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Services', 'post_content' => "<h2>Our services</h2>\n<p>We build websites for small businesses in Austin.</p>\n<p>Call us today.</p>" ) );
	$GLOBALS['csa_q'][] = csa_t_resp( array( csa_t_tool( 'd1', 'search_content', array( 'query' => 'Austin' ) ) ), 'tool_use' );
	$GLOBALS['csa_q'][] = csa_t_resp( array( array( 'type' => 'text', 'text' => "I found **1 page** mentioning Austin. Here's the edit:\n\n- Update the intro line\n- Add a call-to-action link" ), csa_t_tool( 'd2', 'update_post', array( 'id' => $p, 'content' => "<h2>Our services</h2>\n<p>We build fast, accessible websites for small businesses across Central Texas.</p>\n<p><a href=\"/contact\">Book a free consultation</a>.</p>" ) ) ), 'tool_use' );
	list( , $v ) = csa_t_req( 'POST', '/sessions' );
	csa_t_req( 'POST', "/sessions/{$v['id']}/message", array( 'text' => 'Change "Austin" to "Central Texas" on the services page and add a CTA link to /contact.' ) );
	csa_t_req( 'POST', "/sessions/{$v['id']}/continue" );
	echo $v['id']; exit;
} );
add_action( 'init', function () { if ( ( $_GET['csa_test'] ?? '' ) === 'pw' ) { wp_set_password( 'password', 1 ); echo get_userdata( 1 )->user_login; exit; } } );
