<?php
/*
Plugin Name: IntenseDebate
Plugin URI: http://intensedebate.com/wordpress
Description: <a href="http://www.intensedebate.com">IntenseDebate Comments</a> enhance and encourage conversation on your blog or website.  Full comment and account data sync between IntenseDebate and WordPress ensures that you will always have your comments.  Custom integration with your WordPress admin panel makes moderation a piece of cake. Comment threading, reply-by-email, user accounts and reputations, comment voting, along with Twitter and friendfeed integrations enrich your readers' experience and make more of the internet aware of your blog and comments which drives traffic to you!  To get started, please activate the plugin and adjust your  <a href="./options-general.php?page=id_settings">IntenseDebate settings</a> .
Version: 2.1.1
Author: IntenseDebate & Automattic
Author URI: http://intensedebate.com
*/

// CONSTANTS
	
	// This plugin's version 
	define( 'ID_PLUGIN_VERSION', '2.1.1' );
	
	// API Endpoints
	define( 'ID_BASEURL', 'http://intensedebate.com' );
	define( 'ID_SERVICE', ID_BASEURL . '/services/v1/operations/postOperations.php' );
	define( 'ID_USER_LOOKUP_SERVICE', ID_BASEURL . '/services/v1/users' );
	define( 'ID_BLOG_LOOKUP_SERVICE', ID_BASEURL . '/services/v1/sites' );

	// Local queue option name
	define( 'ID_REQUEST_QUEUE_NAME', 'id_request_queue' );
	
	// Application identifier, passed with all API transactions
	define( 'ID_APPKEY', 'wpplugin' );
	
	// IntenseDebate is not supported (at all) prior to WordPress 2.0
	define( 'ID_MIN_WP_VERSION', '2.0' );
	
	// URLs for linkage
	define( 'ID_COMMENT_MODERATION_PAGE', ID_BASEURL . '/wpIframe.php?acctid=' );
	define( 'ID_REGISTRATION_PAGE', ID_BASEURL . '/signup' );
	
	// Set to true to get a detailed log of operations in your error_log and your DB ( wp_options WHERE option_name='id_debug_log' )
	define( 'ID_DEBUG', false );
	
	// Get full path to this file
	if ( defined( 'PLUGINDIR' ) ) {
		$id_plugin_path = PLUGINDIR;
	} else if ( defined( 'WP_PLUGIN_DIR' ) ) {
		$id_plugin_path = WP_PLUGIN_DIR;
	} else {
		$id_plugin_path = ABSPATH . 'wp-content/plugins';
	}
	if ( substr( $id_plugin_path, -1 ) != '/' ) {
		$id_plugin_path .= '/';
	}
	if ( is_file( $id_plugin_path . 'intensedebate.php' ) ) {
		define( 'ID_FILE', $id_plugin_path.'intensedebate.php' );
	} else if ( is_file( $id_plugin_path . 'intensedebate/intensedebate.php' ) ) {
		define( 'ID_FILE', $id_plugin_path . 'intensedebate/intensedebate.php' );
	}
		
	load_plugin_textdomain( 'intensedebate' );
	
	// inits json decoder/encoder object if not already available
	if ( !class_exists( 'Services_JSON' ) ) {
		include_once( 'class.json.php' );
	}
	
	// Global var to ensure link wrapper script only outputs once	
	$id_link_wrapper_output = false;

// OVERRIDE MAIL FUNCTIONS

	if ( !function_exists( 'wp_notify_postauthor' ) ) {
		function wp_notify_postauthor() { }
	}
	if ( !function_exists( 'wp_notify_moderator' ) ) {
		function wp_notify_moderator() { }
	}

// Debug logging
	function id_debug_log( $text ) {
		if ( defined( 'ID_DEBUG' ) && true === ID_DEBUG ) {
			$newLogData = get_option( "id_debug_log" ) . "\n\n" . gmdate( "Y-m-d H:i:s" ) . " - $text\n\n";
			id_save_option( "id_debug_log", substr( $newLogData, max( strlen( $newLogData ) - 1048576, 0 ) ) );
			error_log($text);
		}
	}
	
// HOOK ASSIGNMENT
	function id_activate_hooks() {		
		// warning that we don't support this version of wordpress
		if ( false === stripos( get_bloginfo( 'version' ), 'MU' ) && version_compare( get_bloginfo( 'version' ), ID_MIN_WP_VERSION, '<' ) ) {
			add_action( 'admin_head', 'id_wordpress_version_warning' );
			return;
		}
		
		// IntenseDebate individual settings
		add_action( 'admin_notices', 'id_admin_notices' );

		// IntenseDebate server settings		
		add_action( 'admin_menu', 'id_menu_items' );
		add_action( 'init', 'id_process_settings_page' );
		add_action( 'init', 'id_include_handler' );

		if ( is_admin() ) {
			// scripts for admin settings page
			add_action( "admin_head", 'id_settings_head' );
			wp_enqueue_script( 'id_settings', get_bloginfo( 'wpurl' ) . '/index.php?id_inc=settings_js', array( 'jquery' ) );
		}
		
		if ( id_is_active() ) {
			// hooks onto incoming requests
			add_action( 'init', 'id_request_handler' );

			// crud hooks
			add_action( 'comment_post', 'id_save_comment' );
			add_action( 'trackback_post', 'id_save_comment' );
			add_action( 'pingback_post', 'id_save_comment' );
			add_action( 'edit_comment', 'id_save_comment' );
			add_action( 'save_post', 'id_save_post' );
			add_action( 'delete_post', 'id_delete_post' );
			add_action( 'wp_set_comment_status', 'id_comment_status', 10, 2 );

			// individual registration
			add_action( 'show_user_profile', 'id_show_user_profile' );
			add_action( 'profile_update', 'id_profile_update' );
			
			// Settings > Discussion sync
			add_action( 'load-options.php', 'id_discussion_settings_page' );
			
			// drops script include on front-end to auto-login to IntenseDebate
			add_action( 'wp_print_scripts', 'id_auto_login' );

			// comment template replacement to add in JS version
			if ( 0 == get_option( 'id_useIDComments') )
				add_filter( 'comments_template', 'id_comments_template' );
						
			// swap out the comment count links
			add_filter( 'comments_number', 'id_get_comment_number' );
			add_action( 'wp_footer', 'id_get_comment_footer_script' );
			add_action( 'admin_footer', 'id_admin_footer' );
			add_action( 'get_footer', 'id_get_comment_footer_script' );
		}
		
		if ( id_is_active() || id_queue_not_empty() ) {
			// fires the outgoing HTTP request queue for ID synching
			add_action( 'shutdown', 'id_ping_queue' );
		}
	}

	// adds new menu options to wp admin
	function id_menu_items() {
		// Replace the default Comments menu with the ID-enhanced one
		if ( id_is_active() && 0 == get_option( 'id_moderationPage' ) ) {
			global $menu;
			
			if ( function_exists( 'add_object_page' ) ) { // WP 2.7+
				unset( $menu[25] );
				add_object_page(
					__( 'Comments', 'intensedebate' ),
					__( 'Comments', 'intensedebate' ),
					'moderate_comments',
					'intensedebate',
					'id_moderate_comments',
					'../wp-content/plugins/intensedebate/comments.png' // @todo better URL
				);
			} else { // < WP 2.7
				unset( $menu[20] );
				add_menu_page(
					__( 'Comments', 'intensedebate' ),
					__( 'Comments', 'intensedebate' ),
					'moderate_comments',
					'intensedebate',
					'id_moderate_comments'
				);
			}
		}
		add_options_page(
			__( 'IntenseDebate Options', 'intensedebate' ), 
			__( 'IntenseDebate', 'intensedebate' ), 
			'manage_options', 
			'id_settings',
			'id_settings_page'
		);
	}
	
	function id_activate() {
		update_option( 'thread_comments', 1 );
	}
	register_activation_hook( ID_FILE, 'id_activate' );
	
	function id_deactivate() {
		$fields = array(
			'appKey' => ID_APPKEY,
			'blogKey' => get_option( 'id_blogKey' ),
			'blogid' => get_option( 'id_blogID' ),
		);
		$queue = id_get_queue();
		$op = $queue->add( 'plugin_deactivated', $fields, 'id_generic_callback' );
		$queue->ping( array( $op ) );
	}
	register_deactivation_hook( ID_FILE, 'id_deactivate' );


// UTILITIES
	
	// Load Snoopy if WP HTTP isn't here, and Snoopy's not already loaded (< WP 2.7 compat)
	if ( !function_exists( 'wp_remote_get' ) && !function_exists( 'get_snoopy' ) ) {
		function get_snoopy() {
			include_once(ABSPATH.'/wp-includes/class-snoopy.php');
			return new Snoopy;
		}
	}
	
	function id_http_query( $url, $fields, $method = 'GET' ) {
		$results = '';
		if ( function_exists( 'wp_remote_get' ) ) {
			// The preferred WP HTTP library is available
			if ( 'POST' == $method ) {
				$response = wp_remote_post( $url, array( 'body' => $fields ) );
				if ( !is_wp_error( $response ) ) {
					$results = wp_remote_retrieve_body( $response );
					id_debug_log( "Successfully Sent: " . serialize( $fields ) . " - " . $results );
				} else {
					id_debug_log( "Failed to Send: " . serialize( $fields ) . " - " . $response->get_error_message() );
				}
			} else {
				$url .= '?' . http_build_query( $fields );
				$response = wp_remote_get( $url );
				if ( !is_wp_error( $response ) ) {
					$results = wp_remote_retrieve_body( $response );
					id_debug_log( "Successfully Sent: " . serialize( $fields ) . " - " . $results );
				} else {
					id_debug_log( "Failed to Send: " . serialize( $fields ) . " - " . $response->get_error_message() );
				}
			}
		} else {
			// Fall back to Snoopy
			$snoopy = get_snoopy();
			if ( 'POST' == $method ) {
				if ( $snoopy->submit( $url, $fields ) ) {
					$results = $snoopy->results;
					id_debug_log( "Successfully Sent: " . serialize( $fields ) . " - " . $results );
				} else {
					id_debug_log( "Failed to Send: " . serialize( $fields ) . " - " . $results );
				}
			} else {
				$url .= '?' . http_build_query( $fields );
				if ( $snoopy->fetch( $url ) ) {
					$results = $snoopy->results;
					id_debug_log( "Successfully Sent: " . serialize( $fields ) . " - " . $results );
				} else {
					id_debug_log( "Failed to Send: " . serialize( $fields ) . " - " . $results );
				}
			}
		}
		return $results;
	}
	
	function id_get_json_service() {
		global $id_json_service;
		if ( !$id_json_service ) {
			$id_json_service = new Services_JSON();
		}
		return $id_json_service;
	}

	// blog option
	function id_save_option( $name, $value ) {
		if ( false === get_option( $name ) ) {
			add_option( $name, $value, '', 'no' );
		} else {
			update_option( $name, $value );
		}
	}

	// user options
	function id_save_usermeta_array( $user_id, $meta = array() ) {
		foreach( $meta as $n => $v ) {
			id_save_usermeta( $user_id, $n, $v );
		}
	}

	// saves or wipes an individual meta field
	function id_save_usermeta( $user_id, $name, $value = null ) {
		if ( isset( $value ) && !empty( $value ) ) {
			update_usermeta( $user_id, $name, $value );
		} else {
			delete_usermeta( $user_id, $name );
		}
	}
	
	function id_user_connected() {
		$current_user = wp_get_current_user();
		$user_id = $current_user->ID;
		$userID = get_usermeta( $user_id, 'id_userID' );
		$userKey = get_usermeta( $user_id, 'id_userKey' );
		
		return ( $userID && $userKey );
	}

	// returns first non-null and non empty argment
	function id_coalesce() {
		$args = func_get_args();
		foreach ( $args as $v ) {
			if ( isset( $v ) && !empty( $v ) )
				return $v;
		}
		return null;
	}

	// hash generator
	function id_generate_token( $fields ) {
		return  md5( time() . implode( '&', $fields ) );
	}

	// determines whether ID has been activated via the settings page
	function id_is_active() {
		return (
			get_option( 'id_blogID' ) &&
			get_option( 'id_blogKey' ) &&
			get_option( 'id_userID' ) &&
			get_option( 'id_userKey' ) &&
			get_option( 'id_blogAcct' )
		);
	}

	// pulls a passed parameter from indicated scopes
	function id_param( $name, $default = null, $scopes = null ) {
		if ( $scopes == null ) {
			$scopes = array( $_POST, $_GET );
		}
		foreach ( $scopes as $thisScope ) {
			if ( isset( $thisScope[$name] ) ) {
				return $thisScope[$name];
			}
		}
		return $default;
	}

	// inits queue object
	function id_get_queue() {
		global $id_q;
		if ( !$id_q ) {
			$id_q = new id_queue();
		}
		return $id_q;
	}

	// pings queue object
	function id_ping_queue() {
		$queue = id_get_queue();
		$queue->ping();
	}
	
	function id_queue_not_empty() {
		$queue = id_get_queue();
		$queue->load();
		if ( count( $queue->operations ) ) {
			return true;
		}
		else {
			return false;
		}
	}
	
	// Generic request handler
	function id_remote_api_call( $url, $fields = array(), $method = "GET" ) {
		$results = "";
		$fields['appKey'] = ID_APPKEY;
		$results = id_http_query( $url, $fields, $method );
		return $results;
	}
	
	// deconstructs query string
	if ( !function_exists( 'http_parse_query' ) ) {
		function http_parse_query( $array = NULL, $convention = '%s' ) {
		    if ( count( $array ) == 0 ) {
		        return '';
		    } else {
		        if ( function_exists( 'http_build_query' ) ) {
		            $query = http_build_query( $array );
		        } else {
		            $query = '';
		            foreach ( $array as $key => $value ) {
		                if ( is_array( $value ) ) {
		                    $new_convention = sprintf( $convention, $key ) . '[%s]';
		                    $query .= http_parse_query( $value, $new_convention );
		                } else {
		                    $key = urlencode( $key );
		                    $value = urlencode( $value );
		                    $query .= sprintf( $convention, $key ) . "=$value&";
		                }
		            } 
		        }
		        return $query; 
		    }   
		}
	}
	
// CRUD OPERATION HOOKS

	function id_save_comment( $comment_ID = 0 ) {
		if ( get_option( "id_syncWPComments" ) == 1 )
			return;
			
		$comment = new id_comment( array( 'comment_ID' => $comment_ID ) );
		$comment->loadFromWP();
		if ( $comment->comment_status != 'spam' ) {
			// Don't send the spam
			$queue = id_get_queue();
			$queue->add( 'save_comment', $comment->export(), 'id_generic_callback' );
		}
	}

	function id_comment_status( $comment_id, $status ) {
		if ( get_option( "id_syncWPComments" ) == 1 )
			return;
			
		if ( $status == "delete" ) {
			$packet = new stdClass;
			$packet->comment_id = $comment_id;
			$packet->status = $status;
			$queue = id_get_queue();
			$queue->add( 'update_comment_status', $packet, 'id_generic_callback' );
		}
		else {
			$comment = new id_comment( array( 'comment_ID' => $comment_id ) );
			$comment->loadFromWP();
			if ( $status=="hold" )
				$comment->comment_approved = 0;
			if ( $status=="approved" )
				$comment->comment_approved = 1;
			if ( $status=="spam" )
				$comment->comment_approved = "spam";
			$queue = id_get_queue();			
			$queue->add( 'save_comment', $comment->export(), 'id_generic_callback' );
		}
	}
	
	// don't save the revisions
	function id_save_post( $post_id ) {
		if ( get_option( "id_syncWPPosts" ) == 1 )
			return;

		$post = get_post( $post_id );
		if ( $post->post_parent == 0 ) {
			$p = new id_post( $post );
			$packet = $p->export();
			$queue = id_get_queue();
			$queue->add( 'save_post', $packet, 'id_generic_callback' );
		}
	}

	function id_delete_post( $post_id ) {
		if ( get_option( "id_syncWPPosts" ) == 1 )
			return;
			
		$packet = new stdClass;
		$packet->post_id = $post_id;
		$queue = id_get_queue();
		$queue->add( 'delete_post', $packet, 'id_generic_callback' );
	}

	// callbacks return true to remove from queue
	function id_generic_callback( &$result, &$response, &$operation ) {
		$args = func_get_args();
		if ( $result ) return true;
		if ( $response['attempt_retry'] ) return false;
		return true;
	}
	

