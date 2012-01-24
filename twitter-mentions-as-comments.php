<?php
/*
Plugin Name: Twitter Mentions as Comments
Plugin URI: http://ben.balter.com/2010/11/29/twitter-mentions-as-comments/
Description: Queries the Twitter API on a regular basis for tweets about your posts. 
Version: 1.0.4
Author: Benjamin J. Balter
Author URI: http://ben.balter.com
License: GPL2
*/

require_once( dirname( __FILE__ ) . '/includes/class.plugin-boilerplate.php' );

class Twitter_Mentions_As_Comments extends Plugin_Boilerplate {

	//plugin boilerplate settings
	static $instance;
	public $name = 'Twitter Mentions as Comments';
	public $slug = 'twitter-menttions-as-comments';
	public $slug_ = 'twitter_mentions_as_comments';
	public $prefix = 'tmac_';
	public $directory = null;
	public $version = '1.5';

	/**
	 * Registers hooks and filters
	 * @since 1.0
	 */
	function __construct() {
	
		self::$instance = &$this;
		$this->directory = dirname( __FILE__ );
		parent::__construct( &$this );
		
		add_action('tmac_hourly_check', array( &$this, 'hourly' ) );
		
		//Kill cron on deactivation
		register_deactivation_hook( __FILE__, array( &$this, 'deactivation' ) );
				
		//float bug fix
		add_filter( 'tmac_lastID', array( &$this, 'lastID_float_fix'), 10, 2 );
		
		//avatars
		add_filter( 'get_avatar', array( &$this, 'filter_avatar' ), 10, 2  );
		
		
		add_action( 'tmac_init', array( &$this, 'init' ) );
		

	}
	
	function init() {

		$this->options->defaults = array( 	'comment_type' => '', 
											'posts_per_check' => -1, 
											'hide-donate' => false,
											'api_call_limit' => 150,
											'RTs' => 1,
									);
							
	}
	

	
	/**
	 * Retrieves the ID of the last tweet checked for a given post
	 * If the lastID meta field is empty, it checks for comments (backwards compatability) and then defaults to 0
	 * @since 0.4
	 * @param int $postID ID of post
	 * @returns int ID of last tweet
	 */
	function get_lastID( $postID ) {
		
		//Check for an ID stored in meta, if so, return
		$lastID = get_post_meta( $postID, 'tmac_last_id', true );
		
		return $this->api->apply_filters( 'lastID', $lastID, $postID );
	
	} 
	
	/**
	 * Fix for bug pre 1.6.3 where lastID's were stored as float-strings in scientific notation rather than integers
	 * @param string $lastID the lastID as a string in scientific notation
	 * @param int $postID the post we're checking
	 * @returns int the ID of the last tweet checked
	 * @since 1.0
	 */
	function lastID_float_fix( $lastID, $postID ) {
		
		//if we have an ID, return, but check for bad pre-1.6.3 data	
		if ( $lastID && !is_float( $lastID ) ) 
			return $lastID;
			
		//grab all the comments for the post	
		$comments = get_comments( array( 'post_id' => $postID ) );
		$lastID = 0;
		
		//loop through the comments
		foreach ($comments as $comment) {
		
			//if this isn't a TMAC comment, tkip
			if ( $comment->comment_agent != $this->ua )
				continue;
			
			//parse the ID from the author URL
			$lastID = substr($comment->comment_author_url, strrpos($comment->comment_author_url, '/', -2 ) + 1, -1 );
			
			//we're done looking (comments are in reverse cron order)
			break;
		}
			
		return $lastID;
	}
	
