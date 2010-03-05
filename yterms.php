<?php
/*
 * Yahoo Terms
 * Author: Denis de Bernardy <http://www.mesoconcepts.com>
 * Version: 1.0.1
 */

/**
 * yterms
 *
 * @package Yahoo Terms
 **/

class yterms {
	/**
	 * activate()
	 *
	 * @return void
	 **/

	function activate() {
		if ( get_option('yterms_activated') )
			return;
		
		if ( !function_exists('dbDelta') ) {
			include ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		
		global $wpdb;
		$charset_collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( ! empty($wpdb->charset) )
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty($wpdb->collate) )
				$charset_collate .= " COLLATE $wpdb->collate";
		}
		
		if ( get_option('yt_cache_created') ) {
			foreach ( array('yterms', $wpdb->prefix . 'yterms') as $table )
				$wpdb->query("DROP TABLE IF EXISTS $table;");
			
			delete_option('yt_cache_created');
			delete_post_meta_by_key('_related_widgets_got_yterm');
			
			$wpdb->query("
				DELETE FROM $wpdb->term_relationships
				WHERE	term_taxonomy_id IN (
					SELECT	term_taxonomy_id
					FROM	$wpdb->term_taxonomy
					WHERE	taxonomy = 'yahoo_terms'
					);
				");
			$wpdb->query("
				DELETE FROM $wpdb->term_taxonomy
				WHERE	taxonomy = 'yahoo_terms';
				");
		}
		
		dbDelta("
		CREATE TABLE $wpdb->yterms (
			id			char(32) PRIMARY KEY,
			response	text NOT NULL DEFAULT ''
		) $charset_collate;");
		
		update_option('yterms_activated', 1);
	} # activate()
	
	
	/**
	 * query()
	 *
	 * @param string $context
	 * @param string $query
	 * @return object $results
	 **/

	function query($context, $query = '') {
		foreach ( array('context', 'query') as $var ) {
			$$var = preg_replace("/
				<\s*(script|style|object|textarea)(?:\s.*?)?>
				.*?
				<\s*\/\s*\\1\s*>
				/isx", '', $$var);
			$$var = strip_tags($$var);
			$$var = @html_entity_decode($$var, ENT_NOQUOTES, get_option('blog_charset'));
			$$var = str_replace(array("\r\n", "\r"), "\n", $$var);
			$$var = trim($$var);
		}
		
		$request = array(
			'appid' => yterms_appid,
			'context' => $context,
			'query' => $query,
			'output' => 'xml',
			);
		
		$cache_id = md5(serialize($request));
		
		if ( $xml = yterms::get_cache($cache_id) )
			return new SimpleXMLElement($xml);
		
		$res = wp_remote_post(
			'http://api.search.yahoo.com/ContentAnalysisService/V1/termExtraction',
			array('body' => $request)
			);
		
		if ( is_wp_error($res) || $res['response']['code'] != 200 )
			return false;
		
		$xml = $res['body'];
		
		try {
			$res = @ new SimpleXMLElement($xml);
		} catch ( Exception $e ) {
			return false;
		}
		
		yterms::set_cache($cache_id, $xml);
		
		return $res;
	} # query()
	
	
	/**
	 * get_cache()
	 *
	 * @param string $cache_id
	 * @return mixed $result string on cache hit, else false
	 **/

	function get_cache($cache_id) {
		global $wpdb;
		
		$response = $wpdb->get_var("
			SELECT	response
			FROM	$wpdb->yterms
			WHERE	id = '" . $wpdb->_real_escape($cache_id) . "'
			");
		
		if ( !$response )
			return false;
		else
			return $response;
	} # get_cache()
	
	
	/**
	 * set_cache()
	 *
	 * @param string $cache_id
	 * @param string $xml
	 * @return void
	 **/

	function set_cache($cache_id, $xml) {
		global $wpdb;
		
		$wpdb->query("
			INSERT INTO $wpdb->yterms (
				id,
				response
				)
			VALUES (
				'" . $wpdb->_real_escape($cache_id) . "',
				'" . $wpdb->_real_escape($xml) . "'
				);
			");
	} # set_cache()
	
	
	/**
	 * get()
	 *
	 * @param mixed $post
	 * @return array $terms
	 **/

	function get($post = null) {
		if ( is_null($post) ) {
			if ( in_the_loop() ) {
				$post = get_post(get_the_ID());
			} elseif ( is_singular() ) {
				global $wp_the_query;
				$post = $wp_the_query->get_queried_object();
			}
		} elseif ( !is_object($post) ) {
			$post = get_post($post);
		}
		
		if ( !$post || !in_array($post->post_type, array('post', 'page')) || !extension_loaded('simplexml') )
			return array();
		
		$terms = array();
		
		if ( get_post_meta($post->ID, '_yterms', true) ) {
			return wp_get_object_terms($post->ID, 'yterm');
		}
		
		# work around concurrent calls
		update_post_meta($post->ID, '_yterms', '1');
		
		$res = yterms::query($post->post_title . "\n\n" . apply_filters('the_content', $post->post_content));
		
		if ( $res === false )
			return array();
		
		foreach ( $res->Result as $term )
			$terms[] = (string) $term;
		
		if ( $terms ) {
			$terms = array_slice($terms, 0, 3 + round(log(count($terms))));
			wp_set_object_terms($post->ID, $terms, 'yterm');
		}
		
		return wp_get_object_terms($post->ID, 'yterm');
	} # get()
	
	
	/**
	 * update_taxonomy_count()
	 *
	 * @param array $terms
	 * @return void
	 **/

	function update_taxonomy_count($terms) {
		global $wpdb;
		
		foreach ( $terms as $term ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships, $wpdb->posts WHERE $wpdb->posts.ID = $wpdb->term_relationships.object_id AND post_status = 'publish' AND post_type IN ('post', 'page') AND term_taxonomy_id = %d", $term ) );
			$wpdb->update( $wpdb->term_taxonomy, compact( 'count' ), array( 'term_taxonomy_id' => $term ) );
		}
	} # update_taxonomy_count()
} # yterms

global $wpdb;
if ( defined('YTERMS') ) {
	$wpdb->yterms = YTERMS;
} else {
	$wpdb->yterms = 'yterms'; // share this across blogs by default
}

if ( !defined('yterms_appid') )
	define('yterms_appid', 'pgERC.fV34E3W8zXcUnhXhJqoZp1k6_II7xd6IoawQiuvPYXuLpDhy_nX_dg7.ydCOo-');

register_taxonomy('yterm', array('post', 'page'), array(
	'update_count_callback' => array('yterms', 'update_taxonomy_count'),
	'rewrite' => false,
	'query_var' => false,
	'label' => false,
	));

if ( !get_option('yterms_activated') )
	yterms::activate();
?>