// DATA WRAPPERS

	class id_data_wrapper {

		var $properties = array();
		
		// generic constructor. You can pass in an array/stdClass of
		// values for $props and prepopulate your object either using
		// local or remote names
		function id_data_wrapper( $props = null, $bRemoteLabels = false ) {
			if ( isset( $props ) ) {
				if ( $bRemoteLabels ) {
					$this->loadFromRemote( $props );
				} else {
					$this->loadFromLocal( $props );
				}
			}
		}
		
		// registers a property with the object. $localname is the wordpress column
		// name and also the internal property name, $remoteName is the ID field name
		function addProp( $localName, $remoteName = null, $defaultValue = null ) {
			$remoteName = isset( $remoteName ) ? $remoteName : $localName;
			$this->properties[$localName] = $remoteName;
			$this->$localName = $defaultValue;
		}
		
		// loads object with props from passed object, assumption is that the passed
		// object is keyed using local variable names
		function loadFromLocal( $o ) {
			$incomingProps = $this->scrubInputHash($o);
			foreach ( $this->properties as $local => $remote ) {
				if ( isset( $incomingProps[$local] ) ) {
					$this->$local = $incomingProps[$local];
				}
			}
		}
		
		// loads object with props from remote object hash
		function loadFromRemote( $o ) {
			$props = array_flip( $this->properties );
			$incomingProps = $this->scrubInputHash( $o );
			foreach( $props as $remote => $local ) {
				if ( isset( $incomingProps[$remote] ) ) {
					$this->$local = $incomingProps[$remote];
				}
			}
		}
		
		// makes an array out of whatever is passed in
		function scrubInputHash( $o ) {
			$incomingProps = $o;
			if ( !is_array( $o ) ) {
				$incomingProps = get_object_vars( $o );
			}
			return $incomingProps;
		}

		function loadFromRemoteJson( $jsonString ) {
			$j = id_get_json_service();
			$o = $j->decode( $jsonString );
			$this->loadFromRemote( $o );
		}
		
		// exports object properties into remote property names
		function export( $bRemote = true ) {
			$o = array();
			foreach ( $this->properties as $local => $remote ) {
				if ( $remote == "comment_text" )
					$o[$remote] = trim( $this->$local ); // trim the comment text
				else
					$o[$remote] = $this->$local;
			}
			return $o;
		}
		
		function props() {
			$props = array();
			foreach ( $this->properties as $n => $v ) {
				$props[$n] = $this->$n;
			}
			return $props;
		}
	}

	

// COMMENT WRAPPER

	class id_comment extends id_data_wrapper {
		
		var $post = null;
		
		function id_comment( $props = null, $bRemoteLabels = false ) {
			$this->addProp( 'intensedebate_id' );
			$this->addProp( 'comment_ID', 'comment_id' );
			$this->addProp( 'comment_post_ID', 'comment_post_id' );
			$this->addProp( 'comment_author' );
			$this->addProp( 'comment_author_email' );
			$this->addProp( 'comment_author_url' );
			$this->addProp( 'comment_author_IP', 'comment_author_ip' );
			$this->addProp( 'comment_date' );
			$this->addProp( 'comment_date_gmt' );
			$this->addProp( 'comment_content', 'comment_text' );
			// $this->addProp( 'comment_karma' );
			$this->addProp( 'comment_approved', 'comment_status' );
			$this->addProp( 'comment_agent' );
			$this->addProp( 'comment_type' );
			$this->addProp( 'comment_parent' );
			$this->addProp( 'user_id' );
			$this->id_data_wrapper( $props, $bRemoteLabels );
		}
		
		
		// loadFromWP
		// loads comment from WP database
		function loadFromWP() {
			if ( $this->comment_ID ) {
				$wp_comment = get_comment( $this->comment_ID, ARRAY_A );
				$this->loadFromLocal( $wp_comment );
			}
		}
		
		// saves back to WP database
		function save() {
			if ( !$this->valid() )
				return false;	
			$result = 0;
			if ( $this->comment_ID ) {
				remove_action( 'edit_comment', 'id_save_comment' );
				$result = wp_update_comment( $this->props() );
				add_action( 'edit_comment', 'id_save_comment' );
			} else {
				remove_action( 'comment_post', 'id_save_comment' );
				$result = $this->comment_ID = wp_insert_comment( $this->props() );
				add_action( 'comment_post', 'id_save_comment' );
			}
			return ( $result != 0 );
		}
		
		// evaluates whether the comment is valid
		function valid() {
			return ( !empty( $this->comment_content ) && $this->comment_post_ID && !$this->duplicateEntry() );
		}
		
		// returns true if this comment already in db, stolen from wp_allow_comment
		function duplicateEntry() {
			global $wpdb;
			extract( $this->props() );
			
			// sql to check for duplicate comment post
			$dupe = $wpdb->prepare( "SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID = %d AND ( comment_author = '%s' ", $comment_post_ID, $comment_author );
			if ( $comment_author_email )
				$dupe .= $wpdb->prepare( "OR comment_author_email = '%s' ", $comment_author_email );
			$dupe .= $wpdb->prepare( ") AND comment_content = '%s' LIMIT 1", $comment_content );

			if ( $wpdb->get_var( $dupe ) ) {
				return true;
			}
			return false;
		}
		
		// associated post parent object
		function post() {
			if ( !$this->post ) {
				$this->post = new id_post( get_post( $this->comment_post_ID, ARRAY_A ) );
			}
			return $this->post;
		}
		
		function export() {
			$o = parent::export();
			$p = $this->post();
			$o['post'] = $p->export();
			return $o;
		}
		
		// the intensedebate_id actually has to be stored with the post because
		// there is no comment metadata
		function intensedebate_id( $intensedebate_id = null ) {
			$post = $this->post();
			return $post->setRemoteID( $this->comment_ID, $intensedebate_id );
		}
	}



// POST WRAPPER

	class id_post extends id_data_wrapper {

		function id_post( $props = null, $bRemoteLabels = false ) { 
		
			$this->addProp( 'ID', 'postid' );
			$this->addProp( 'post_title', 'title' );
			$this->addProp( 'guid' );
			$this->addProp( 'url' );
			$this->addProp( 'post_author_name', 'author' );
			$this->addProp( 'post_author', 'authorid' );
			$this->addProp( 'post_modified_gmt', 'date_gmt' );
			$this->addProp( 'comment_status' );
			$this->addProp( 'ping_status' );
			
			// load passed props
			$this->id_data_wrapper( $props, $bRemoteLabels );
			
			// load up inferred props
			$this->loadProprietaryProps();
		}
		
		function loadProprietaryProps() {
			if ( $this->post_author ) {
				$a = get_userdata( $this->post_author );
				$this->post_author_name = trim( $a->display_name );
			}
		}
		
		// need the category names in an array
		function categories() {
			if ( function_exists( 'wp_get_post_categories' ) ) {
				$category_ids = (array) wp_get_post_categories( $this->ID );
				$categories = array();
				foreach ( $category_ids as $id ) {
					$c = get_category( $id );
					$categories[] = $c->cat_name;
				}
			} else {
				global $wpdb;
				$results = $wpdb->get_results( $wpdb->prepare( "SELECT c.cat_name FROM {$wpdb->categories} c, {$wpdb->post2cat} pc WHERE pc.category_id = c.cat_ID AND pc.post_id = %d", $this->ID ), ARRAY_A );
				$categories = array();
				foreach ( $results as $row ) {
					$categories[] = $row['cat_name'];
				}
			}
			return $categories;
		}
		
		function comments() {
			return null;
		}
		
		function export() {
			$me = parent::export();
			$me['comments'] = $this->comments();
			$me['categories'] = $this->categories();
			$me['url'] = get_permalink( $this->ID );
			return $me;
		}
		
		function mapCategory( $categoryID ) {
			$c = get_category( $categoryID );
			return $c->name;
		}
		
		function mapComment( $o ) {
			return $o->comment_ID;
		}
		
		
		// saves back to WP database
		function save() {
			if ( !$this->valid() )
				return false;
			remove_action( 'save_post', 'id_save_post' );
			
			// watch for text-link-ads.com plugin
			if ( function_exists( "tla_send_updated_post_alert" ) )
				remove_action( 'edit_post', 'tla_send_updated_post_alert' );
			
			$result = wp_update_post( get_object_vars( $this ) );
			add_action( 'save_post', 'id_save_post' );
			
			// add hooks for text-link-ads.com back in			
			if ( function_exists( "tla_send_updated_post_alert" ) )
				add_action( 'edit_post', 'tla_send_updated_post_alert' );
			
			return $result;
		}
		
		// evaluates whether the comment is valid
		function valid() {
			return $this->ID;
		}

	}


// QUEUE

	class id_queue_operation {

		var $action, $callback, $operation_id, $time_gmt, $data, $response, $success;
		
		function id_queue_operation( $action, $data, $callback = null ) {
			$this->action = $action;
			$this->callback = $callback;
			$this->data = $data;
			$this->time_gmt = gmdate( "Y-m-d H:i:s" );
			$this->operation_id = $this->id();
			$this->success = false;
			$this->wp_version = get_bloginfo( 'version' );
			$this->id_plugin_version = ID_PLUGIN_VERSION;
		}

		function id() {
			return md5( $this->action . $this->callback . $this->time_gmt . serialize( $this->data ) );
		}
	}

	class id_queue {
		
		var $queueName = ID_REQUEST_QUEUE_NAME;
		var $url = ID_SERVICE;
		var $operations = array();

		function id_queue() {
			$this->load();
			//$this->create();
		}

		function load() {
			$this->operations = get_option( $this->queueName );
			if ( $this->operations == false ) {
				$this->create();
			}
		}

		function create() {
			$this->operations = array();
			$this->store();
		}
		
		function store() {
			id_save_option( $this->queueName, $this->operations );
		}
		
		function add( $action, $data, $callback = null ) {
			$op = new id_queue_operation( $action, $data, $callback );
			return $this->queue( $op );
		}
		
		function queue( $operation ) {
			$this->operations[] = $operation;
			
			// Ping immediately for MU compatibility
			if ( stripos( get_bloginfo( 'version' ), 'MU' ) !== false )
				$this->ping();
				
			return $operation;
		}

		function ping( $operations = null ) {
			if ( !$operations )
				$operations = $this->operations;
			if ( count( $operations ) === 0 )
				return;
			$this->process( $this->send( $operations ) );
			$this->store();
		}
		
		function send( $operations = null ) {
			if ( !$operations )
				$operations = $this->operations;			
			
			$jsonservice = id_get_json_service();
			$fields = array(
				'appKey' => ID_APPKEY,
				'blogKey' => get_option( 'id_blogKey' ),
				'blogid' => get_option( 'id_blogID' ),
				'operations' => $jsonservice->encode( $operations )
			);

			return id_http_query( $this->url, $fields, 'POST' );
		}

		function process( $rawResults ) {
			
			// HTTP request failed?  Leave queue alone and attempt to resend later
			if ( false == $rawResults )
				return;
			
			// Decode results string
			$jsonservice = id_get_json_service();
			$results = $jsonservice->decode( $rawResults );
			
			// flip the array around using operation_id as the key
			$results = $this->reIndex( $results, 'operation_id' );

			// loop through sent operations and try to resolve results for each
			$newQueue = array();
			foreach ( $this->operations as $operation ) {

				$result = $results[$operation->operation_id];
				if ( isset( $result ) ) {
					
					$callback = $operation->callback;
					if ( isset( $callback ) && function_exists( $callback ) ) {
						// callback returns true == remove from queue
						// callback returns false == add back to queue
						$finished = call_user_func_array( $callback, array( "result" => $result->result, "response" => $result->response, "operation" => $operation ) );
						
						$operation->success = $finished;
						$operation->response = $result->response;
						
						if ( !$finished ) {
							$newQueue[] = $operation;			
						}
					}
					
				} else {
					// no result returned for that operation, requeue
					$newQueue[] = $operation;
				}

			}
			
			// store new queue
			$this->operations = $newQueue;
		}
		
		function testResults() {
			$results = array();
			foreach ( $this->operations as $op ) {
				$result = new stdClass;
				$result->operation_id = $op->operation_id;
				$result->result = $op->data;
				$results[] = $result;
			}
			$jsonservice = id_get_json_service();
			return $jsonservice->encode( $results );
		}
		
		function reIndex( $arrIn, $prop ) {
			$arrOut = array();
			if ( isset( $arrIn ) ) {
				foreach ( $arrIn as $item ) {
					$arrOut[$item->$prop] = $item;
				}
			}
			return $arrOut;
		}
	}


// REST SERVICE FUNCS
	
	// include handler (css/js)
	function id_include_handler() {
		$inc = id_param( 'id_inc' );
		if ( !$inc )
			return;
		$fn = "id_INCLUDE_$inc";
		if ( function_exists( $fn ) ) {
//			if (class_exists('Debugger')) Debugger::$debug = false;
			ob_end_clean();
			return call_user_func( $fn );
		}
	}

	// Main Handler
	function id_request_handler() {
		// Blanket protection against accidental access to edit-comments.php
		if ( 0 == get_option( 'id_moderationPage') && 'edit-comments.php' == basename( $_SERVER['REQUEST_URI'] ) )
			wp_redirect( get_bloginfo( 'wpurl' ) . '/wp-admin/admin.php?page=intensedebate' );

		// determine requested action
		$action = id_param( 'id_action' );
		if ( !$action )
			return;

		id_debug_log( 'Request for: ' . $action );

		// translated func name
		$fn = "id_REST_$action";
		if ( !function_exists( $fn ) ) {
			id_request_error( 'Unknown action' );
			return;
		}

		// token key
		$token = id_param( 'id_token' );
		if ( get_option( 'id_import_token' ) !== $token ) {
			id_request_error( 'Missing or invalid token' );
			return;
		}

		// calls named func
		$result = call_user_func( $fn );
		id_debug_log( 'Response: ' . print_r( $result, true ) );
		id_response_render( $result );
	}
	
	function id_request_error( $msg ) {
		$result = new stdClass();
		$result->success = false;
		$result->error = $msg;
		id_response_render( $result );
	}
	
	function id_request_message( $msg ) {
		$result = new stdClass();
		$result->success = true;
		$result->data = null;
		$result->message = $msg;
		id_response_render( $result );
	}
	
	function id_response_render( $result, $contentType = "application/json" ) {
		ob_end_clean();
		$charSet = get_bloginfo( 'charset' );
		header( "Content-Type: {$contentType}; charset={$charSet}" );
		$jsonservice = id_get_json_service();
		if ( !$jsonservice )
			return;
		die( $jsonservice->encode( $result ) );
	}

	function id_REST_clear_debug_log() {
		id_save_option( "id_debug_log", "" );
		return "true";
	}
	
	function id_REST_get_debug_log() {
		return get_option( "id_debug_log" );
	}
	
	function id_REST_get_comments_by_user() {
		global $wpdb;
		
		$email = id_param( 'id_email' );
		$postid = id_param( 'id_postid' );
		
		if ( strlen( $email ) > 0 )
			$emailStr = $wpdb->prepare( "c.comment_author_email = %s", $email );
		else 
			$emailStr = "true";
			
		if ( strlen( $postid ) > 0 )
			$postStr = $wpdb->prepare( "c.comment_post_ID = %d", $postid );
		else 
			$postStr = "true";
			
		if ( 'true' == $postStr && 'true' == $emailStr ) {
			id_request_message( "Invalid params $postid $postStr $email $emailStr" );
			return array();
		}
			
		$results = $wpdb->get_results( "SELECT * FROM {$wpdb->comments} c WHERE $emailStr AND $postStr ORDER BY c.comment_ID DESC LIMIT 0, 30" );
		
		if ( !count( $results ) ) {
			id_request_message( 'No comments' );
			return array();
		}
		
		$comments = array_map( "id_export_comment", $results );
		return $comments;
	}
	