	/**
	 * Inserts mentions into the comments table, queues and checks for spam as necessary
	 * @params int postID ID of post to check for comments
	 * @since .1a
	 * @returns int number of tweets found
	 */
	function insert_metions( $postID ) {
			
		//Get array of mentions
		$mentions = $this->get_mentions( $postID );
	
		//if there are no tweets, update post meta to speed up subsequent calls and return
		if ( empty( $mentions->results ) ) {
			update_post_meta( $postID, 'tmac_last_id', $mentions->max_id_str );
			return 0;
		}
			
		//loop through mentions
		foreach ( $mentions->results as $tweet ) {
		
			//If they exclude RTs, look for "RT" and skip if needed
			if ( $this->options->RTs ) {
				if ( substr( $tweet->text, 0, 2 ) == 'RT' ) 
					continue;
			}
			
			//Format the author's name based on cache or call API if necessary
			$author = $this->build_author_name( $tweet->from_user );
				
			//prepare comment array
			$commentdata = array(
				'comment_post_ID' => $postID, 
				'comment_author' => $author,
				'comment_author_email' => $tweet->from_user . '@twitter.com', 
				'comment_author_url' => 'http://twitter.com/' . $tweet->from_user . '/status/' . $tweet->id_str . '/',
				'comment_content' => $tweet->text,
				'comment_date_gmt' => date('Y-m-d H:i:s', strtotime($tweet->created_at) ),
				'comment_type' => $this->options->comment_type
				);
		
			//insert comment using our modified function
			$commentdata = $this->api->apply_filters( 'commentdata', $commentdata );
			$comment_id = $this->new_comment( $commentdata );
			
			//Cache the user's profile image
			add_comment_meta($comment_id, 'tmac_image', $tweet->profile_image_url, true);

			$this->api->do_action( 'insert_mention', $comment_id, $commentdata );
		
		}
		
		//If we found a mention, update the last_Id post meta
		update_post_meta($postID, 'tmac_last_id', $mentions->max_id_str );
	
		//return number of mentions found
		return sizeof( $mentions->results );
	}
	
	/**
	 * Function to run on cron to check for mentions
	 * @since .1a
	 * @returns int number of mentions found
	 * @todo break query into multiple queries so recent posts are checked more frequently
	 */
	function mentions_check(){
		global $tmac_api_calls;
						
		$mentions = 0;
			
		//set API call counter
		$tmac_api_calls = $this->options->api_call_counter;
		
		//Get all posts
		$posts = get_posts('numberposts=' . $this->options->posts_per_check );
		$posts = $this->api->apply_filters( 'mentions_check_posts', $posts );
		
		//Loop through each post and check for new mentions
		foreach ( $posts as $post )
			$mentions += $this->insert_metions( $post->ID );
	
		//update the stored API counter
		$this->options->api_call_counter = $tmac_api_calls;
		
		$this->api->do_action( 'mentions_check' );
	
		return $mentions;
	}
	
	/**
	 * Resets internal API counter every hour
	 *
	 * User API is limited to 150 unauthenticated calls / hour
	 * Authenticated API is limited to 350 / hour
	 * Search calls do not count toward total, although search has an unpublished limit
	 *
	 * @since .2
	 * @todo query the API for our actual limit
	 */
	function reset_api_counter() {
		
		$this->options->api_call_counter = 0;
		$this->api->do_action( 'api_counter_reset' );
		
	}
	
	/**
	 * Function run hourly via WP cron
	 * @since 0.3-beta
	 */
	function hourly() {
			
		//reset API counter
		$this->reset_api_counter();
		
		if ( $this->options->manual_cron )
			return;
		
		//if we are in hourly cron mode, check for Tweets
		$this->mentions_check();
		
	}


	/**
	 * Upgrades options database and sets default options
	 * @since 1.0
	 */
	function upgrade( $from, $to ) {

		wp_schedule_event( time(), 'hourly', 'tmac_hourly_check' );
			
		//change option name pre-1.5
		if ( $from < '1.5' ) {
			$this->options->set_options ( get_option( 'tmac_options' ) );
			delete_option( 'tmac_options' );
		}
		
	}
	
	/**
	 * Callback to remove cron job
	 * @since .1a
	 */
	function deactivation(  ) {
		wp_clear_scheduled_hook('tmac_hourly_check');	
	}
	
	/**
	 * Custom new_comment function to allow overiding of timestamp to meet tweet's timestamp
	 *
	 * (Adaptation of wp_new_comment from /wp-includes/comments.php,
	 * original function does not allow for overriding timestamp,
	 * copied from v 3.3 )
	 *
	 * @params array $commentdata assoc. array of comment data, same format as wp_insert_comment()
	 * @since .1a
	 */
	function new_comment( $commentdata ) {
	
		$commentdata = apply_filters('preprocess_comment', $commentdata);
		
		$commentdata['comment_post_ID'] = (int) $commentdata['comment_post_ID'];
		if ( isset($commentdata['user_ID']) )
			$commentdata['user_id'] = $commentdata['user_ID'] = (int) $commentdata['user_ID'];
		elseif ( isset($commentdata['user_id']) )
			$commentdata['user_id'] = (int) $commentdata['user_id'];
		
		$commentdata['comment_parent'] = isset($commentdata['comment_parent']) ? absint($commentdata['comment_parent']) : 0;
		$parent_status = ( 0 < $commentdata['comment_parent'] ) ? wp_get_comment_status($commentdata['comment_parent']) : '';
		$commentdata['comment_parent'] = ( 'approved' == $parent_status || 'unapproved' == $parent_status ) ? $commentdata['comment_parent'] : 0;
		
		//	BEGIN TMAC MODIFICATIONS (don't use current timestamp but rather twitter timestamp)
		$commentdata['comment_author_IP'] = '';
		$commentdata['comment_agent']     = $this->ua;
		$commentdata['comment_date']     =  get_date_from_gmt( $commentdata['comment_date_gmt'] );
		$commentdata = apply_filters( 'tmac_comment', $commentdata );
		//	END TMAC MODIFICATIONS
		
		$commentdata = wp_filter_comment($commentdata);
	
		$commentdata['comment_approved'] = wp_allow_comment($commentdata);
	
		$comment_ID = wp_insert_comment($commentdata);
	
		do_action('comment_post', $comment_ID, $commentdata['comment_approved']);
	
		if ( 'spam' !== $commentdata['comment_approved'] ) { // If it's spam save it silently for later crunching
			if ( '0' == $commentdata['comment_approved'] )
				wp_notify_moderator($comment_ID);
	
			$post = &get_post($commentdata['comment_post_ID']); // Don't notify if it's your own comment
	
			if ( get_option('comments_notify') && $commentdata['comment_approved'] && ( ! isset( $commentdata['user_id'] ) || $post->post_author != $commentdata['user_id'] ) )
				wp_notify_postauthor($comment_ID, isset( $commentdata['comment_type'] ) ? $commentdata['comment_type'] : '' );
		}
	
		return $comment_ID;
	}
		
	/**
	 * Stopgap measure to prevent duplicate comment error
	 * @param array $commentdata commentdata
	 * @since 0.4
	 */
	function dupe_check( $commentdata ) {
	
		global $wpdb;
		extract($commentdata, EXTR_SKIP);
	
		// Simple duplicate check
		// expected_slashed ($comment_post_ID, $comment_author, $comment_author_email, $comment_content)
		$dupe = "SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID = '$comment_post_ID' AND comment_approved != 'trash' AND ( comment_author = '$comment_author' ";
		if ( $comment_author_email )
			$dupe .= "OR comment_author_email = '$comment_author_email' ";
		$dupe .= ") AND comment_content = '$comment_content' LIMIT 1";
		
		return $wpdb->get_var($dupe);
	
	}
	
	/**
	 * Formats a comment authors name in either the Real Name (@handle) or @handle format depending on information available
	 * @param string $twitterID twitter handle of user
	 * @returns string the formatted name
	 * @since .2
	 */
	function build_author_name( $twitterID ) {
		
		//get the cached real name or query the API
		$real_name = $this->get_author_name( $twitterID );
	
		//If we don't have a real name, just use their twitter handle
		if ( !$real_name ) 
			$name = '@' . $twitterID;
			
		//if we have their real name, build a pretty name
		else
			$name = $real_name . ' (@' . $twitterID . ')';
		
		//return the name
		return $name;
		
	}
	
	
	/**
	 * Filters default avatar since tweets don't have an e-mail but do have a profile image
	 * @param string $avatar default image
	 * @param object data comment data
	 * @since .1a
	 */
	function filter_avatar( $avatar, $data) {
		
		//If this is a real comment (not a tweet), kick
		if ( !isset( $data->comment_agent ) || $data->comment_agent != $this->name )
			return $avatar;
	
		//get the url of the image
		$url = $this->calls->get_profile_image ( substr( $data->comment_author, 1 ), $data->comment_ID);
		
		//replace the twitter image with the default avatar and return
		return preg_replace("/http:\/\/([^']*)/", $url, $avatar);
		
	}

}

$tmac = new Twitter_Mentions_As_Comments();