// ACTION: import
// Gets comments by post_id, includes paging parameters
// Used to populate ID database right after registration
// http://localhost/wordpress/2.5/?id_action=import&post_id=3&offset=0

	function id_REST_import() {
		global $wpdb;

		$count = id_param( 'id_count', 100 );
		if ( $count <= 0 )
			id_request_error( 'Return count must be positive.' );
		
		$min_cid = id_param( 'id_start_cid', 0 );
		if ( $min_cid < 0 )
			id_request_error( 'Start commentid must be positive.' );
		
		$start = get_option( 'id_import_comment_id' );
		if ( $start <= 0 )
			id_request_message( 'Import complete.' );
		
		id_debug_log( 'Initiating import response.' );
		
		$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->comments} c WHERE c.comment_ID >= %d AND c.comment_ID <= %d AND c.comment_approved != 'spam' ORDER BY c.comment_ID DESC LIMIT %d", $min_cid, $start, $count );
		$results = $wpdb->get_results( $sql );
		if ( !count( $results ) ) {
			id_debug_log( 'No comments to import.' );
			id_save_option( 'id_signup_step', 3 );
			id_request_message( 'Import complete.' );
		}
		
		// Update each comment to use "external" names
		$comments = array_map( "id_export_comment", $results );
		
		// mark the next comment_id for the next import request
		$lastCommentIndex = count( $comments ) - 1;
		$next_id = max( 0, (int) $comments[$lastCommentIndex]['comment_id'] - 1 );
		id_save_option( 'id_import_comment_id', $next_id );
		$totalRemainingCount = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} c WHERE c.comment_ID >= {$min_cid} AND c.comment_ID <= {$next_id} AND c.comment_approved != 'spam'", $min_cid, $next_id ) );
		
		$result = new stdclass;
		$result->totalCommentCount = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} c WHERE c.comment_ID >= %d AND c.comment_approved != 'spam'", $min_cid ) );
		$result->totalRemainingCount = $totalRemainingCount;
		$result->time_gmt = gmdate( "Y-m-d H:i:s" );
		$result->time = date( "Y-m-d H:i:s" );
		$result->success = "true";
		$result->data = $comments;
		
		return $result;
	}
	
	function id_export_comment( $o ) {
		$c = new id_comment( $o );
		return $c->export();
	}


// ACTION: sync moderation settings from ID

	function id_REST_sync_moderation_settings() {
		$settings = id_param( 'settings' );
		
		if ( empty( $settings ) )
			return 'false';
		
		// Decode and UnJSON the settings so we can work with them
		$settings = rawurldecode( stripslashes( $settings ) );
		$j = id_get_json_service();
		$opt = $j->decode( $settings );
		
		// Moderation links
		if ( isset( $opt->min_links_for_moderations ) )
			update_option( 'comment_max_links', $opt->min_links_for_moderations );
		
		// Update all the boolean values
		if ( 'T' == $opt->all_comments_require_approval )
			update_option( 'comment_moderation', '1' );
		else if ( 'F' == $opt->all_comments_require_approval )
			update_option( 'comment_moderation', '' );
			
		if ( 'T' == $opt->require_previously_approved )
			update_option( 'comment_whitelist', '1' );
		else if ( 'F' == $opt->require_previously_approved )
			update_option( 'comment_whitelist', '' );
			
		if ( 'T' == $opt->email_new_comments )
			update_option( 'comments_notify', '1' );
		else if ( 'F' == $opt->email_new_comments )
			update_option( 'comments_notify', '' );
			
		if ( 'T' == $opt->email_requires_moderation )
			update_option( 'moderation_notify', '1' );
		else if ( 'F' == $opt->email_requires_moderation )
			update_option( 'moderation_notify', '' );
		
		if ( 'T' == $opt->show_threads )
			update_option( 'thread_comments', '1' );
		else if ( 'F' == $opt->show_threads )
			update_option( 'thread_comments', '' );
		
		// Need to do some magic on the moderate/blacklist strings
		if ( isset( $opt->moderate_words ) || isset( $opt->moderate_ips ) || isset( $opt->moderate_emails ) ) {
			if ( !isset( $opt->moderate_words ) )  $opt->moderate_words  = '';
			if ( !isset( $opt->moderate_ips ) )    $opt->moderate_ips    = '';
			if ( !isset( $opt->moderate_emails ) ) $opt->moderate_emails = '';
			$moderate_words  = explode( ' ', $opt->moderate_words );
			$moderate_ips    = explode( ' ', $opt->moderate_ips );
			$moderate_emails = explode( ' ', $opt->moderate_emails );
			$moderate = array_merge( $moderate_words, $moderate_ips, $moderate_emails );
			$moderate = implode( "\n", id_cleanup_moderation_array( $moderate ) );
			update_option( 'moderation_keys', $moderate );
		}
		
		if ( isset( $opt->blacklisted_words ) || isset( $opt->blacklisted_ips ) || isset( $opt->blacklisted_emails ) ) {
			if ( !isset( $opt->blacklisted_words ) )  $opt->blacklisted_words  = '';
			if ( !isset( $opt->blacklisted_ips ) )    $opt->blacklisted_ips    = '';
			if ( !isset( $opt->blacklisted_emails ) ) $opt->blacklisted_emails = '';
			$blacklist_words  = explode( ' ', $opt->blacklisted_words );
			$blacklist_ips    = explode( ' ', $opt->blacklisted_ips );
			$blacklist_emails = explode( ' ', $opt->blacklisted_emails );
			$blacklist = array_merge( $blacklist_words, $blacklist_ips, $blacklist_emails );
			$blacklist = implode( "\n", id_cleanup_moderation_array( $blacklist ) );
			update_option( 'blacklist_keys', $blacklist );
		}
				
		if ( 'T' == $opt->akismet && get_option( 'wordpress_api_key' ) )
			return get_option( 'wordpress_api_key' );
		else
			return 'true';
	}
	
	function id_cleanup_moderation_array( $arr ) {
		$clean = array();
		foreach ( $arr as $val ) {
			$val = trim( $val );
			if ( !$val )
				continue;
			if ( !in_array( $val, $clean ) )
				$clean[] = $val;
		}
		return $clean;
	}

// ACTION: save_comment
// Enter a new comment in to the system
// http://localhost/wordpress/2.5/?id_action=save_comment
	
	function id_REST_save_comment() {
		$rawComment = stripslashes( id_param( 'id_comment_data' ) );
		id_debug_log( "Receive Comment: $rawComment" );
		$comment = new id_comment();
		$comment->loadFromRemoteJson( $rawComment );
		return array(
			'success' => $comment->save(),
			'comment' => $comment->export()
		);
	}

	
// ACTION: set_comment_status
// http://wordpress.dev/2.6/?id_token=e6d73f80d00b2c7801d166daa64d43df&id_action=set_comment_status&comment_id=12&status=hold
// http://localhost/wordpress/2.5/?id_action=set_comment_status&comment_id=123&status=[hold|approve|spam|delete]
// ***Deleting is apparently done by passing status=delete

	function id_REST_set_comment_status() {
		$newStatus = id_param( 'status', '' );
		$comment_id = id_param( 'comment_id', 0 );
		
		id_debug_log( "Receive Comment Status: $newStatus $comment_id" );
		
		// Check if the status is already set, if so, still return true
		if ( $newStatus == wp_get_comment_status( $comment_id ) )
			return true;
		else if ( $newStatus == "delete" && wp_get_comment_status( $comment_id ) == "deleted" ) //handle cases that don't quite line up (delete=deleted and hold=unapproved)
			return true;
		else if ( $newStatus == "hold" && wp_get_comment_status( $comment_id ) == "unapproved" ) 
			return true;
		
		// If not already set, then attempt to set it and return the result
		remove_action( 'wp_set_comment_status', 'id_comment_status', 10, 2 );
		$result = wp_set_comment_status( $comment_id, $newStatus );
		add_action( 'wp_set_comment_status', 'id_comment_status', 10, 2 );
		return $result;
	}
	
	
// ACTION: save_post

	function id_REST_save_post() {
		$rawPost = stripslashes( id_param( 'id_post_data' ) );
		id_debug_log( "Receive Post Status: $rawPost" );
		$post = new id_post();
		$post->loadFromRemoteJson( $rawPost );
		return $post->save();
	}
	
	
// ACTION: reset queue

	function id_REST_reset_queue() {
		$queue = id_get_queue();
		$queue->create();
		return true;
	}
	
	
// ACTION: restart import

	function id_REST_reset_import() {
		id_save_option( 'id_import_comment_id', id_get_latest_comment_id() );
		return true;
	}
	
	function id_REST_get_last_wp_comment_id() {
		return id_get_latest_comment_id();
	}


// AUTOLOGIN
	
	// drops autologin js after user has logged in via profile page, makes it so
	// user does not need to login to IntenseDebate if they've already logged in here
	function id_auto_login() {
		global $userdata;
		$wp_userID = $userdata->ID;
		if ( !$wp_userID || get_option( 'id_auto_login' ) == 1 )
			return false;
			
		$appKey = ID_APPKEY;
		$userID = get_usermeta( $wp_userID, 'id_userID' );
		$userKey = get_usermeta( $wp_userID, 'id_userKey' );
		if ( id_user_connected() ) {
			echo "<script type=\"text/javascript\" src=\"" . ID_BASEURL . "/services/v1/jsLogin.php?appKey={$appKey}&amp;userid={$userID}&amp;userKey={$userKey}\"></script>\n";
		}
	}
	
	
// ADMIN BANNERS
	
	// displays prompt to login on the admin pages if user has not logged into IntenseDebate
	function id_admin_notices() {
		// global administrative settings prompt
		if ( !id_is_active() && $_GET['page'] != 'id_settings' ) {
			$settingsurl = get_bloginfo( 'wpurl' ) . '/wp-admin/options-general.php?page=id_settings';
			?>
			<div class="updated fade-ff0000">
				<p><strong><?php printf( __( 'The IntenseDebate plugin is enabled but you need to adjust <a href="%s">your settings</a>.', 'intensedebate' ), $settingsurl ); ?></strong></p>
			</div>
			<?php
			return;
		}
		
		// user profile settings prompt
		if ( !id_user_connected() && $_GET['page'] != 'id_settings' && $_GET['page'] != 'id_registration' ) {
			$profileurl = get_bloginfo( 'wpurl' ) . '/wp-admin/profile.php#intensedebatelogin';
			?>
			<div class="updated fade-ff0000">
				<p><strong><?php _e( 'Connect to your IntenseDebate account. Go to your <a href="' . $profileurl . '">profile</a> to log in or register.', 'intensedebate' ); ?></strong></p>
			</div>
			<?php
			return;
		}
		
	}
	
	function id_wordpress_version_warning() {
		?>
		<div class="updated fade-ff0000">
			<p><strong><?php printf( __("We're sorry, but the IntenseDebate plugin is not supported for versions of
			WordPress lower than %s.", 'intensedebate' ), ID_MIN_WP_VERSION ); ?></strong></p>
		</div>
		<?php
	}
	
	
// PROFILE PAGE
	
	// multiple panel display on user profile, trying to avoid this
	// delete if Jon accepts the "user registration link" solution instead
	function _id_show_user_profile() {

		global $userdata;
		$id_username = id_coalesce( $userdata->id_username );
		$id_email = id_coalesce( $userdata->id_email );
		$id_displayname = id_coalesce( $userdata->id_displayname );

		if ( version_compare( get_bloginfo( 'version' ), '2.5', '<' ) ) {
			// slightly different layout in older versions
			?>
			<fieldset>
				<legend><a name="intensedebatelogin"><?php _e( 'IntenseDebate User Login', 'intensedebate' ); ?></a></legend>
				<p>
					<label for="id_username">
						<?php _e( 'IntenseDebate Login', 'intensedebate' ); ?><br/>
						<input type="text" id="id_username" name="id_username" value="<?php echo $id_username; ?>" />
					</label>
				</p>
				<p>
					<label for="id_password">
						<?php _e( 'Password/User Key', 'intensedebate' ); ?><br/>
						<input type="password" id="id_password" name="id_password" value="" />
					</label>
				</p>
				<p>
					<a href='#useOpenID' onclick='document.getElementById("useOpenID").style.display="block";'><img src="<?php echo ID_BASEURL ?>/images/icon-openid.png" /> Signed up with OpenID? </a>
				</p>
				<p style="display:none" id="useOpenID">
					Unfortunately IntenseDebate and WordPress account syncing with OpenID is currently not directly available.  Please use your IntenseDebate username and user key to sync your account.  You can obtain your username and user key <a href="<?php echo ID_BASEURL ?>/userkey" target="_blank">here</a>.
				</p>
			</fieldset>
			<?php			
		} else {
			?>
			
			<div id="id_settings">
			
				<h2><a name="intensedebatelogin"><?php _e( 'IntenseDebate Settings', 'intensedebate' ); ?></a></h2>
				<span style="display: block; clear: both;"></span>
				
				<ol id="id_settings_menu">
					<li><a href="#id_user_login"><?php _e( 'I already have an account', 'intensedebate' ); ?></a></li>
					<li><a href="#id_user_registration"><?php _e( "I'm a new user", 'intensedebate' ); ?></a></li>
				</ol>
				
				<table class="form-table hidden" id="id_user_login">
					<tbody>
						<tr>
							<th><label for="id_login_username"><?php _e( 'Username', 'intensedebate' ); ?></label></th>
							<td><input type="text" id="id_login_username" name="id_login_username" value="<?php echo $id_username; ?>" /></td>
						</tr>
						<tr>
							<th><label for="id_login_password"><?php _e( 'Password/User Key', 'intensedebate' ); ?></label></th>
							<td><input type="password" id="id_login_password" class="required" name="id_login_password" value="" /><a style="text-decoration: none" href='#useOpenID' onclick='document.getElementById("useOpenID").style.display="block";'><img style="padding-left:5px; padding-right:2px" src="<?php echo ID_BASEURL ?>/images/icon-openid.png" /> Signed up with OpenID? </a></td>
						</tr>
						<tr>
							<td>								
							</td>
							<td >
								<span style="display:none" id="useOpenID">Unfortunately IntenseDebate and WordPress account syncing with OpenID is currently not directly available.  Please use your IntenseDebate username and user key to sync your account.  You can obtain your username and user key <a href="<?php echo ID_BASEURL ?>/userkey" target="_blank">here</a>.</span>
							</td>
						</tr>
					</tbody>
				</table>

				<div id="id_user_registration">
					<?php _e( 'Please signup at', 'intensedebate' ); ?> <a href="<?php echo ID_REGISTRATION_PAGE; ?>">IntenseDebate.com</a> <?php _e( 'and then select the "I already have an account" option instead.', 'intensedebate' ); ?>
				</div>			
			</div>
			<?php
		}
	}
	
	
	function id_show_user_profile() {

		if ( id_user_connected() ) {
			id_show_user_disconnect();
			return;
		}

		global $userdata;
		$id_username = id_coalesce( $userdata->id_username );

		if ( version_compare( get_bloginfo('version'), '2.5', '<' ) ) {
			// slightly different layout in older versions
			?>
			<fieldset id="intensedebatelogin">
				<legend><?php _e( 'IntenseDebate Account', 'intensedebate' ); ?></legend>
				<p>
					<label for="id_username">
						<?php _e( 'IntenseDebate Login', 'intensedebate' ); ?><br/>
						<input type="text" id="id_username" name="id_username" value="<?php echo($id_username); ?>" />
					</label>
				</p>
				<p>
					<label for="id_password">
						<?php _e( 'IntenseDebate Password', 'intensedebate' ); ?><br/>
						<input type="password" id="id_password" name="id_password" value="" />
					</label>
				</p>
			</fieldset>
			<p><?php _e( 'Not registered with IntenseDebate yet? <a target="_blank" href="' . ID_REGISTRATION_PAGE . '">It\'s easy.', 'intensedebate' ); ?></a></p>
			<?php
		} else {
			?>
			<a name="intensedebatelogin">&nbsp;</a><br/>
			<h2><img src="<?php echo ID_BASEURL ?>/images/intensedebate.png" alt="IntenseDebate Logo" class="idwp-logo" /> <?php _e( 'User Synchronization', 'intensedebate' ); ?></h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th><label for="id_username"><?php _e( 'Username', 'intensedebate' ); ?></label></th>
						<td><input type="text" id="id_username" name="id_username" value="<?php echo $id_username; ?>" /></td>
					</tr>
					<tr>
						<th><label for="id_password"><?php _e( 'Password', 'intensedebate' ); ?></label></th>
						<td><input type="password" id="id_password" class="required" name="id_password" value="" /></td>
					</tr>
				</tbody>
			</table>
			<p><?php _e( 'Not registered with IntenseDebate yet? <a target="_blank" href="' . ID_REGISTRATION_PAGE . '">It\'s easy, sign up now.</a>', 'intensedebate' ); ?></p>
			<?php
		}
	}
		
	function id_profile_update( $wp_userID = 0 ) {
		// validation
		if ( !$wp_userID ) {
			return false;
		}
		
		// Don't clear credentials when they were just being displayed
		if ( 'true' == id_param( 'id_connected' ) )
			return;
		
		$username = id_param( 'id_username' );
		$password = id_param( 'id_password' );
		if ( !$username || !$password ) {
			id_save_usermeta_array( $wp_userID, array(
				'id_username' => $username,
				'id_userID' => null,
				'id_userKey' => null
			) );
			return false;
		}

		// outgoing fields
		$fields = array();
		$fields['username'] = $username;
		$fields['password'] = $password;
		$fields['wp_userID'] = $wp_userID;
		$fields['admin'] = current_user_can( 'manage_options' );

		$queue = id_get_queue();
		$op = $queue->add( 'user_login', $fields, 'id_profile_update_callback' );
		$queue->ping( array( $op ) );

		return true;
	}
	
	function id_profile_update_callback( &$result, &$response, &$operation ) {
		$args = func_get_args();
		if ( $wp_userID = id_coalesce( @$operation->data['wp_userID'] ) ) {
			id_save_usermeta_array( $wp_userID, array(
				'id_username' => id_coalesce( $operation->data['username'] ),
				'id_userID' => id_coalesce( $response->userID ),
				'id_userKey' => id_coalesce( $response->userKey )
			) );
		}
		return true;
	}

	// user disconnect form
	function id_show_user_disconnect() {
		$current_user = wp_get_current_user();
		$user_ID = $current_user->ID;
		?>
				<a name="intensedebatelogin">&nbsp;</a><br/>
				<input type="hidden" name="id_connected" value="true" />
				<h2><img src="<?php echo ID_BASEURL ?>/images/intensedebate.png" alt="IntenseDebate Logo" class="idwp-logo" /> <?php _e( 'User Synchronization', 'intensedebate' ); ?></h2>

	            <table class="form-table">
					<tbody>
						<tr>
							<td colspan="2">
	                        	<img src="<?php echo ID_BASEURL ?>/midimages/<?php echo get_usermeta( $user_ID, 'id_userID' ); ?>" alt="[Avatar]" class="idwp-avatar" />
	                            <h3 class="idwp-floatnone"><?php printf( __( 'Synchronizing as %s', 'intensedebate' ), '<a href="' . ID_BASEURL . '/people/' . get_usermeta( $user_ID, 'id_username' ).'">' . get_usermeta( $user_ID, 'id_username' ) . '</a>'); ?></h3>
	                            <p class="idwp-floatnone"><a href="<?php echo ID_BASEURL ?>/userDash"><?php _e( 'Dashboard', 'intensedebate' ); ?></a> | <a href="<?php echo ID_BASEURL ?>/editprofile"><?php _e( 'Edit profile', 'intensedebate' ); ?></a></p>
	                            <p><a href="?id_settings_action=user_disconnect" id="id_user_disconnect"><?php _e( 'Disconnect from IntenseDebate', 'intensedebate' ) ?></a></p>
	                            <span class="idwp-clear"></span>
	                            <p class="idwp-nomargin"><?php _e( 'All WordPress comments are now synchronized with the IntenseDebate account above. <a href="' . ID_BASEURL . '/wordpress#userSync">Read more here</a>.', 'intensedebate' ); ?></p>
								<p></p>
	                        </td>
	                    </tr>
	               	</tbody>
	           	</table>				
		<?php
	}

	// user disconnect postback
	function id_SETTINGS_user_disconnect() {
		$current_user = wp_get_current_user();
		$user_id = $current_user->ID;

		$fields = array(
			'userKey' => get_option( 'id_userKey' ),
			'userid' => get_option( 'id_userID' ),
		);

		$queue = id_get_queue();
		$op = $queue->add( 'user_disconnect', $fields, 'id_generic_callback' );
		$queue->ping( array( $op ) );
		
		$meta = array(
			'id_username'
			, 'id_displayname'
			, 'id_email'
			, 'id_userID'
			, 'id_userKey'
		);
		foreach ( $meta as $key ) {
			id_save_usermeta( $user_id, $key, null );
		}
	}
	

// DISCUSSION SETTINGS PAGE
	/**
	 * When discussion settings are changed in WP, queue the change over to ID
	 * as well to keep things in sync.
	**/
	function id_discussion_settings_page( $merge_moderation_strings = false ) {
		if ( ( isset( $_POST['option_page'] ) && 'discussion' == $_POST['option_page'] ) || ( isset( $_POST['page_options'] ) && stristr( $_POST['page_options'], 'comment_moderation' ) ) ) {
			$settings = array();
			
			// We only sync to ID if one of the relevant options was changed
			$sync = false;
			$options = array( 'comments_notify', 'moderation_notify', 'comment_moderation', 'comment_whitelist', 'comment_max_links', 'moderation_keys', 'blacklist_keys', 'thread_comments' );
			foreach ( $options as $option ) {
				$current = get_option( $option );
				if ( ( isset( $_POST[$option] ) && $_POST[$option] != $current ) || !isset( $_POST[$option] ) && $current ) {
					$sync = true;
					break;
				}
			}
		
			if ( $sync ) {
				// Simple boolean options
				$settings = array(
									'all_comments_require_approval' => ( '1' == $_POST['comment_moderation'] ? 'T' : 'F' ),
									'require_previously_approved'   => ( '1' == $_POST['comment_whitelist']  ? 'T' : 'F' ),
									'email_new_comments'            => ( '1' == $_POST['comments_notify']    ? 'T' : 'F' ),
									'email_requires_moderation'     => ( '1' == $_POST['moderation_notify']  ? 'T' : 'F' ),
									'show_threads'                  => ( '1' == $_POST['thread_comments']    ? 'T' : 'F' ),
									'min_links_for_moderations'     => $_POST['comment_max_links'],
								);  
		
				// Some custom ones
				// If Akismet is active here, then send the key so that ID can use it as well
				if ( get_option( 'wordpress_api_key' ) && is_plugin_active( 'akismet/akismet.php' ) )
					$settings['akismet'] = get_option( 'wordpress_api_key' );
		
				// Need to do some parsing to get moderation strings into the same format as ID
				$mods = id_separate_tokens( $_POST['moderation_keys'] );
				$settings['moderate_words']     = implode( ' ', $mods['words'] );
				$settings['moderate_ips']       = implode( ' ', $mods['ips'] );
				$settings['moderate_emails']    = implode( ' ', $mods['emails'] );
		
				$blacklist = id_separate_tokens( $_POST['blacklist_keys'] );
				$settings['blacklisted_words']  = implode( ' ', $blacklist['words'] );
				$settings['blacklisted_ips']    = implode( ' ', $blacklist['ips'] );
				$settings['blacklisted_emails'] = implode( ' ', $blacklist['emails'] );
				
				// Optionally merge (rather than overwrite) ID moderation strings
				$settings['merge_moderation_strings'] = $merge_moderation_strings;
			
				id_discussion_sync_now( $settings );
			}
		}
	}
	
	/**
	 * Helper function to trigger an update of IntenseDebate options, based
	 * on what's stored in the database here (WP).
	**/
	function id_discussion_sync_from_db( $merge_moderation_strings = false ) {
		$settings = array(
							'all_comments_require_approval' => ( '1' == get_option( 'comment_moderation' ) ? 'T' : 'F' ),
							'require_previously_approved'   => ( '1' == get_option( 'comment_whitelist' )  ? 'T' : 'F' ),
							'email_new_comments'            => ( '1' == get_option( 'comments_notify' )    ? 'T' : 'F' ),
							'email_requires_moderation'     => ( '1' == get_option( 'moderation_notify' )  ? 'T' : 'F' ),
							'show_threads'                  => ( '1' == get_option( 'thread_comments' )    ? 'T' : 'F' ),
							'min_links_for_moderations'     => get_option( 'comment_max_links' ),
						);

		// Some custom ones
		// If Akismet is active here, then send the key so that ID can use it as well
		include_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		if ( get_option( 'wordpress_api_key' ) && is_plugin_active( 'akismet/akismet.php' ) )
			$settings['akismet'] = get_option( 'wordpress_api_key' );

		// Need to do some parsing to get moderation strings into the same format as ID
		$mods = id_separate_tokens( get_option( 'moderation_keys' ) );
		$settings['moderate_words']     = implode( ' ', $mods['words'] );
		$settings['moderate_ips']       = implode( ' ', $mods['ips'] );
		$settings['moderate_emails']    = implode( ' ', $mods['emails'] );

		$blacklist = id_separate_tokens( get_option( 'blacklist_keys' ) );
		$settings['blacklisted_words']  = implode( ' ', $blacklist['words'] );
		$settings['blacklisted_ips']    = implode( ' ', $blacklist['ips'] );
		$settings['blacklisted_emails'] = implode( ' ', $blacklist['emails'] );
		
		// Optionally merge (rather than overwrite) ID moderation strings
		$settings['merge_moderation_strings'] = ('1' == $merge_moderation_strings ? 1 : 0 );
		
		id_discussion_sync_now( $settings );
	}
	
	/**
	 * Send the array of options over to IntenseDebate to update the settings
	 * there to match here (WP)
	 * 
	 * @param array $settings The moderation/discussion settings to sync back to ID
	**/
	function id_discussion_sync_now( $settings ) {
		if ( is_array( $settings ) ) {
			$queue = id_get_queue();
			$op = $queue->add( 'moderation_settings', $settings, 'id_discussion_options_update_callback' );
			$queue->ping( array( $op ) );
		}
	}

	/**
	 * Very basic separation of moderation tokens into Email addresses, IPs
	 * and normal strings. Required because ID stores them separately.
	 * 
	 * @param string $str The string containing all moderation tokens
	 * @return Array containing all tokens, split into an associative array,
	 *         keyed with "emails", "words" and "ips"
	**/
	function id_separate_tokens( $str ) {
		$out = array( 'emails' => array(), 'words' => array(), 'ips' => array() );
		if ( !strlen( $str ) )
			return $out;
		
		$str = preg_replace( '/\s+/', ' ', $str );
		$tokens = explode( ' ', $str );
		foreach ( $tokens as $token ) {
			if ( false !== strstr( $token, '@' ) && false !== strstr( $token, '.' ) )
				$out['emails'][] = trim( $token );
			else if ( preg_match( '/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $token ) )
				$out['ips'][] = trim( $token );
			else
				$out['words'][] = trim( $token );
		}
	
		return $out;
	}
	
	/**
	 * Handle response from ID when a request to update moderation settings is made.
	 * 
	 * @param string $result The result string returned from ID ('success' or 'failure')
	 * @param object $response Data returned from ID
	 * @param object $operation The queue operation object sent to ID
	 * @return Boolean, true to remove from queue, false to try again later
	**/
	function id_discussion_options_update_callback( &$result, &$response, &$operation ) {
		if ( 'failure' == $result )
			return false; // Try again later
		else
			return true; // Remove from queue
	}

	
// SETTINGS PAGE
	
	// js/css for settings page
	// form validation doesn't work in older versions of wordpress due to jQuery version conflicts
	function id_settings_head() {
		echo '<link rel="stylesheet" href="' . get_bloginfo( 'wpurl' ) . '/index.php?id_inc=settings_css" type="text/css" />';
	}
		
	// checks to see if this blog already exists, stores blogID and blogAcct if found
	function id_blog_detection() {
		$fields = array( 'url' => get_option( 'siteurl' ) );
		$json = id_remote_api_call( ID_BLOG_LOOKUP_SERVICE, $fields );
		$j = id_get_json_service();
		$o = $j->decode( $json );
		if ( $o->resultCount == 1 ) {
			$row = $o->data[0];
			id_save_option( 'id_blogID', $row->id );
			id_save_option( 'id_blogAcct', $row->acct );
		}
	}
	
	// main settings page handler
	function id_settings_page() {
		// errors & alerts
		id_message();
		
		// Restart the process of connecting if we don't have a blogKey yet
		if ( !get_option( 'id_blogKey' ) )
			id_save_option( 'id_signup_step', 0 );
		?>
		<div id="id_settings" class="wrap">		
			<div class="clear"></div>
			<h2><?php if ( strlen( get_option( 'id_blogID' ) > 0 ) && get_option( 'id_signup_step' ) >= 3 ) { ?>
				<span class="idwp-logo-more"><strong><?php _e( 'Note', 'intensedebate' ); ?>:</strong> <?php _e( 'For more customization options please visit your', 'intensedebate' ); ?> <a href="<?php echo ID_BASEURL ?>/editacct/<?php echo get_option( 'id_blogID' ); ?>"><?php _e( 'blog settings', 'intensedebate' ); ?></a> <?php _e( 'page', 'intensedebate' ); ?></span><?php } ?><img src="<?php echo ID_BASEURL ?>/images/intensedebate.png" alt="IntenseDebate" class="idwp-logo" /> <?php _e('Settings', 'intensedebate'); ?></h2>
			<?php
				if ( id_param( 'login_msg' ) && id_param( 'login_msg' ) == "Login successful" )
					id_save_option( 'id_signup_step', 1 );
				else if ( id_param( 'new_status' ) && id_param( 'new_status' ) == "importcomplete" )
					id_save_option( 'id_signup_step', 3 );
				else if ( id_param( 'hideSettingsTop' ) && id_param( 'hideSettingsTop' ) == "true" )
					id_save_option( 'id_hideSettingsTop', 1 );

				if ( !id_is_active() || get_option( 'id_hideSettingsTop' ) == 0) : ?>				
				<style type="text/css">
/* 	!Install */
.idwp-install h3 {
	display: block !important;
	float: none !important;
	clear: none !important;
	font-size: 15px;
}
.idwp-install h4 {
	font-size: 13px;
}
.idwp-install {
	background: #dfdfdf url(<?php echo ID_BASEURL ?>/images1/_wordpress/gray-grad.png);
	border: 1px solid #dfdfdf;
	margin: 0 0 20px;
	padding: 14px;
	/* Rounded corners in most browsers! */
	-moz-border-radius: 4px; /* For Mozilla Firefox */
	-khtml-border-radius: 4px; /* For Konqueror */
	-webkit-border-radius: 4px; /* For Safari */
	border-radius: 4px; /* For future native implementations */
}
.idwp-install-logo {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat 100% 0;
	display: inline;
	float: right;
	margin: -14px -14px 0 0;
	height: 51px;
	width: 252px;
}
/* * html .idwp-install-logo {
	margin-right: 0;
}*/

/* Steps */
.idwp-install-steps {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat 0 25px;
	cursor: default;
	/*float: left;*/
	height: 45px;
	width: 189px;
	margin: 0;
	padding: 0;
}
.idwp-install-steps li {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat 24px -132px;
	color: #464646;
	float: left;
	height: 45px;
	list-style: none;
	margin: 0;
	text-align: center;
	width: 63px;
}
.idwp-install-steps .idwp-sel {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat 24px -222px;
	font-weight: bold;
}
.idwp-install-steps .idwp-completed {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat 24px -43px;
	color: #999;
}

/* Main */
.idwp-install-main {
	background: #fff;
	/*clear: left;*/
	padding: 18px;
	/* Rounded corners in most browsers! */
	-moz-border-radius: 2px; /* For Mozilla Firefox */
	-khtml-border-radius: 2px; /* For Konqueror */
	-webkit-border-radius: 2px; /* For Safari */
	border-radius: 2px; /* For future native implementations */
}
.idwp-install-main form h4 {
	clear: none;
	float: left;
	line-height: 28px;
	margin: 0;
	width: 160px;
}
.idwp-input-text-wrap {
	margin: 0 0 10px 160px;
}
.idwp-install-main .idwp-fade {
	margin: 4px 0 1em;
}
.idwp-install-form_elements {
	margin: 20px 0;
}

/* message_error */
.idwp-message_error {
	background: #fcc;
	padding: 5px;
	/* Rounded corners in most browsers! */
	-moz-border-radius: 2px; /* For Mozilla Firefox */
	-khtml-border-radius: 2px; /* For Konqueror */
	-webkit-border-radius: 2px; /* For Safari */
	border-radius: 2px; /* For future native implementations */
}
.idwp-message_error-symbol {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat -131px -133px;
	display: inline-block;
	float: left;
	margin: 0 6px 0 0;
	height: 17px;
	width: 17px;
}

/* Import status */
.idwp-install-importstatus {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat -131px -163px;
	cursor: default;
	display: inline-block;
	float: left;
	margin: 0 0 2px;
}
.idwp-install-importstatus .idwp-install-importstatus-inner {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat 100% -163px;
	font-size: 13px;
	line-height: 28px;
	margin: 0 0 0 12px;
	padding: 0 12px 0 0;
}
.idwp-install-importstatus-inner strong {
	margin: 0 12px 0 0;
}

.idwp-install-importstatus-info {
	clear: left;
	font-size: 11px;
	padding: 0 0 0 12px;
}

.idwp-install-loading_indicator {
	margin: 6px 0 0 6px;
}

/* Import complete! */
.idwp-success {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat -131px -201px;
	line-height: 38px;
	margin-top: 0;
	padding: 0 0 0 45px;
}

/* 	!idwp-list-arrows */
.idwp-list-arrows {
}
.idwp-list-arrows li {
	background: url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat -532px -248px;
	line-height: 18px;
	padding: 0 0 0 25px;
	list-style: none;
}

/* !WP-style big buttons */
.idwp-bigbutton {
	background: #f2f2f2 url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat -133px -63px;
	font-family: "Lucida Grande", Verdana, Arial, "Bitstream Vera Sans", sans-serif;
	text-decoration: none;
	font-size: 14px !important;
	line-height: 16px;
	padding: 6px 12px;
	cursor: pointer;
	border: 1px solid #bbb;
	color: #464646;
	-moz-border-radius: 15px;
	-khtml-border-radius: 15px;
	-webkit-border-radius: 15px;
	border-radius: 15px;
	-moz-box-sizing: content-box;
	-webkit-box-sizing: content-box;
	-khtml-box-sizing: content-box;
	box-sizing: content-box;
}
.idwp-bigbutton:hover {
	color: #000;
	border-color: #666;
}
.idwp-bigbutton:active {
	background: #eee url(<?php echo ID_BASEURL ?>/images1/_wordpress/idwp.png) no-repeat -133px -93px;
}

/* ID WP Plugin Special Classes */
.idwp-secondary {
	color: #999;
	font-size: 11px;
	line-height: 33px;
	margin: 0 0 0 10px;
}
.idwp-shortline {
	padding: 0 45% 0 0;
}
.idwp-fade {
	color: #999;
}
.idwp-nomargin {
	margin: 0 !important;
}
.idwp-clear {
	clear: both;
	display: block;
}
				</style>
				<div class="idwp-install" style="display: block;">
					<div class="idwp-install-logo"></div>
					<ul class="idwp-install-steps">
						<li class="<?php if ( get_option( 'id_signup_step' ) == 0 ) echo 'idwp-sel'; else if ( get_option( 'id_signup_step' ) > 0 ) echo 'idwp-completed'; ?>">
							<?php _e( 'Login', 'intensedebate' ); ?>
						</li>
						<li class="<?php if ( get_option( 'id_signup_step' ) == 1 || get_option( 'id_signup_step' ) == 2 ) echo 'idwp-sel'; else if ( get_option( 'id_signup_step' ) > 2 ) echo 'idwp-completed'; ?>">
							<?php _e( 'Import', 'intensedebate' ); ?>
						</li>
						<li class="<?php if ( get_option( 'id_signup_step' ) == 3 ) echo 'idwp-sel'; else if ( get_option( 'id_signup_step' ) > 3 ) echo 'idwp-completed'; ?>">
							<?php _e( 'Tweak', 'intensedebate' ); ?>
						</li>
					</ul>
					<div class="idwp-install-main">
						<?php if ( get_option( 'id_signup_step' ) == 0 ) : // first step (login/signup) ?>
							<h3 class="idwp-nomargin"><?php _e( 'Please login to your IntenseDebate account', 'intensedebate' ); ?></h3>
							<p style="margin-top: 4px;"><?php _e( "Don't have an account?", 'intensedebate' ); ?> <a href="<?php echo ID_BASEURL ?>/signup" target="_blank"><?php _e( 'Sign up here', 'intensedebate' ); ?></a>. </p>
							<p <?php if ( !id_param( 'login_msg' ) ) echo 'style="display:none"'; ?> class="idwp-message_error"><span class="idwp-message_error-symbol"></span> Login failed. Please check your credentials and try again.</p>
							<?php $username = id_param( 'username' ); ?>
							<form id="id_user_login" action="options-general.php?page=id_settings" method="POST">
								<input type="hidden" name="id_settings_action" value="user_login" />							
								<div class="idwp-install-form_elements form-table">
								    <h4><label for="txtEmail"><?php _e( 'Email/Username', 'intensedebate' ); ?></label></h4>
								    <div class="idwp-input-text-wrap">
								    	<input id="txtEmail" autocomplete="off" type="text" class="required regular-text" name="id_remote_fields[username]" value="<?php echo $username; ?>" />
								    	<p class="idwp-fade"><?php _e( 'The email address or username you use for your IntenseDebate.com account.', 'intensedebate' ); ?></p>
								    </div>
								    <h4><label for="txtPassword"><?php _e( 'Password/User Key', 'intensedebate' ); ?></label></h4>
								    <div class="idwp-input-text-wrap" style="margin-bottom: 20px;">
								        <input id="txtPassword" autocomplete="off" type="password" class="required regular-text" name="id_remote_fields[password]" value="" /><a href='#' style="text-decoration:none" onclick='document.getElementById("useOpenID").style.display="block";'><img style="padding-left: 5px; padding-right: 2px" src="<?php echo ID_BASEURL ?>/images/icon-openid.png" /> Signed up with OpenID? </a>
								        <p class="idwp-fade"><a href="<?php echo ID_BASEURL ?>/forgot" target="_blank"><?php _e( 'Forgot your IntenseDebate password?', 'intensedebate' ); ?></a></p>
								    </div>
								    <span style="display:none" id="useOpenID"><?php _e( 'Unfortunately IntenseDebate and WordPress account syncing with OpenID is currently not directly available.  Please use your IntenseDebate username and user key to sync your account.  You can obtain your username and user key', 'intensedebate' ); ?> <a href="<?php echo ID_BASEURL ?>/userkey" target="_blank"><?php _e( 'here', 'intensedebate' ); ?></a>.</span>
							    </div><!--/ idwp-install-form_elements -->
							    <input type="submit" value="<?php _e( 'Login to IntenseDebate', 'intensedebate' ); ?>" class="idwp-bigbutton" />
							    <p><strong><?php _e( 'Note:', 'intensedebate' ); ?></strong> <?php _e( "As is the case when installing any plugin, it's always a good idea to", 'intensedebate' ); ?> <a href='export.php' target="_blank"><?php _e( 'backup', 'intensedebate' ); ?></a> <?php _e( 'your blog data before proceeding.', 'intensedebate' ); ?></p>
						    </form>
						<?php elseif ( get_option( 'id_signup_step' ) == 1 ) : //second step (start import) ?>
							<h3 class="idwp-nomargin"><?php _e( 'Import your WordPress comments into IntenseDebate', 'intensedebate' ); ?></h3>
							<div class="idwp-shortline">				
								<p><strong><?php _e( 'Welcome', 'intensedebate' ); global $userdata; $id_username = id_coalesce( $userdata->id_username ); echo " $id_username!"; ?></strong> <?php _e( 'For your old WordPress comments to show up in the plugin, they need to be imported to give them all the IntenseDebate comment goodness.', 'intensedebate' ); ?> <a href="<?php echo ID_BASEURL ?>/wordpress#import" target="_blank">&raquo; <?php _e( 'Learn more', 'intensedebate' ); ?></a>.</p>
								<p><?php _e( "The process usually takes a few hours or less, but times may vary depending on how many comments you're importing. You'll be notified via email when the import is complete.", 'intensedebate' ); ?></p>
								<p><strong><?php _e( 'Note:', 'intensedebate' ); ?></strong> <?php _e( "Until your comments are imported they will not show up in the IntenseDebate comment system.  Don't worry though, your comments are still safe and will be ready as soon as the import completes.", 'intensedebate' ); ?></p>
							</div>
							<form id="id_user_login" action="options-general.php?page=id_settings" method="POST">
								<input type="hidden" name="id_settings_action" value="start_import" />
								<p><input type="checkbox" name="use_id_moderation_strings" id="use_id_moderation_strings" value="1" checked="checked" /> <label for="use_id_moderation_strings"><?php _e( "Use IntenseDebate's default moderation settings (recommended)" ); ?></label> <span style="cursor:pointer;" onclick="jQuery('#explain_id_moderation_strings').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></p>
								<p id="explain_id_moderation_strings" style="display: none;" class="idwp-shortline"><?php _e( "By enabling this option, IntenseDebate will add commonly-abused keywords and phrases to your moderation settings, so that you can avoid spam in your comments. You can always edit/delete these values later if you don't want them any more." ); ?></p>
								<input type="submit" value="<?php _e( 'Start Importing Comments', 'intensedebate' ); ?>" class="idwp-bigbutton" /> <a href="javascript: document.getElementById('id_skip_import').submit();" class="idwp-secondary"><?php _e( 'Skip Import', 'intensedebate' ); ?></a>
							</form>
							<form id="id_skip_import" action="options-general.php?page=id_settings" method="POST">
								<input type="hidden" name="id_settings_action" value="skip_import" />								
							</form>
						<?php elseif ( get_option( 'id_signup_step' ) == 2 ) : //third step (import in progress) ?>
							<h3 style="margin-top: 0;"><?php _e( 'Import in progress...', 'intensedebate' ); ?></h3>
							<p class="idwp-message_error" id="id_importError" style="display: none"><span class="idwp-message_error-symbol"></span> <?php _e( 'An importing error occured. Please', 'intensedebate' ); ?> <a href="<?php echo ID_BASEURL ?>/contactus"><?php _e( 'contact us', 'intensedebate' ); ?></a> <?php _e( 'to get help!', 'intensedebate' ); ?></p>
							<div class="idwp-install-importstatus" id="id_importStatus_wrapper">
								<div class="idwp-install-importstatus-inner" id="id_importStatus">
									<strong>0%</strong>
								</div>
							</div><img id='id_loadingImage' src="<?php echo ID_BASEURL ?>/images/ajax-loader.gif" alt="Loading..." class="idwp-install-loading_indicator" title="Importing comments..." />							
							<div class="idwp-shortline">
								<p><strong><?php _e( 'Please note:', 'intensedebate' ); ?></strong> <?php _e( "While comments are being imported you might notice some of your comments appear to be missing from the IntenseDebate comment system. Don't worry though, your comments will be back as soon as they are imported.", 'intensedebate' ); ?></p>
								<p class="idwp-nomargin"><?php _e( "The process usually takes a few hours or less, but times may vary depending on how many comments you're importing. Feel free to go about your business in the mean time. You'll be notified via email when the import is complete.", 'intensedebate' ); ?></p>
								<p style="display:none" id="id_restartLink"><?php printf( __( 'If you\'re experiencing importing problems you can try to <a href="%s/resetWPImport.php?acctid=%s&blogKey=%s">restart the import process</a> to see if that fixes it.', 'intensedebate' ), ID_BASEURL, get_option( "id_blogID" ), get_option( 'id_blogKey' ) ); ?></p>
							</div>
							<script type="text/javascript" src="<?php echo ID_BASEURL ?>/js/importStatus2.php?acctid=<?php echo get_option( "id_blogID" ); ?>&time=<?php echo time(); ?>"></script>
						<?php elseif ( get_option( 'id_signup_step' ) >= 3 ) : //fourth step (fine tune) ?>
							<h3 class="idwp-success"><?php _e( 'Success! IntenseDebate is now fully activated on your blog.', 'intensedebate' ); ?> <a href="<?php echo get_option( 'home' ); ?>" target="_blank">&raquo; <?php _e( 'View blog', 'intensedebate' ); ?></a></h3>
							<h4><?php _e( 'Here are a few other customization options you might want to check out:', 'intensedebate' ); ?></h4>
							<ul class="idwp-list-arrows">
								<li><a href="<?php echo ID_BASEURL ?>/editacct/<?php echo get_option( 'id_blogID' ); ?>" target="_blank"><?php _e( 'Edit your blog settings on IntenseDebate.com', 'intensedebate' ); ?></a></li>
								<li><a href="<?php echo ID_BASEURL ?>/bTheme/<?php echo get_option( 'id_blogID' ); ?>" target="_blank"><?php _e( 'Customize the comment layout', 'intensedebate' ); ?></a></li>
								<li><a href="<?php echo ID_BASEURL ?>/addOns" target="_blank"><?php _e( 'Grab some comment widgets for your blog.', 'intensedebate' ); ?></a></li>
							</ul>
							<form id="id_close_box" action="options-general.php?page=id_settings&hideSettingsTop=true" method="POST">
							</form>
							<p style="margin: 20px 0 0;"><a href="javascript: document.getElementById('id_close_box').submit();"><?php _e( 'Close this box', 'intensedebate' ); ?></a></p>
						<?php endif; ?>						
					</div><!--/ idwp-install-main -->
					<span class="idwp-clear"></span>
				</div><!--/ idwp-install -->
			<?php endif; ?>

				<?php if ( get_option( 'id_signup_step' ) >= 3 ) : ?>
				<!-- post-activation settings -->
				<div style="overflow:hidden;">
					<form id="id_manual_settings" class="ui-tabs-panel" action="options.php" method="post">
						<input type="hidden" name="action" value="update" />
						<input type="hidden" name="page_options" value="id_auto_login,id_moderationPage,id_useIDComments,id_jsCommentLinks,id_syncWPComments,id_syncWPPosts,id_revertMobile" />
						<?php wp_nonce_field( 'update-options' ); ?>
									
						<table class="form-table">
							<tbody>
								<tr valign="top">
									<th scope="row" style="white-space: nowrap;" ><?php _e( 'Comment Links', 'intensedebate' ); ?> <span style="cursor:pointer;" onclick="jQuery('#divCommentLinkInfo').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></th>
									<td>
										<input type="radio" name="id_jsCommentLinks" value="0" <?php if ( get_option( 'id_jsCommentLinks' ) == 0 ) echo "checked"; ?> id="id_jsCommentLinks_0"> <label for="id_jsCommentLinks_0"><?php _e( 'IntenseDebate Enhanced Comment Links', 'intensedebate' ); ?></label> (<a href="<?php echo ID_BASEURL ?>/editacct/<?php echo get_option('id_blogID'); ?>" target="_blank" title="Customize Comment Links"><?php _e( 'Customize Them', 'intensedebate' ); ?></a>)<br />
										<input type="radio" name="id_jsCommentLinks" value="1" <?php if ( get_option( 'id_jsCommentLinks' ) == 1 ) echo "checked"; ?> id="id_jsCommentLinks_1"> <label for="id_jsCommentLinks_1"><?php _e( 'Wordpress Standard Comment Links', 'intensedebate' ); ?></label>
										<span class="idwp-clear"></span>                            
										<p id="divCommentLinkInfo" class="hidden"><?php _e( 'Use customized comment link text by enabling IntenseDebate Enhanced Comment Links.  <a href="' . ID_BASEURL . '/faq#li181">Learn more</a> about customizing your comment links.', 'intensedebate' ); ?></p>
									</td>
								</tr>
								<tr valign="top">
									<th scope="row" style="white-space: nowrap;" ><?php _e( 'Moderation Page', 'intensedebate' ); ?> <span style="cursor:pointer;" onclick="jQuery('#divModPageInfo').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></th>
									<td>
										<input type="radio" name="id_moderationPage" value="0" <?php if ( get_option( 'id_moderationPage' ) == 0 ) echo "checked"; ?> id="id_moderationPage_0"> <label for="id_moderationPage_0"><?php _e( 'IntenseDebate Enhanced Moderation', 'intensedebate' ); ?></label> <br />
										<input type="radio" name="id_moderationPage" value="1" <?php if ( get_option( 'id_moderationPage' ) == 1 ) echo "checked"; ?> id="id_moderationPage_1"> <label for="id_moderationPage_1"><?php _e( 'Wordpress Standard Moderation', 'intensedebate' ); ?></label> 
										<span class="idwp-clear"></span>                            
										<p id="divModPageInfo" class="hidden"><?php _e( "Moderate and reply to IntenseDebate comments from your WordPress admin panel using our custom moderation page that mirrors the WordPress page that you're already used to.  The only difference is the extra IntenseDebate zest we've added by including IntenseDebate avatars, reputation points, profile links and all of our other metadata gravy that you'll love.", 'intensedebate' ); ?></p>
									</td>
								</tr>
								<tr valign="top">
									<th scope="row" style="white-space: nowrap;" ><?php _e( 'Comment System', 'intensedebate' ); ?> <span style="cursor:pointer;" onclick="jQuery('#divCommentSystemInfo').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></th>
									<td>
										<input type="radio" name="id_useIDComments" value="0" <?php if ( get_option( 'id_useIDComments' ) == 0 ) echo "checked"; ?> id="id_useIDComments_0"> <label for="id_useIDComments_0"><?php _e( 'IntenseDebate Enhanced Comments', 'intensedebate' ); ?></label> <br />
										<input type="radio" name="id_useIDComments" value="1" <?php if ( get_option( 'id_useIDComments' ) == 1 ) echo "checked"; ?> id="id_useIDComments_1"> <label for="id_useIDComments_1"><?php _e( 'Wordpress Standard Comments', 'intensedebate' ); ?></label> 				
										<span class="idwp-clear"></span>                            
										<p id="divCommentSystemInfo" class="hidden"><?php _e( 'By enabling WordPress Comments you can disable your IntenseDebate Comment system without deactivating the plugin.', 'intensedebate' ); ?></p>			
									</td>
								</tr>
								<tr valign="top">
									<th scope="row" style="white-space: nowrap;" ><?php _e('Sync WP Comments', 'intensedebate'); ?> <span style="cursor:pointer;" onclick="jQuery('#divCommentSyncInfo').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></th>
									<td>
										<input type="radio" name="id_syncWPComments" value="0" <?php if ( get_option( 'id_syncWPComments' ) == 0 ) echo "checked"; ?> id="id_syncWPComments_0"> <label for="id_syncWPComments_0"><?php _e( 'Sync that data back to IntenseDebate!', 'intensedebate' ); ?></label> <br />
										<input type="radio" name="id_syncWPComments" value="1" <?php if ( get_option( 'id_syncWPComments' ) == 1 ) echo "checked"; ?> id="id_syncWPComments_1"> <label for="id_syncWPComments_1"><?php _e( "Let's just sync one-way instead.", 'intensedebate' ); ?></label> 
										<span class="idwp-clear"></span>                            
										<p id="divCommentSyncInfo" class="hidden"><?php _e( 'IntenseDebate outputs the standard WordPress comments enabling your comments to still be indexed by search engines that ignore JavaScript, while ensuring that visitors surfing with JavaScript disabled will be able to interact with comments made in IntenseDebate.  Readers with JavaScript disabled can still comment in the original WordPress system.  Syncing your WordPress comments will import those comments into IntenseDebate, ensuring that every comment (and trackback/pingback) makes its way into the conversation.', 'intensedebate' ); ?></p>
									</td>
								</tr>
								<tr valign="top">
									<th scope="row" style="white-space: nowrap;" ><?php _e( 'Sync WP Post Data', 'intensedebate' ); ?> <span style="cursor:pointer;" onclick="jQuery('#divPostSyncInfo').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></th>
									<td>
										<input type="radio" name="id_syncWPPosts" value="0" <?php if ( get_option( 'id_syncWPPosts' ) == 0 ) echo "checked"; ?> id="id_syncWPPosts_0"> <label for="id_syncWPPosts_0"><?php _e( 'Sync that data back to IntenseDebate!', 'intensedebate' ); ?></label> <br />
										<input type="radio" name="id_syncWPPosts" value="1" <?php if ( get_option( 'id_syncWPPosts' ) == 1 ) echo "checked"; ?> id="id_syncWPPosts_1"> <label for="id_syncWPPosts_1"><?php _e( "Let's just sync one-way instead.", 'intensedebate' ); ?></label>
										<span class="idwp-clear"></span>                            
										<p id="divPostSyncInfo" class="hidden"><?php _e( 'WordPress admin settings like closing and opening comments on a post, and changing your post titles, will be automatically recognized and reflected in your IntenseDebate settings.', 'intensedebate' ); ?></p>
									</td>
								</tr>
								<tr valign="top">
									<th scope="row" style="white-space: nowrap;" ><?php _e( 'Auto Login', 'intensedebate' ); ?> <span style="cursor:pointer;" onclick="jQuery('#divAutoLoginInfo').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></th>
									<td>
										<input type="radio" name="id_auto_login" value="0" <?php if ( get_option( 'id_auto_login' ) == 0 ) echo "checked"; ?> id="id_auto_login_0"> <label for="id_auto_login_0"><?php _e( 'Automatically log me in to IntenseDebate when possible', 'intensedebate' ); ?></label> <br />
										<input type="radio" name="id_auto_login" value="1" <?php if ( get_option( 'id_auto_login' ) == 1 ) echo "checked"; ?> id="id_auto_login_1"> <label for="id_auto_login_1"><?php _e( "Don't automatically log me in to IntenseDebate", 'intensedebate' ); ?></label>
										<span class="idwp-clear"></span>                            
										<p id="divAutoLoginInfo" class="hidden"><?php _e( "This setting will determine if we attempt to log you (or any users that have synced a WordPress account to an IntenseDebate account) in to IntenseDebate automatically when you're signed into your WordPress account.  Note: this might not work in Safari if third party cookies are not enabled.", 'intensedebate' ); ?></p>
									</td>
								</tr>
								<tr valign="top">
									<th scope="row" style="white-space: nowrap;" ><?php _e( 'Comments for mobile devices', 'intensedebate' ); ?> <span style="cursor:pointer;" onclick="jQuery('#divRevertMobileInfo').slideToggle();"><img src="<?php echo ID_BASEURL ?>/images1/wp-info.png" /></span></th>
									<td>
										<input type="radio" name="id_revertMobile" value="0" <?php if ( get_option( 'id_revertMobile' ) == 0 ) echo "checked"; ?> id="id_revertMobile_0"> <label for="id_revertMobile_0"><?php _e( 'Revert to WordPress comments for visitors on mobile devices', 'intensedebate' ); ?></label> <br />
										<input type="radio" name="id_revertMobile" value="1" <?php if ( get_option( 'id_revertMobile' ) == 1 ) echo "checked"; ?> id="id_revertMobile_1"> <label for="id_revertMobile_1"><?php _e( 'Use IntenseDebate comments for visitors on mobile devices', 'intensedebate' ); ?></label>
										<span class="idwp-clear"></span>                            
										<p id="divRevertMobileInfo" class="hidden"><?php _e( 'This setting will determine if we show IntenseDebate comments or Wordpress comments when a reader on a mobile device visits your blog.  Because IntenseDebate is not yet fully compatible with all mobile devices, we suggest reverting to the standard WordPress comments when mobile devices access your blog.', 'intensedebate' ); ?></p>
									</td>
								</tr>									
							</tbody>
						</table>						
						
						<p class="submit">
							<input type="submit" name="Submit" value="<?php _e( 'Save Changes', 'intensedebate' ) ?>" class="button-primary" /> 
						</p>
						
					</form>
					<form id="id_plugin_reset" action="options-general.php?page=id_settings" method="POST">
						<input type="hidden" name="id_settings_action" value="settings_reset" />
						<p><?php _e( 'Use this button to completely reset the IntenseDebate plugin.', 'intensedebate' ); ?></p>
			
						<p class="submit" style="border: 0; padding: 0 0 10px;">
							<input type="submit" name="Submit" value="<?php _e( 'Reset IntenseDebate Plugin', 'intensedebate' ) ?>" />
						</p>
						
					</form>					
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
	
	// errors, etc at top of settings page
	function id_message() {
		if ( $msg = id_param( 'msg' ) ) {
			?>
			<div id="message" class="updated fade"><p><strong><?php _e( $msg, 'intensedebate' ) ?></strong></p></div>
			<?php
		}
	}

	// postback for settings page
	function id_process_settings_page() {
		$id_settings_action = 'id_SETTINGS_' . id_param( 'id_settings_action' );
		if ( $id_settings_action && function_exists( $id_settings_action ) )
			call_user_func( $id_settings_action );
	}
	
	function id_clear_blog_settings() {
		$settings = array(
			'id_blogAcct'
			, 'id_blogID'
			, 'id_blogKey'
			, 'id_import_comment_id'
			, 'id_import_post_id'
			, 'id_import_token'
			, 'id_request_queue'
			, 'id_userID'
			, 'id_userKey'
			, 'id_comment_template_file'
			, 'id_jsCommentLinks'
			, 'id_moderationPage'
			, 'id_request_queue'
			, 'id_syncWPComments'
			, 'id_syncWPPosts'
			, 'id_revertMobile'
			, 'id_useIDComments'
			, 'id_hideSettingsTop'
			, 'id_signup_step'
			, 'id_auto_login'
		);
		foreach ( $settings as $setting ) {
			delete_option( $setting );
		}
	}
	
	function id_SETTINGS_settings_reset() {	
		id_clear_blog_settings();
		
		global $wpdb;
		$users = $wpdb->get_results( "SELECT * FROM $wpdb->users" );
		$meta = array( 'id_username', 'id_userID', 'id_userKey' );
		foreach ( $users as $user ) {
			foreach ( $meta as $key ) {
				delete_usermeta( $user->ID, $key );
			}
		}

		// notify ID
		$fields = array();
		$queue = id_get_queue();
		$op = $queue->add( 'plugin_reset', $fields, 'id_generic_callback' );
		$queue->ping( array( $op ) );
	}
	
	// Skip import
	function id_SETTINGS_skip_import() {
		id_save_option( 'id_signup_step', 3 );
		
		$goback = remove_query_arg( 'updated', $_SERVER['REQUEST_URI'] );
		$goback = remove_query_arg( 'login_msg', $goback );
		wp_redirect( $goback );
	}
	
	// Start import
	function id_SETTINGS_start_import() {		
		id_REST_reset_import();
		
		// Send request to start importing comments
		$fields = array( "blog_id" => get_option( 'id_blogID' ), "blog_key" => get_option( 'id_blogKey' ) );
		$queue = id_get_queue();
		$queue->create();
		$op = $queue->add( 'start_import', $fields, 'id_process_start_import_callback' );
		$queue->ping( array( $op ) );
		$queue->create();
		
		// Trigger initial sync of moderation/discussion settings
		id_discussion_sync_from_db( id_param( 'use_id_moderation_strings') );
		
		// Go to the next step
		id_save_option( 'id_signup_step', 2 );
		$goback = remove_query_arg( 'updated', $_SERVER['REQUEST_URI'] );
		$goback = remove_query_arg( 'login_msg', $goback );
		wp_redirect( $goback );
	}
	
	
	function id_process_start_import_callback( &$result, &$response, &$operation ) {
	}
	
	// login form post-back
	function id_SETTINGS_user_login() {
		global $userdata;

		$goback = remove_query_arg( 'updated', $_SERVER['REQUEST_URI'] );
		$goback = remove_query_arg( 'login_msg', $goback );
		$messages = array();

		$fields = id_param( 'id_remote_fields', array() );
		$fields['admin'] = current_user_can( 'manage_options' );
		$fields['blog_url'] = get_option( 'siteurl' );
		$fields['blog_rss'] = get_bloginfo( 'rss_url' );
		$fields['blog_title'] = get_option( 'blogname' );
		$fields['blog_sitetype'] = "wordpress";
		$fields['rest_service'] = $fields['blog_url'] . '/index.php?id_action=import'; 
		$fields['token'] = id_generate_token( $fields );
		$fields['wp_userID'] = $userdata->ID;
		$fields['start_import'] = "false";
		
		foreach ( $fields as $n => $v ) {
			if ( !strlen( $v ) ) {
				$messages[] = 'Missing field: ' . $n;
			}
		}
			
		if ( !count( $messages ) ) {
			$queue = id_get_queue();	
			$queue->create();
			$op = $queue->add( 'user_login', $fields, 'id_process_user_login_callback' );
			$queue->ping( array( $op ) );
			$loginOperation = $queue->operations[0];
			$loginResponse = $loginOperation->response;
			$messages[] = id_coalesce( @$loginResponse->error_msg, "Login successful" );
		}
		
		if ( count( $messages ) ) {
			$msg = implode( '<br/>', $messages );
			$goback = add_query_arg( 'login_msg', urlencode( $msg ), $goback );
		} else {
			$goback = add_query_arg( 'updated', 'true', $goback );
		}
		wp_redirect( $goback );
	}


	// login api callback
	function id_process_user_login_callback( &$result, &$response, &$operation ) {
		global $userdata;
		
		$args = func_get_args();
		
		if (
			strtolower( $result ) == "success" 
			&& $response->userID
			&& $response->userKey 
			&& $response->blogID > 0
			&& $response->blogKey 
			&& $response->blogAcct != ''
		) {
			id_save_option( 'id_userID', $response->userID );
			id_save_option( 'id_userKey', $response->userKey );
			id_save_option( 'id_blogID', $response->blogID );
			id_save_option( 'id_blogKey', $response->blogKey );
			id_save_option( 'id_blogAcct', $response->blogAcct );

			
			//Save default options
			id_save_option( 'id_jsCommentLinks', 0 );
			id_save_option( 'id_moderationPage', 0 );
			id_save_option( 'id_useIDComments', 0 );
			id_save_option( 'id_syncWPComments', 0 );
			id_save_option( 'id_syncWPPosts', 0 );
			id_save_option( 'id_revertMobile', 0 );
			
			//Set to go to next step
			id_save_option( 'id_signup_step', 1 );
			
			id_save_usermeta_array( $userdata->ID, array(
				'id_userID' => $response->userID,
				'id_userKey' => $response->userKey,
				'id_username' => $response->username
			) );
			
			// password IntenseDebate uses to request imported comments
			id_save_option( 'id_import_token', $operation->data['token'] );

			// highest comment id
			id_save_option( 'id_import_comment_id', id_get_latest_comment_id() );

			return true;
		}
		
		return false;
	}
	
	// returns highest comment ID in wp database
	function id_get_latest_comment_id() {
		global $wpdb;
		return $wpdb->get_var( "SELECT MAX(comment_ID) FROM {$wpdb->comments}" );
	}

// COMMENT MODERATION PAGE
	
	function id_moderate_comments() {
		global $userdata;
		$wp_userID = $userdata->ID;
		
		$userID = get_usermeta( $wp_userID, 'id_userID' );
		$userKey = get_usermeta( $wp_userID, 'id_userKey' );
		
		$curSysTime = gmdate( "U" );
		?>
		
			<div class="wrap">
				<div id="intense_debate_news-wrap">
				</div>
				<?php if ( function_exists( 'screen_icon' ) ) screen_icon( 'edit-comments' ); ?>
				<h2><?php _e( 'Edit Comments', 'intensedebate' ); ?></h2>
				<iframe frameborder="0" id="id_iframe_moderation" src="<?php echo ID_COMMENT_MODERATION_PAGE . get_option( 'id_blogID' ) . "&userid=$userID&time=$curSysTime&authstr=" . md5( $userKey . $curSysTime ); ?>" style="width: 100%; height: 500px; border: none;" onload="addScript()" scrolling="auto"></iframe>
			</div>
		
		<script type="text/javascript" src="<?php echo ID_BASEURL ?>/wpPluginNews.php?acctid=<? echo get_option( 'id_blogID' ); ?>"></script>
		<script type="text/javascript">		
		jQuery('#adminmenu a[href=edit-comments.php]').addClass('current');				
		function addScript() {
			setTimeout("addScript2();", 100);
		}
		function addScript2() {
			var idScript = document.createElement("script");
			idScript.type = "text/javascript";
			idScript.src = "<?php echo ID_BASEURL ?>/js/updateWindowHeightForWPPlugin.php?acctid=<?php echo get_option( 'id_blogID' ); ?>";
			document.getElementsByTagName("head")[0].appendChild(idScript);
		}
		</script>
		<?php
	}
	
// CSS INCLUDES

	function id_INCLUDE_settings_css() {
		$charSet = get_bloginfo( 'charset' );
		header( "Content-type: text/css; charset={$charSet}" );
?>
		/* IntenseDebate Stylings
			v.00002
		 */

		#id_settings_menu {
			list-style: none;
			padding: 0;
			}
		#id_settings_menu li {
			background: #E4F2FD url(<?php echo ID_BASEURL ?>/images1/idwp-signup_arrow.png) no-repeat 8px 50%;
			display: block;
			padding: 8px 8px 8px 35px;
			width: 250px;
			}
		#id_user_login .form-table,
		#id_email_lookup .form-table,
		#id_user_registration .form-table {
			margin-top: 0;
			}
		#id_user_login .submit,
		#id_email_lookup .submit,
		#id_user_registration .submit {
			background: #EAF3FA;
			border: none;
			padding: 10px;
			}
		#id_user_login .form-table td,
		#id_email_lookup .form-table td,
		#id_user_registration .form-table td,
		#id_user_login .form-table th,
		#id_email_lookup .form-table th,
		#id_user_registration .form-table th {
			border: none;
			margin: 0;
			}
		#id_user_login .form-table th,
		#id_email_lookup .form-table th,
		#id_user_registration .form-table th {
			line-height: 25px;
			}
		.idwp-form_info {
			margin: .4em 0 0;
			}
		.idwp-form_info_fade {
			color: #666;
			margin: .2em 0 1em;
			}
		.idwp-logo {
			margin: 0 -4px 0 0;
			}
		.idwp-clear {
			clear: both;
			display: block;
			}
		.idwp-importstatus {
			float: left;
			font-size: 12px; line-height: 1.3em;
			margin: 0;
			outline: 3px solid #fff;
			padding: 4px;
			}
		.id_settings_menu {
			list-style: none;
			padding: 0;
			}
		.id_settings_menu li {
			background: #E4F2FD url(<?php echo ID_BASEURL ?>/images1/idwp-signup_arrow.png) no-repeat 8px 50%;
			display: block;
			padding: 8px 8px 8px 35px;
			width: 250px;
			}
		.idwp-popup {
			background: url(<?php echo ID_BASEURL ?>/images1/idwp-popup_bg.png);
			height: 100%;
			position: fixed;
			width: 100%;
			z-index: 100;
			}
		.idwp-popup-inner {
			color: #ccc;
			display: block;
			float: none;
			margin: 65px auto 0;
			width: 994px;
			}
		.idwp-popup-inner a {
			color: #ccc;
			}
		.idwp-popup-inner a:hover {
			color: #fff;
			}
		.idwp-popup-iframe {
			height: 480px;
			margin: 0 auto;
			width: 990px;
			}
		.idwp-close {
			background: url(<?php echo ID_BASEURL ?>/images1/idwp-close.png) no-repeat;
			display: block;
			float: right;
			height: 24px;
			margin: 0 0 0 8px;
			width: 24px;
			}
		a.idwp-floatright:hover .idwp-close, .idwp-close:hover {
			background-position: 0 100%;
			}
		.idwp-floatright {
			float: right;
			}
		.idwp-logo-more {
			display: inline-block;
			float: right;
			font-size: 15px;
			margin: 26px 0 0;
			}
		#id_settings h2 {
			padding-right: 0;
			}
		#intense_debate_news-wrap {
			float: right;
			display: inline-block;
			margin: 15px -260px 15px 0;
			padding: 0 0 0 10px;
			position: relative; /* IE6 */
			}
		#intense_debate_news {
			background: #E4F2FD;
			display: inline-block;
			float: left;
			padding: 12px 12px 2px;
			width: 220px;
			}
		#intense_debate_news h3 {
			margin: 0 0 .5em;
			}
		#intense_debate_news h4 {
			margin: 0 0 .5em;
			}
		#intense_debate_news p {
			margin: 0 0 1em;
			}
		#intense_debate_news .id_news_list {
			margin: 1em 0;
			padding: 0 0 0 15px;
			}
		#intense_debate_news .id_news_list li {
			margin: 0 0 1em;
			}
		#intense_debate_news .id_news_toggle {
			display: inline-block;
			float: right;
			margin: -22px -12px 0 0;
			position: relative;
			}
		#intense_debate_news .id_news_toggle a {
			font-size: 9px; line-height: 1em;
			text-decoration: none;
			}

    <!--[if IE]>
		.idwp-popup {
			background: none;
			position: absolute !important;
			top: 0; left: 0;
			overflow: hidden;
		}
		.idwp-popup-inner {
			background: #333;
		}
		.idwp-close {
			background: url(<?php echo ID_BASEURL ?>/images1/idwp-close_ie6.png) no-repeat;
			margin: 0;
		}
		a.idwp-floatright:hover .idwp-close, .idwp-close:hover {
			background-position: 0 100%;
		}
		.idwp-popup-inner, .idwp-popup-inner a, .idwp-popup-inner a:hover {
			color: #fff;
		}
		.idwp-popup-inner {
			width: 994px;
		}
		.idwp-popup-inner p {	
			margin: 10px 10px 0;
		}
		.idwp-popup-iframe {
			margin: 5px 10px 10px;
			width: 974px;
		}
		.idwp-close {
			display: none;
		}
    <![endif]-->

<?php
		die();	
	}

	function id_INCLUDE_settings_js() {
		global $id_plugin_path;
		
		$charSet = get_bloginfo( 'charset' );
		header( "Content-type: text/javascript; charset={$charSet}" );
		?>
jQuery(function() {
	
	// hide menu item
	// for some reason this doesn't work in WP 2.3
	// jQuery('#submenu a[href="options-general.php?page=id_registration"]').parent().hide();
	// so we have this ugly code instead
	jQuery("#submenu a").each(function() { 
		if (jQuery(this).attr('href') == 'options-general.php?page=id_registration') {
			jQuery(this).parent().hide();
		} 
	});
		
	// nav links
// 	jQuery('#id_user_login a[href=#id_email_lookup]').click(function() {
// 		jQuery('#id_email_lookup').toggleClass('hidden');
// 		return false;
// 	});

	jQuery('#id_settings_menu a').click(function(e) {
		e.preventDefault();
		jQuery('#id_user_registration, #id_user_login, #id_email_lookup').addClass('hidden');
		jQuery('#id_settings_menu a').removeClass('selected');
		var target = jQuery(this).attr('href');
		jQuery(this).addClass('selected');
		jQuery(target).toggleClass('hidden');
		jQuery('#id_active_form').val(target.replace('#', ''));
	});

	jQuery('#id_plugin_reset').submit(function() {
		return confirm('<?php _e( 'Are you sure you want to delete all of your settings and reset the IntenseDebate plugin?', 'intensedebate' ); ?>');
	});
	jQuery('#id_user_disconnect').click(function() {
		return confirm('<?php _e( 'Are you sure you want to disconnect your WordPress account from  your IntenseDebate account?', 'intensedebate' ); ?>');
	});

		<?php
		if ( strlen( get_option( 'id_moderationPage' ) ) > 0 && get_option( 'id_moderationPage' ) == 0 ) {
		// use the ID comment moderation page
		?>			
			jQuery('#adminmenu a[href=edit-comments.php]').attr('id', "id_moderate_comment_link");
			jQuery('#adminmenu a[href=edit-comments.php]').attr('href', "admin.php?page=intensedebate");
			jQuery('#favorite-actions a[href=edit-comments.php]').attr('href', "admin.php?page=intensedebate");
		<?php		
		}
		?>
});
<?php
if ( version_compare( get_bloginfo( 'version' ), '2.5', '>=' ) ) {
?>

// jquery.validate
/* ignore IE throwing errors when focusing hidden elements */
(function($){$.extend($.fn,{validate:function(options){if(!this.length){options&&options.debug&&window.console&&console.warn("nothing selected, can't validate, returning nothing");return;}var validator=$.data(this[0],'validator');if(validator){return validator;}validator=new $.validator(options,this[0]);$.data(this[0],'validator',validator);if(validator.settings.onsubmit){this.find("input, button").filter(".cancel").click(function(){validator.cancelSubmit=true;});this.submit(function(event){if(validator.settings.debug)event.preventDefault();function handle(){if(validator.settings.submitHandler){validator.settings.submitHandler.call(validator,validator.currentForm);return false;}return true;}if(validator.cancelSubmit){validator.cancelSubmit=false;return handle();}if(validator.form()){if(validator.pendingRequest){validator.formSubmitted=true;return false;}return handle();}else{validator.focusInvalid();return false;}});}return validator;},valid:function(){if($(this[0]).is('form')){return this.validate().form();}else{var valid=false;var validator=$(this[0].form).validate();this.each(function(){valid|=validator.element(this);});return valid;}},removeAttrs:function(attributes){var result={},$element=this;$.each(attributes.split(/\s/),function(){result[this]=$element.attr(this);$element.removeAttr(this);});return result;},rules:function(command,argument){var element=this[0];if(command){var staticRules=$.data(element.form,'validator').settings.rules;var existingRules=$.validator.staticRules(element);switch(command){case"add":$.extend(existingRules,$.validator.normalizeRule(argument));staticRules[element.name]=existingRules;break;case"remove":if(!argument){delete staticRules[element.name];return existingRules;}var filtered={};$.each(argument.split(/\s/),function(index,method){filtered[method]=existingRules[method];delete existingRules[method];});return filtered;}}var data=$.validator.normalizeRules($.extend({},$.validator.metadataRules(element),$.validator.classRules(element),$.validator.attributeRules(element),$.validator.staticRules(element)),element);if(data.required){var param=data.required;delete data.required;data=$.extend({required:param},data);}return data;},push:function(t){return this.setArray(this.add(t).get());}});$.extend($.expr[":"],{blank:function(a){return!$.trim(a.value);},filled:function(a){return!!$.trim(a.value);},unchecked:function(a){return!a.checked;}});$.format=function(source,params){if(arguments.length==1)return function(){var args=$.makeArray(arguments);args.unshift(source);return $.format.apply(this,args);};if(arguments.length>2&&params.constructor!=Array){params=$.makeArray(arguments).slice(1);}if(params.constructor!=Array){params=[params];}$.each(params,function(i,n){source=source.replace(new RegExp("\\{"+i+"\\}","g"),n);});return source;};$.validator=function(options,form){this.settings=$.extend({},$.validator.defaults,options);this.currentForm=form;this.init();};$.extend($.validator,{defaults:{messages:{},groups:{},rules:{},errorClass:"error",errorElement:"label",focusInvalid:true,errorContainer:$([]),errorLabelContainer:$([]),onsubmit:true,ignore:[],onfocusin:function(element){this.lastActive=element;if(this.settings.focusCleanup&&!this.blockFocusCleanup){this.settings.unhighlight&&this.settings.unhighlight.call(this,element,this.settings.errorClass);this.errorsFor(element).hide();}},onfocusout:function(element){if(!this.checkable(element)&&(element.name in this.submitted||!this.optional(element))){this.element(element);}},onkeyup:function(element){if(element.name in this.submitted||element==this.lastElement){this.element(element);}},onclick:function(element){if(element.name in this.submitted)this.element(element);},highlight:function(element,errorClass){$(element).addClass(errorClass);},unhighlight:function(element,errorClass){$(element).removeClass(errorClass);}},setDefaults:function(settings){$.extend($.validator.defaults,settings);},messages:{required:"This field is required.",remote:"Please fix this field.",email:"Please enter a valid email address.",url:"Please enter a valid URL.",date:"Please enter a valid date.",dateISO:"Please enter a valid date (ISO).",dateDE:"Bitte geben Sie ein g�ltiges Datum ein.",number:"Please enter a valid number.",numberDE:"Bitte geben Sie eine Nummer ein.",digits:"Please enter only digits",creditcard:"Please enter a valid credit card.",equalTo:"Please enter the same value again.",accept:"Please enter a value with a valid extension.",maxlength:$.format("Please enter no more than {0} characters."),minlength:$.format("Please enter at least {0} characters."),rangelength:$.format("Please enter a value between {0} and {1} characters long."),range:$.format("Please enter a value between {0} and {1}."),max:$.format("Please enter a value less than or equal to {0}."),min:$.format("Please enter a value greater than or equal to {0}.")},autoCreateRanges:false,prototype:{init:function(){this.labelContainer=$(this.settings.errorLabelContainer);this.errorContext=this.labelContainer.length&&this.labelContainer||$(this.currentForm);this.containers=$(this.settings.errorContainer).add(this.settings.errorLabelContainer);this.submitted={};this.valueCache={};this.pendingRequest=0;this.pending={};this.invalid={};this.reset();var groups=(this.groups={});$.each(this.settings.groups,function(key,value){$.each(value.split(/\s/),function(index,name){groups[name]=key;});});var rules=this.settings.rules;$.each(rules,function(key,value){rules[key]=$.validator.normalizeRule(value);});function delegate(event){var validator=$.data(this[0].form,"validator");validator.settings["on"+event.type]&&validator.settings["on"+event.type].call(validator,this[0]);}$(this.currentForm).delegate("focusin focusout keyup",":text, :password, :file, select, textarea",delegate).delegate("click",":radio, :checkbox",delegate);},form:function(){this.checkForm();$.extend(this.submitted,this.errorMap);this.invalid=$.extend({},this.errorMap);if(!this.valid())$(this.currentForm).triggerHandler("invalid-form.validate",[this]);this.showErrors();return this.valid();},checkForm:function(){this.prepareForm();for(var i=0,elements=(this.currentElements=this.elements());elements[i];i++){this.check(elements[i]);}return this.valid();},element:function(element){element=this.clean(element);this.lastElement=element;this.prepareElement(element);this.currentElements=$(element);var result=this.check(element);if(result){delete this.invalid[element.name];}else{this.invalid[element.name]=true;}if(!this.numberOfInvalids()){this.toHide.push(this.containers);}this.showErrors();return result;},showErrors:function(errors){if(errors){$.extend(this.errorMap,errors);this.errorList=[];for(var name in errors){this.errorList.push({message:errors[name],element:this.findByName(name)[0]});}this.successList=$.grep(this.successList,function(element){return!(element.name in errors);});}this.settings.showErrors?this.settings.showErrors.call(this,this.errorMap,this.errorList):this.defaultShowErrors();},resetForm:function(){if($.fn.resetForm)$(this.currentForm).resetForm();this.submitted={};this.prepareForm();this.hideErrors();this.elements().removeClass(this.settings.errorClass);},numberOfInvalids:function(){return this.objectLength(this.invalid);},objectLength:function(obj){var count=0;for(var i in obj)count++;return count;},hideErrors:function(){this.addWrapper(this.toHide).hide();},valid:function(){return this.size()==0;},size:function(){return this.errorList.length;},focusInvalid:function(){if(this.settings.focusInvalid){try{$(this.findLastActive()||this.errorList.length&&this.errorList[0].element||[]).filter(":visible").focus();}catch(e){}}},findLastActive:function(){var lastActive=this.lastActive;return lastActive&&$.grep(this.errorList,function(n){return n.element.name==lastActive.name;}).length==1&&lastActive;},elements:function(){var validator=this,rulesCache={};return $([]).add(this.currentForm.elements).filter(":input").not(":submit, :reset, :image, [disabled]").not(this.settings.ignore).filter(function(){!this.name&&validator.settings.debug&&window.console&&console.error("%o has no name assigned",this);if(this.name in rulesCache||!validator.objectLength($(this).rules()))return false;rulesCache[this.name]=true;return true;});},clean:function(selector){return $(selector)[0];},errors:function(){return $(this.settings.errorElement+"."+this.settings.errorClass,this.errorContext);},reset:function(){this.successList=[];this.errorList=[];this.errorMap={};this.toShow=$([]);this.toHide=$([]);this.formSubmitted=false;this.currentElements=$([]);},prepareForm:function(){this.reset();this.toHide=this.errors().push(this.containers);},prepareElement:function(element){this.reset();this.toHide=this.errorsFor(element);},check:function(element){element=this.clean(element);if(this.checkable(element)){element=this.findByName(element.name)[0];}var rules=$(element).rules();var dependencyMismatch=false;for(method in rules){var rule={method:method,parameters:rules[method]};try{var result=$.validator.methods[method].call(this,$.trim(element.value),element,rule.parameters);if(result=="dependency-mismatch"){dependencyMismatch=true;continue;}dependencyMismatch=false;if(result=="pending"){this.toHide=this.toHide.not(this.errorsFor(element));return;}if(!result){this.formatAndAdd(element,rule);return false;}}catch(e){this.settings.debug&&window.console&&console.log("exception occured when checking element "+element.id+", check the '"+rule.method+"' method");throw e;}}if(dependencyMismatch)return;if(this.objectLength(rules))this.successList.push(element);return true;},customMetaMessage:function(element,method){if(!$.metadata)return;var meta=this.settings.meta?$(element).metadata()[this.settings.meta]:$(element).metadata();return meta.messages&&meta.messages[method];},customMessage:function(name,method){var m=this.settings.messages[name];return m&&(m.constructor==String?m:m[method]);},findDefined:function(){for(var i=0;i<arguments.length;i++){if(arguments[i]!==undefined)return arguments[i];}return undefined;},defaultMessage:function(element,method){return this.findDefined(this.customMessage(element.name,method),this.customMetaMessage(element,method),element.title||undefined,$.validator.messages[method],"<strong>Warning: No message defined for "+element.name+"</strong>");},formatAndAdd:function(element,rule){var message=this.defaultMessage(element,rule.method);if(typeof message=="function")message=message.call(this,rule.parameters,element);this.errorList.push({message:message,element:element});this.errorMap[element.name]=message;this.submitted[element.name]=message;},addWrapper:function(toToggle){if(this.settings.wrapper)toToggle.push(toToggle.parents(this.settings.wrapper));return toToggle;},defaultShowErrors:function(){for(var i=0;this.errorList[i];i++){var error=this.errorList[i];this.settings.highlight&&this.settings.highlight.call(this,error.element,this.settings.errorClass);this.showLabel(error.element,error.message);}if(this.errorList.length){this.toShow.push(this.containers);}if(this.settings.success){for(var i=0;this.successList[i];i++){this.showLabel(this.successList[i]);}}if(this.settings.unhighlight){for(var i=0,elements=this.validElements();elements[i];i++){this.settings.unhighlight.call(this,elements[i],this.settings.errorClass);}}this.toHide=this.toHide.not(this.toShow);this.hideErrors();this.addWrapper(this.toShow).show();},validElements:function(){return this.currentElements.not(this.invalidElements());},invalidElements:function(){return $(this.errorList).map(function(){return this.element;});},showLabel:function(element,message){var label=this.errorsFor(element);if(label.length){label.removeClass().addClass(this.settings.errorClass);label.attr("generated")&&label.html(message);}else{label=$("<"+this.settings.errorElement+"/>").attr({"for":this.idOrName(element),generated:true}).addClass(this.settings.errorClass).html(message||"");if(this.settings.wrapper){label=label.hide().show().wrap("<"+this.settings.wrapper+">").parent();}if(!this.labelContainer.append(label).length)this.settings.errorPlacement?this.settings.errorPlacement(label,$(element)):label.insertAfter(element);}if(!message&&this.settings.success){label.text("");typeof this.settings.success=="string"?label.addClass(this.settings.success):this.settings.success(label);}this.toShow.push(label);},errorsFor:function(element){return this.errors().filter("[@for='"+this.idOrName(element)+"']");},idOrName:function(element){return this.groups[element.name]||(this.checkable(element)?element.name:element.id||element.name);},checkable:function(element){return/radio|checkbox/i.test(element.type);},findByName:function(name){var form=this.currentForm;return $(document.getElementsByName(name)).map(function(index,element){return element.form==form&&element.name==name&&element||null;});},getLength:function(value,element){switch(element.nodeName.toLowerCase()){case'select':return $("option:selected",element).length;case'input':if(this.checkable(element))return this.findByName(element.name).filter(':checked').length;}return value.length;},depend:function(param,element){return this.dependTypes[typeof param]?this.dependTypes[typeof param](param,element):true;},dependTypes:{"boolean":function(param,element){return param;},"string":function(param,element){return!!$(param,element.form).length;},"function":function(param,element){return param(element);}},optional:function(element){return!$.validator.methods.required.call(this,$.trim(element.value),element)&&"dependency-mismatch";},startRequest:function(element){if(!this.pending[element.name]){this.pendingRequest++;this.pending[element.name]=true;}},stopRequest:function(element,valid){this.pendingRequest--;if(this.pendingRequest<0)this.pendingRequest=0;delete this.pending[element.name];if(valid&&this.pendingRequest==0&&this.formSubmitted&&this.form()){$(this.currentForm).submit();}},previousValue:function(element){return $.data(element,"previousValue")||$.data(element,"previousValue",previous={old:null,valid:true,message:this.defaultMessage(element,"remote")});}},classRuleSettings:{required:{required:true},email:{email:true},url:{url:true},date:{date:true},dateISO:{dateISO:true},dateDE:{dateDE:true},number:{number:true},numberDE:{numberDE:true},digits:{digits:true},creditcard:{creditcard:true}},addClassRules:function(className,rules){className.constructor==String?this.classRuleSettings[className]=rules:$.extend(this.classRuleSettings,className);},classRules:function(element){var rules={};var classes=$(element).attr('class');classes&&$.each(classes.split(' '),function(){if(this in $.validator.classRuleSettings){$.extend(rules,$.validator.classRuleSettings[this]);}});return rules;},attributeRules:function(element){var rules={};var $element=$(element);for(method in $.validator.methods){var value=$element.attr(method);if(value){rules[method]=value;}}if(rules.maxlength&&/-1|2147483647|524288/.test(rules.maxlength)){delete rules.maxlength;}return rules;},metadataRules:function(element){if(!$.metadata)return{};var meta=$.data(element.form,'validator').settings.meta;return meta?$(element).metadata()[meta]:$(element).metadata();},staticRules:function(element){var rules={};var validator=$.data(element.form,'validator');if(validator.settings.rules){rules=$.validator.normalizeRule(validator.settings.rules[element.name])||{};}return rules;},normalizeRules:function(rules,element){$.each(rules,function(prop,val){if(val===false){delete rules[prop];return;}if(val.param||val.depends){var keepRule=true;switch(typeof val.depends){case"string":keepRule=!!$(val.depends,element.form).length;break;case"function":keepRule=val.depends.call(element,element);break;}if(keepRule){rules[prop]=val.param!==undefined?val.param:true;}else{delete rules[prop];}}});$.each(rules,function(rule,parameter){rules[rule]=$.isFunction(parameter)?parameter(element):parameter;});$.each(['minlength','maxlength','min','max'],function(){if(rules[this]){rules[this]=Number(rules[this]);}});$.each(['rangelength','range'],function(){if(rules[this]){rules[this]=[Number(rules[this][0]),Number(rules[this][1])];}});if($.validator.autoCreateRanges){if(rules.min&&rules.max){rules.range=[rules.min,rules.max];delete rules.min;delete rules.max;}if(rules.minlength&&rules.maxlength){rules.rangelength=[rules.minlength,rules.maxlength];delete rules.minlength;delete rules.maxlength;}}if(rules.messages){delete rules.messages}return rules;},normalizeRule:function(data){if(typeof data=="string"){var transformed={};$.each(data.split(/\s/),function(){transformed[this]=true;});data=transformed;}return data;},addMethod:function(name,method,message){$.validator.methods[name]=method;$.validator.messages[name]=message;if(method.length<3){$.validator.addClassRules(name,$.validator.normalizeRule(name));}},methods:{required:function(value,element,param){if(!this.depend(param,element))return"dependency-mismatch";switch(element.nodeName.toLowerCase()){case'select':var options=$("option:selected",element);return options.length>0&&(element.type=="select-multiple"||($.browser.msie&&!(options[0].attributes['value'].specified)?options[0].text:options[0].value).length>0);case'input':if(this.checkable(element))return this.getLength(value,element)>0;default:return value.length>0;}},remote:function(value,element,param){if(this.optional(element))return"dependency-mismatch";var previous=this.previousValue(element);if(!this.settings.messages[element.name])this.settings.messages[element.name]={};this.settings.messages[element.name].remote=typeof previous.message=="function"?previous.message(value):previous.message;if(previous.old!==value){previous.old=value;var validator=this;this.startRequest(element);var data={};data[element.name]=value;$.ajax({url:param,mode:"abort",port:"validate"+element.name,dataType:"json",data:data,success:function(response){if(!response){var errors={};errors[element.name]=response||validator.defaultMessage(element,"remote");validator.showErrors(errors);}else{var submitted=validator.formSubmitted;validator.prepareElement(element);validator.formSubmitted=submitted;validator.successList.push(element);validator.showErrors();}previous.valid=response;validator.stopRequest(element,response);}});return"pending";}else if(this.pending[element.name]){return"pending";}return previous.valid;},minlength:function(value,element,param){return this.optional(element)||this.getLength(value,element)>=param;},maxlength:function(value,element,param){return this.optional(element)||this.getLength(value,element)<=param;},rangelength:function(value,element,param){var length=this.getLength(value,element);return this.optional(element)||(length>=param[0]&&length<=param[1]);},min:function(value,element,param){return this.optional(element)||value>=param;},max:function(value,element,param){return this.optional(element)||value<=param;},range:function(value,element,param){return this.optional(element)||(value>=param[0]&&value<=param[1]);},email:function(value,element){return this.optional(element)||/^((([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+(\.([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.)+(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.?$/i.test(element.value);},url:function(value,element){return this.optional(element)||/^(https?|ftp):\/\/(((([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(%[\da-f]{2})|[!\$&'\(\)\*\+,;=]|:)*@)?(((\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])\.(\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])\.(\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])\.(\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5]))|((([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.)+(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.?)(:\d*)?)(\/((([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(%[\da-f]{2})|[!\$&'\(\)\*\+,;=]|:|@)+(\/(([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(%[\da-f]{2})|[!\$&'\(\)\*\+,;=]|:|@)*)*)?)?(\?((([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(%[\da-f]{2})|[!\$&'\(\)\*\+,;=]|:|@)|[\uE000-\uF8FF]|\/|\?)*)?(\#((([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(%[\da-f]{2})|[!\$&'\(\)\*\+,;=]|:|@)|\/|\?)*)?$/i.test(element.value);},date:function(value,element){return this.optional(element)||!/Invalid|NaN/.test(new Date(value));},dateISO:function(value,element){return this.optional(element)||/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/.test(value);},dateDE:function(value,element){return this.optional(element)||/^\d\d?\.\d\d?\.\d\d\d?\d?$/.test(value);},number:function(value,element){return this.optional(element)||/^-?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/.test(value);},numberDE:function(value,element){return this.optional(element)||/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d+)?$/.test(value);},digits:function(value,element){return this.optional(element)||/^\d+$/.test(value);},creditcard:function(value,element){if(this.optional(element))return"dependency-mismatch";if(/[^0-9-]+/.test(value))return false;var nCheck=0,nDigit=0,bEven=false;value=value.replace(/\D/g,"");for(n=value.length-1;n>=0;n--){var cDigit=value.charAt(n);var nDigit=parseInt(cDigit,10);if(bEven){if((nDigit*=2)>9)nDigit-=9;}nCheck+=nDigit;bEven=!bEven;}return(nCheck%10)==0;},accept:function(value,element,param){param=typeof param=="string"?param:"png|jpe?g|gif";return this.optional(element)||value.match(new RegExp(".("+param+")$","i"));},equalTo:function(value,element,param){return value==$(param).val();}}});})(jQuery);;(function($){var ajax=$.ajax;var pendingRequests={};$.ajax=function(settings){settings=$.extend(settings,$.extend({},$.ajaxSettings,settings));var port=settings.port;if(settings.mode=="abort"){if(pendingRequests[port]){pendingRequests[port].abort();}return(pendingRequests[port]=ajax.apply(this,arguments));}return ajax.apply(this,arguments);};})(jQuery);;(function($){$.each({focus:'focusin',blur:'focusout'},function(original,fix){$.event.special[fix]={setup:function(){if($.browser.msie)return false;this.addEventListener(original,$.event.special[fix].handler,true);},teardown:function(){if($.browser.msie)return false;this.removeEventListener(original,$.event.special[fix].handler,true);},handler:function(e){arguments[0]=$.event.fix(e);arguments[0].type=fix;return $.event.handle.apply(this,arguments);}};});$.extend($.fn,{delegate:function(type,delegate,handler){return this.bind(type,function(event){var target=$(event.target);if(target.is(delegate)){return handler.apply(target,arguments);}});},triggerEvent:function(type,target){return this.triggerHandler(type,[$.event.fix({type:type,target:target})]);}})})(jQuery);

jQuery(function() {
	// form validation
	var opts = {errorClass: "invalid"};
	try {
		jQuery('form#id_user_login').validate(opts);
		jQuery('form#id_user_registration').validate(opts);
		jQuery('form#id_email_lookup').validate(opts);
	} catch(e) {
//		console.log('form validation unavailable');
	}
});
		<?php
		}
		die();
	}

// ACTIVATE
	
	id_activate_hooks();

	/*  Hook into existing template and inject IntenseDebate comment system 
	(as well as old system in a noscript tags for browsers w/out JS and crawlers)
	Add template file in options for later use in intensedebate-comment-template.php */
	
	function id_comments_template( $file ) {
		if ( !( is_single() || is_page() || $withcomments ) ) {
			return $file;
		}
		
		update_option( "id_comment_template_file", $file );
		
		// Get our template file to swap out
		$directory = dirname( __FILE__ ) . '/intensedebate-comment-template.php';
		return $directory;
	}

	function id_get_comment_number( $comment_text ) {
		global $post;		
		
		if ( get_option( "id_jsCommentLinks" ) == 0 ) {
			$id = $post->ID;
			$posttitle = urlencode( $post->post_title );
			$posttime = urlencode( $post->post_date_gmt );
			$postauthor = urlencode( get_author_name( $post->post_author ) );
			$permalink = get_permalink( $id );
			$permalinkEncoded = urlencode( $permalink );
			
			return "<span class='IDCommentsReplace' style='display:none'>$id</span>" . __( 'Comments', 'intensedebate' ) . "<span style='display:none' id='IDCommentPostInfoPermalink$id'>$permalink</span><span style='display:none' id='IDCommentPostInfoTitle$id'>$posttitle</span><span style='display:none' id='IDCommentPostInfoTime$id'>$posttime</span><span style='display:none' id='IDCommentPostInfoAuthor$id'>$postauthor</span>";
		} else {
			return $comment_text;
		}
	}
	
	function id_get_comment_footer_script() {	
		global $id_link_wrapper_output;
		global $id_cur_post;
		
		if ( !$id_link_wrapper_output ) {
			$id_link_wrapper_output = true;
		
			if ( strlen( get_option( "id_blogAcct" ) ) > 0 ) {
				echo "<script src = '" . ID_BASEURL . "/js/wordpressTemplateLinkWrapper2.php?acct=" . get_option( "id_blogAcct" ) . "' type='text/javascript'></script>";
			}
		}
	}

	function id_admin_footer() {
		if ( 0 == get_option( 'id_moderationPage' ) )
			echo "<script type='text/javascript' src='" . ID_BASEURL . "/js/wpModLink.php?acct=" . get_option( "id_blogAcct" ) . "'></script>";
		
		id_get_comment_footer_script();
	}
	
?>