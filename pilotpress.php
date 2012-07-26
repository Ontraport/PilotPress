<?php 
/*
Plugin Name: PilotPress
Plugin URI: http://officeautopilot.com/
Description: OfficeAutoPilot / WordPress integration plugin.
Version: 1.5.7
Author: MoonRay, LLC
Author URI: http://officeautopilot.com/
Text Domain: pilotpress
Copyright: 2011, MoonRay, LLC
*/
		
	if(defined("ABSPATH")) {
		include_once(ABSPATH.WPINC.'/class-http.php');
		include_once(ABSPATH.WPINC.'/registration.php');
		register_activation_hook(__FILE__, "enable_pilotpress");
		register_deactivation_hook(__FILE__, "disable_pilotpress");
		$pilotpress = new PilotPress;
	}
	
	class PilotPress {

		const VERSION = "1.5.7";
		const WP_MIN = "3.0.0";
		const NSPACE = "_pilotpress_";
		const URL_API = "https://www1.moon-ray.com/api.php";
		const BACKUP_URL_API = "https://web.moon-ray.com/api.php";
		const URL_TJS = "https://www1.moon-ray.com/tracking.js";
		const URL_JSWPCSS = "https://forms.moon-ray.com/v2.4/include/scripts/moonrayJS/moonrayJS-only-wp-forms.css";
		const URL_MRCSS = "https://forms.moon-ray.com/v2.4/include/minify/?g=moonrayCSS";
		const URL_JQCSS = "https://ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/smoothness/jquery-ui.css";

		public $system_pages = array();
	
		/* Various runtime, shared variables */
		private $uri;
		private $metaboxes;
		private $settings;
		private $status = 0;
		private $do_login = false;
		private $homepage_url;
	
		function __construct() {
	
			$this->bind_hooks(); /* hook into WP */
			$this->start_session(); /* use sessions, controversial but easy */
			$this->load_settings(); /* hitup the API or grab transient */
	
			/* use this var, it's handy */
			$this->uri = get_option('siteurl').'/wp-content/plugins/'.dirname(plugin_basename(__FILE__));
			
			/* metaboxes in admin */
			$this->metaboxes[self::NSPACE."page_box"] = array(
			    	'id' => self::NSPACE.'page_box',
			    	'title' => 'PilotPress Options',
			    	'context' => 'side',
			    	'priority' => 'high',
			    	'fields' => array(
			        	array(
			            		'name' => 'Access Levels',
			            		'desc' => 'To have no level, do not check any boxes.',
			            		'id' => self::NSPACE.'level',
			            		'type' => 'multi-checkbox',
			            		'options' => $this->get_setting("membership_levels", "oap")
			        	),
						array(
			            		'name' => 'Show in Navigation',
			            		'desc' => false,
			            		'id' => self::NSPACE.'show_in_nav',
			            		'type' => 'single-checkbox'
			   			),
						array(
			            		'name' => 'On Error',
			            		'desc' => $this->get_setting("error_redirect_message"),
			            		'id' => self::NSPACE.'redirect_location',
			            		'type' => $this->get_setting("error_redirect_field"),
			            		'options' => array()
			        	)
			     	)
			);
			
			/* the various Centers */
			$this->centers = array(
				"customer_center" => array(
					"title" => "Customer Center",
					"slug" => "customer-center",
					"content" => "This content will be replaced by the Customer Center"
				),
				"affiliate_center" => array(
					"title" => "Affiliate Center",
					"slug" => "affiliate-center",
					"content" => "This content will be replaced by the Affiliate Center"
				),
			);
		}
		
		/* this function loads up runtime settings from API or transient caches for both plugin and user (if logged in) */
		function load_settings() {

			global $wpdb;
			
			$this->system_pages = $this->get_system_pages();

			if(get_transient('pilotpress_cache') && !isset($_SESSION["rehash"])) { 
				$this->settings = get_transient('pilotpress_cache');
				if(get_transient("usertags_".$this->get_setting("contact_id", "user"))) {
					$tags = get_transient("usertags_".$this->get_setting("contact_id", "user"));
					if(is_array($tags["tags"])) {
						$this->settings["user"]["tags"] = $tags["tags"];
					}
				}
				$this->status = 1;
			} else {

				if(isset($_SESSION["rehash"])) {
					unset($_SESSION["rehash"]);
				}
				
				$this->settings["wp"] = array();
				$this->settings["wp"]["post_types"] = array();
				$this->settings["wp"]["permalink"] = get_option('permalink_structure');
				$this->settings["wp"]["template"] = get_option('template');
				$this->settings["wp"]["plugins"] = get_option('active_plugins');
				$this->settings["wp"]["post_types"] = get_post_types();

				$this->settings["pilotpress"] = get_option("pilotpress-settings");
				
				if($this->get_setting("usehome")) {
					$this->homepage_url = home_url();
				} else {
					$this->homepage_url = site_url();
				}
								
				$this->settings["pilotpress"]["error_redirect_field"] = 'select-keyvalue';
				$this->settings["pilotpress"]["error_redirect_message"] = "Redirect to THIS page on error.";
				
				$results = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_show_in_nav'", ARRAY_A);			
				foreach($results as $index => $page) {
					$this->settings["pilotpress"]["show_in_nav"][] = $page["post_id"];
				}

				if($this->get_setting("api_key") && $this->get_setting("app_id")) {
					$api_result = $this->api_call("get_site_settings", array("site" => site_url()));					
					if(is_array($api_result)) {
						$this->settings["oap"] = $api_result;
						
						if(isset($this->settings["user"])) {
							unset($this->settings["user"]);
						}
						
						if(!$this->get_setting("disablecaching")) {
							set_transient('pilotpress_cache', $this->settings, 60 * 60 * 12); 
						}
																	
						$_SESSION["default_fields"] = $this->settings["oap"]["default_fields"];
						$this->status = 1;
					}
				} else {
					$this->status = 0;
				}
				
				$this->settings["user"] = $this->get_user_settings();
				
				if($this->get_setting("contact_id", "user")) {
					if(get_transient("usertags_".$this->get_setting("contact_id", "user"))) {
						$tags = get_transient("usertags_".$this->get_setting("contact_id", "user"));
					} else {
						$tags = $this->api_call("get_contact_tags", array("contact_id" => $this->get_setting("contact_id", "user")));
						if(!$this->get_setting("disablecaching")) {
							set_transient('usertags_'.$this->get_setting("contact_id", "user"), $tags, 60 * 60 * 12); 
						}	
					}
					
					if(is_array($tags["tags"])) {
						$this->settings["user"]["tags"] = $tags["tags"];
					}
						
				}
			}
		}
		
		/* what protocol? */
		static function get_protocol() {
			if(isset($_SERVER["HTTPS"])) {
				if(!empty($_SERVER["HTTPS"])) {
					return "https://";
				}
			}
			return "http://";
		}
		
		/* add metaboxes to said post types */
		function update_post_types() {

			$exclude = array("attachment","revision","nav_menu_item");
			$array = $this->get_setting("post_types","wp");

			$post_types = get_post_types('','names');
			foreach($post_types as $post_type) {
			  	if(!in_array($post_type, $array) && !in_array($post_type, $exclude)) {
					$array[] = $post_type;
				}
			}

			$this->settings["wp"]["post_types"] = $array;

		}
		
		function get_setting($key, $type = "pilotpress", $array = false) {
			if(isset($this->settings[$type][$key])) {
				if(!is_array($this->settings[$type][$key]) && $array) {
					return array($this->settings[$type][$key]);
				} else {
					return $this->settings[$type][$key];	
				}
			} else {
				if($array) {
					return array();
				} else {
					return false;
				}
			}
		}
		
		/* ditto getter is also handy */
		function get_field($key) {
			foreach($this->get_setting("fields", "user", true) as $group => $fields) {
				if(isset($fields[$key])) {
					return $fields[$key];
				}
			}
			foreach($this->get_setting("default_fields", "oap", true) as $group => $fields) {
				if(isset($fields[$key])) {
					return $fields[$key];
				}
			}
			return "";
		}
		
		function is_setup() {
			if($this->status != 0) {
				return true;
			} else {
				return false;
			}
		}
	
		/* this is a fancy getter, for user settings */
		function get_user_settings() {
			$return = array();
			
			if(isset($_COOKIE["contact_id"])) {
				$return["contact_id"] = $_COOKIE["contact_id"];
			} else if ($_SESSION['contact_id']){
				$return["contact_id"] = $_SESSION["contact_id"];
			}
						
			if(isset($_SESSION["user_levels"])) {
				$return["name"] = $_SESSION["user_name"];
				$return["username"] = $_SESSION["user_name"];
				$return["nickname"] = $_SESSION["nickname"];
				$return["fields"] = $_SESSION["user_fields"];
				$return["levels"] = $_SESSION["user_levels"];
			}
			return $return;
		}
		
		/* finally some fun: this sets up the admin edit page! */
		function settings_init() {
			
			$_SESSION["rehash"] = 1;
			
			add_options_page('PilotPress Settings' , 'PilotPress', 'manage_options', 'pilotpress-settings', array(&$this, 'settings_page'));
			register_setting('pilotpress-settings', 'pilotpress-settings', array(&$this, 'settings_validate'));
			
			add_settings_section('pilotpress-settings-general', __('General Settings', 'pilotpress'), array(&$this, 'settings_section_general'), 'pilotpress-settings'); 
			add_settings_field('pilotpress_app_id',   __('Application ID', 'pilotpress'), array(&$this, 'display_settings_app_id'), 'pilotpress-settings', 'pilotpress-settings-general');
			add_settings_field('pilotpress_api_key', __('API Key', 'pilotpress'), array(&$this, 'display_settings_api_key'), 'pilotpress-settings', 'pilotpress-settings-general');
			add_settings_field('wp_userlockout', __('Lock users out of Profile editor', 'pilotpress'), array(&$this, 'display_settings_userlockout'), 'pilotpress-settings', 'pilotpress-settings-general');

			add_settings_section('settings_section_oap', __('OfficeAutoPilot Integration Settings', 'pilotpress'), array(&$this, 'settings_section_oap'), 'pilotpress-settings'); 
			add_settings_field('customer_center',  __('Enable Customer Center', 'pilotpress'), array(&$this, 'display_settings_cc'), 'pilotpress-settings', 'settings_section_oap');
			add_settings_field('affiliate_center',  __('Enable Affiliate Center', 'pilotpress'), array(&$this, 'display_settings_ac'), 'pilotpress-settings', 'settings_section_oap');

			add_settings_section('pilotpress-redirect-display', __('Post Login Redirect Settings', 'pilotpress'), array(&$this, 'settings_section_redirect'), 'pilotpress-settings'); 
			add_settings_field('pilotpress_customer_plr', __('Customers Redirect To', 'pilotpress'), array(&$this, 'display_settings_customer_plr'), 'pilotpress-settings', 'pilotpress-redirect-display');
			add_settings_field('pilotpress_affiliate_plr', __('Affiliates Redirect To', 'pilotpress'), array(&$this, 'display_settings_affiliate_plr'), 'pilotpress-settings', 'pilotpress-redirect-display');

			add_settings_section('pilotpress-settings-advanced', __('Advanced Settings', 'pilotpress'), array(&$this, 'settings_section_advanced'), 'pilotpress-settings'); 
			add_settings_field('pp_use_cache', __('Disable API Caching', 'pilotpress'), array(&$this, 'display_settings_disablecaching'), 'pilotpress-settings', 'pilotpress-settings-advanced');
			add_settings_field('pp_sslverify', __('Disable Verify Host SSL', 'pilotpress'), array(&$this, 'display_settings_disablesslverify'), 'pilotpress-settings', 'pilotpress-settings-advanced');
			add_settings_field('pp_use_home', __('Use WordPress URL instead of Site URL', 'pilotpress'), array(&$this, 'display_settings_usehome'), 'pilotpress-settings', 'pilotpress-settings-advanced');			

		}
		
		/* WP is sometimes silly, this is a function to echo a checkbox and have it registered.. annoying but easy */
		function display_settings_cc() {
			echo "<input type='checkbox' name='pilotpress-settings[customer_center]'";
			if($this->get_setting("customer_center")) {
				echo " checked";
			}
			echo ">";
		}
		
		/* ditto */
		function display_settings_ac() {
			echo "<input type='checkbox' name='pilotpress-settings[affiliate_center]'";
			if($this->get_setting("affiliate_center")) {
				echo " checked";
			}
			echo ">";
		}
	
		/* customer center settings */
		function display_settings_customer_plr() {

			$setting = $this->get_setting("pilotpress_customer_plr");
			if(!$setting) {
				$setting = "-1";
			}

			$pages = $this->get_routeable_pages(array("-2"));
			echo "<select name='pilotpress-settings[pilotpress_customer_plr]'>";
			foreach($pages as $id => $title) {
				echo "<option value='{$id}'";
				if($id == $setting) {
					echo " selected";
				}
				echo ">{$title}</option>";
			}
			echo "</select>";
		}
		
		/* ditto, but for affil center */
		function display_settings_affiliate_plr() {

			$setting = $this->get_setting("pilotpress_affiliate_plr");
			if(!$setting) {
				$setting = "-1";
			}

			$pages = $this->get_routeable_pages(array("-2"));
			echo "<select name='pilotpress-settings[pilotpress_affiliate_plr]'>";
			foreach($pages as $id => $title) {
				echo "<option value='{$id}'";
				if($id == $setting) {
					echo " selected";
				}
				echo ">{$title}</option>";
			}
			echo "</select>";
		}
	
		/* section output, blank for austerity */
		function settings_section_general() {}
		function settings_section_oap() {}
		function settings_section_redirect() {}
		function settings_section_advanced() {
			echo "<span class='pilotpress-advanced-warning'><b>WARNING:</b> these settings affect the core functionality of the PilotPress plugin, proceed with caution.</span>";
		}
	
		/* notices! this is where the magic nags happen */
		function display_notice() {

			global $post, $wp_version;

			if(basename($_SERVER["SCRIPT_NAME"]) == "post.php" && $_GET["action"] == "edit" && in_array($post->ID, $this->system_pages)) {
				echo '<div class="updated"><p>This page is used by the <b>PilotPress</b> plugin. You can edit the content but not delete the page itself.</p></div>';
			}

			if($wp_version < self::WP_MIN) {
				echo '<div class="error" style="padding-top: 5px; padding-bottom: 5px;">';
				_e('PilotPress requires WordPress '.self::WP_MIN.' or higher. Please de-activate the PilotPress plugin, upgrade to WordPress '.self::WP_MIN.' or higher then activate PilotPress again.', 'pilotpress');
				echo '</div>';
			}

			if (!$this->get_setting('api_key') || !$this->get_setting('app_id')) {

				echo '<div class="error" style="padding-top: 5px; padding-bottom: 5px;">';
				_e('PilotPress must be configured with an OfficeAutoPilot API Key and App ID.', 'pilotpress');

				if($_GET['page'] != 'pilotpress-settings') {
					_e(sprintf('Go to the <a href="%s" title="PilotPress Admin Page">PilotPress Admin Page</a> to finish setting up your site!', 'options-general.php?page=pilotpress-settings'), 'pilotpress');
					echo ' ' ;
					_e(sprintf('You need an <a href="%s" title="Visit OfficeAutoPilot.com">OfficeAutoPilot</a> account to use this plugin.', 'http://officeautopilot.com'));
					echo ' ';
					_e('Don\'t have one yet?', 'pilotpress');
					echo ' ';
					_e(sprintf('<a href="%s" title="OfficeAutoPilot SignUp">Sign up</a> now!', 'https://www.moon-ray.com/officeautopilot_signup.php', 'pilotpress'));				
				}

				echo '</div>';
			}

			if(!$this->is_setup() && $this->get_setting('api_key') && $this->get_setting('app_id')) {
				echo '<div class="error" style="padding-top: 5px; padding-bottom: 5px;">';
				_e('Either this site <b>'.str_replace("http://","",(string)site_url()).'</b> is not configured in OfficeAutopilot or the <a href="options-general.php?page=pilotpress-settings">API Key / App Id settings</a> are incorrect. ', 'pilotpress');
				_e('Most PilotPress features are disabled until this is configured. Please <a href="'.$this->get_setting("setup_url", "oap").'">click here</a> to set it up or visit the <a href="'.$this->get_setting("help_url", "oap").'">online help</a> for more information.', 'pilotpress');
				echo '</div>';
			}

		}
		
		function display_settings_api_key() {
			?>
			<input size="50" name="pilotpress-settings[api_key]" id="pilotpress_api_key" type="text" class="code" value="<?php echo $this->get_setting('api_key'); ?>" />
			<?php 
		}

		function display_settings_app_id() {
			?>
			<input size="50" name="pilotpress-settings[app_id]" id="pilotpress_app_id" type="text" class="code" value="<?php echo $this->get_setting('app_id'); ?>" />
			<?php 
		}

		function display_settings_userlockout() {
			echo "<input type='checkbox' name='pilotpress-settings[wp_userlockout]'";
			if($this->get_setting("wp_userlockout")) {
				echo " checked";
			}
			echo ">";
		}

		function display_settings_disablecaching() {
			echo "<input type='checkbox' name='pilotpress-settings[disablecaching]'";
			if($this->get_setting("disablecaching")) {
				echo " checked";
			}
			echo ">";
		}
		
		function display_settings_disablesslverify() {
			echo "<input type='checkbox' name='pilotpress-settings[disablesslverify]'";
			if($this->get_setting("disablesslverify")) {
				echo " checked";
			}
			echo ">";
		}
		
		function display_settings_disableprotected() {
			echo "<input type='checkbox' name='pilotpress-settings[disableprotected]'";
			if($this->get_setting("disableprotected")) {
				echo " checked";
			}
			echo ">";
		}
		
		
		function display_settings_usehome() {
			echo "<input type='checkbox' name='pilotpress-settings[usehome]'";
			if($this->get_setting("usehome")) {
				echo " checked";
			}
			echo ">";
		}
	
		/* finally, we register the settings page itself. */
		function settings_page() {

			?>			
			<div class="wrap"><h2><?php _e('PilotPress Settings', 'pilotpress'); ?></h2><?php

			?><form name="pilotpress-settings" method="post" action="options.php"><?php

			settings_fields('pilotpress-settings');
			do_settings_sections('pilotpress-settings');
			
			?>
						
			<p class="submit"><input type="submit" class="button-primary" name="save" value="<?php _e('Save Changes', 'pilotpress'); ?>" />&nbsp;<input type="button" class="button-secondary" name="advanced" value="<?php _e('Advanced Settings', 'pilotpress'); ?>"></p></form></div>
			
			<script type="text/javascript">
				jQuery(document).ready(function() {
					jQuery(document).find("[name=pilotpress-settings] h3:eq(3)").toggle();
					jQuery(document).find(".pilotpress-advanced-warning").toggle();
					jQuery(document).find("[name=pilotpress-settings] table:eq(3)").toggle();
					jQuery(document).find("[name=advanced]").click(function() {
						jQuery(document).find("[name=pilotpress-settings] h3:eq(3)").toggle();
						jQuery(document).find(".pilotpress-advanced-warning").toggle();
						jQuery(document).find("[name=pilotpress-settings] table:eq(3)").toggle();
					});
				});
			</script>
			
			<?php
		}
	
		/* use this to validate input, for now it simply creates the pages and/or resets cache */
		function settings_validate($input) {

			if(isset($input["customer_center"])) {
				$this->create_system_page("customer_center");
			} else {
				$this->delete_system_page("customer_center");
			}

			if(isset($input["affiliate_center"])) {
				$this->create_system_page("affiliate_center");
			} else {
				$this->delete_system_page("affiliate_center");
			}

			$_SESSION["rehash"] = 1;
			return $input;	
		}
		
		/* OH YEAH! this is the API call method, wraps the static function as some other plugins may call via their own behalf */		
		function api_call($method, $data) {
			return self::api_call_static($method, $data, $this->get_setting("app_id"), $this->get_setting("api_key"), $this->get_setting("disablesslverify"));
		}
		
		/* this is the real function of the above, for errors... try dumping $post */
		static function api_call_static($method, $data, $app_id, $api_key, $ssl_verify = false) {
			
			$post = array('body' => array("app_id" => $app_id, 
										"api_key" => $api_key,
										"data" => json_encode($data)), 'timeout' => 500);
									
			if($ssl_verify) {
				$post["sslverify"] = 0;
			}
			
			$endpoint = sprintf(PilotPress::URL_API.'/%s/%s/%s', "json", "pilotpress", $method);
			$response = wp_remote_post($endpoint, $post);
			
			if ($response->errors['http_request_failed']){
				$endpoint = sprintf(PilotPress::BACKUP_URL_API.'/%s/%s/%s', "json", "pilotpress", $method);
				$response = wp_remote_post($endpoint, $post);
			}

			if(is_wp_error($response) || $response['response']['code'] == 500) {
				return false;
			} else {
				$body = json_decode(trim($response['body']), true);
			}

			if(isset($body["type"]) && $body["type"] == "error") {
				return false;
			} else {
				return $body["pilotpress"];
			}
			
		}
	
		/* all WP binding happens here, mostly. consolidated for your pleasure */
		private function bind_hooks() {

			add_action("init", array(&$this, "load_scripts"));
			add_action('wp_print_styles', array(&$this, 'stylesheets'));
			add_action('wp_head', array(&$this, 'tracking'));
			add_action('retrieve_password', array(&$this, 'retrieve_password'));
			add_action('profile_update', array(&$this, 'profile_update'));
			
			add_action("wp_ajax_pp_update_aff_details", array(&$this, 'update_aff_details'));
			add_action("wp_ajax_pp_update_cc_details", array(&$this, 'update_cc_details'));

			if(is_admin()) {
				add_action('admin_menu', array(&$this, 'settings_init'));
				add_filter('admin_init', array(&$this, 'clean_meta'));
				add_filter('admin_init', array(&$this, 'flush_rewrite_rules'));
				add_filter('admin_init', array(&$this, 'user_lockout'));
				add_action('admin_notices', array(&$this, 'display_notice'));

				add_action('admin_menu', array(&$this, 'metabox_add'));
				add_action('save_post', array(&$this, 'metabox_save'));

				add_action('media_buttons', array(&$this, 'media_button_add'), 20);
				add_action('media_upload_forms', array(&$this, 'media_upload_forms'));
				add_action('media_upload_images', array(&$this, 'media_upload_images'));
				add_action('media_upload_videos', array(&$this, 'media_upload_videos'));
				add_action('media_upload_fields', array(&$this, 'media_upload_fields'));	
				add_action('wp_ajax_pp_insert_form', array(&$this, 'get_insert_form_html'));
				add_action('wp_ajax_pp_insert_video', array(&$this, 'get_insert_video_html'));
				add_action("wp_ajax_pp_get_aff_report", array(&$this, 'get_aff_report'));
				
				add_filter('tiny_mce_before_init', array(&$this, 'mce_valid_elements'));
				add_filter('tiny_mce_version', array(&$this, 'tiny_mce_version') );
				add_filter("mce_external_plugins", array(&$this, "mce_external_plugins"));
				add_filter('mce_buttons', array(&$this, 'mce_buttons'));

				add_filter('manage_posts_columns', array(&$this, 'page_list_col'));
				add_action('manage_posts_custom_column', array(&$this, 'page_list_col_value'), 10, 2);
				add_filter('manage_pages_columns', array(&$this, 'page_list_col'));
				add_action('manage_pages_custom_column', array(&$this, 'page_list_col_value'), 10, 2);
				add_filter('user_has_cap', array(&$this, 'lock_delete'), 0, 3);
				add_filter('media_upload_tabs', array(&$this, 'modify_media_tab'));
				add_action('wp_loaded', array(&$this, 'update_post_types'));
				
				//add_action('admin_print_footer_scripts', array(&$this, 'tinymce_autop'), 50);

			} else {
				add_filter('rewrite_rules_array', array(&$this, 'filter_rewrite_rules'));
				add_action('wp', array(&$this, 'post_process'));
				add_filter('get_pages', array(&$this, 'get_pages'));
				add_filter("wp_nav_menu", array(&$this, 'get_nav_menus'));
				add_filter("wp_nav_menu_objects", array(&$this, 'get_nav_menu_objects'));
				add_filter('posts_where', array(&$this, 'posts_where'));
				add_filter('query_vars', array(&$this, 'filter_query_vars'));
				add_filter('the_content', array(&$this, 'content_process'));
				add_filter('login_message', array(&$this, 'content_process'));
				
				add_shortcode('protected', array(&$this, 'shortcode_show_if'));
				add_shortcode('show_if', array(&$this, 'shortcode_show_if'));
				add_shortcode('login_page', array(&$this, 'login_page'));
				add_shortcode('field', array(&$this, 'shortcode_field'));
			}

			add_action('wp_authenticate', array(&$this, 'user_login'), 1, 2);
			add_action("wp_login_failed", array(&$this, 'user_login_failed'));
			add_action("lostpassword_post", array(&$this, 'user_lostpassword'));
			add_action('wp_logout', array(&$this, 'user_logout'));

		}

		function retrieve_password($name) {
			$return = $this->api_call("retrieve_password", array("site" => site_url(), "username" => $name));
		}
		
		/* update a persons profile */
		function profile_update($user_id) {
			
			$user = get_userdata($_POST["user_id"]);
			
			$details = array();
			$details["site"] = site_url();
			$details["username"] = $user->user_login;
			$details["firstname"] = $_POST['first_name'];
			$details["lastname"] = $_POST['last_name'];
			$details["nickname"] = $_POST['nickname'];
			$details["password"] = $_POST["pass1"];
			wp_update_user(array("ID" => $user->ID, "user_pass" => $_POST["pass1"]));			
			$return = $this->api_call("profile_update", $details);
		}

		function user_lockout() {
			global $current_user;
			if(!current_user_can('manage_options') && $this->get_setting("wp_userlockout") && !isset($_POST["action"])) {
				$customer = $this->get_setting("pilotpress_customer_plr");
				if(!empty($customer) && $customer != "-1") {
					wp_redirect(get_permalink($customer));
				} else {
					wp_redirect($this->homepage_url);
				}
				die;
			}
		}
	
		/* please load scripts here vs. printing. it's so much healthier */
		function load_scripts() {
			wp_register_script("mr_tracking", self::URL_TJS);
			wp_enqueue_script("mr_tracking");
			wp_enqueue_script("jquery");
		}

		function stylesheets() {

			wp_register_style("mrjswp", self::URL_JSWPCSS);
			wp_enqueue_style("mrjswp");

			wp_register_style("mrcss", self::URL_MRCSS);
			wp_enqueue_style("mrcss");

			wp_register_style("jqcss", self::URL_JQCSS);
			wp_enqueue_style("jqcss");
		}
	
		/* except this one. */
		function tracking() {
			echo "<script>_mri = \"".$this->get_setting('tracking','oap')."\";mrtracking();</script>";
		}
	
		/* first of a few tinymce functions, this registers some of our buttons */
		function mce_buttons($buttons) {
			array_push($buttons, "separator", "merge_fields");
			return $buttons;
		}
		
		/* load up our marshalled plugin code (see comment prefixed: Xevious) */
		function mce_external_plugins($plugin_array) {
			$plugin_array['pilotpress']  =  plugins_url('/pilotpress/pilotpress.php?ping=js');
			return $plugin_array;
		}
	
		/* i forget what this did, but it is important */
		function tiny_mce_version($version) {
			return ++$version;
		}
	
		/* right so... lets just make most useful elements avaliable */
		function mce_valid_elements($in) {
			$em = '#p[*],p[*],form[*],div[*],span[*],script[*],link[*]';
			
			if(!is_array($in)) {
				$in = array();
			}
			
			if(isset($in["extended_valid_elements"])) {
				$in["extended_valid_elements"] .= ',';
				$in["extended_valid_elements"] .= $em;
			} else {
				$in["extended_valid_elements"] = $em;
			}
			
			$in["entity_encoding"] = "raw";			
			
			return $in;
		}
	
		/* horrible but it gets the job done. WP said they'd fix this in 3.3, but they lied */
		function lock_delete($allcaps, $caps, $args) {

			global $wp_post;

			if(is_array($this->system_pages)) {
				if(isset($_GET["post"])) {
					if(in_array($_GET["post"], $this->system_pages)) {
						if(is_array($allcaps)) {
							foreach($allcaps as $cap => $value) {					
								if(strpos($cap, "delete") !== false) {
									$allcaps[$cap] = 0;
								}
							}
						}
					}
				}
			}
			return $allcaps;
		}
	
		/* adds a column to the post list view */
		function page_list_col($cols) {
			$_cols = array();
			if(is_array($cols)) {
				foreach($cols as $col => $value) {
					if($col == "author") {
						$_cols["pilotpress"] = "PilotPress Levels";
					}				
					$_cols[$col] = $value;
				}
			}
			return $_cols;
		}
	
		/* prints value of above */
		function page_list_col_value($column_name, $id) {			
			if ($column_name == "pilotpress") {
				if(in_array($id, $this->system_pages)) {
					echo '<img src="' .$this->get_setting("mr_url", "oap"). 'static/lock-icon-pp.png" width="16" height="16" alt="Locked" />&nbsp;System';
				} else {
					$levels = get_post_meta($id, self::NSPACE.'level', false);						
					if(!empty($levels)) {
						if(count($levels) == 1) {
							echo $levels[0];
						} else {
							echo count($levels)." Levels";
						}
					} else {
						echo '(not set)';
					}
				}
			}
		}
	
		/* handy ajax call for Affiliate Center */
		function get_aff_report() {
			$return = $this->api_call("get_aff_report", $_POST);
			echo($return["report"]);
			die();
		}
	
		/* same but for aff details (setter) */
		function update_aff_details() {
			$return = $this->api_call("update_aff_details", $_POST);
			echo($return["update"]);
			die();
		}
	
		/* same but for cc details (setter) */
		function update_cc_details() {
			global $wpdb;
						
			if(wp_verify_nonce($_POST['nonce'], basename(__FILE__))) {
				
				$return = $this->api_call("update_cc_details", $_POST);
				
				if(isset($return["updateUser"])) {
					$old_user = $_POST["oguser"];
					if($this->get_setting("newusernamefield") == 1) {
						$new_user = $_POST["username"];
					} else {
						$new_user = $_POST["nickname"];
					}
					$user_id = $wpdb->get_var("SELECT ID FROM {$wpdb->users} WHERE user_login = '{$old_user}'");
					$wpdb->query("UPDATE {$wpdb->users} SET user_login = '{$new_user}' WHERE user_login = '{$old_user}'");
					$wpdb->query("UPDATE {$wpdb->usermeta} SET meta_value = '{$_POST["nickname"]}' WHERE user_id = {$user_id} AND meta_key = 'nickname'");
					
				}

				echo($return["update"]);

				$this->end_session();
				
				die();
			}
			
		}
	
		/* grabs form insert code, disables that pesky wpautop */
		function get_insert_form_html(){
		 	if(isset($_POST["form_id"])) {
				remove_filter('the_content', 'wpautop');
				$api_result = $this->api_call("get_form", array("form_id" => $_POST["form_id"]));
				echo $api_result["code"];
				die;
			}
		}
	
		/* grabs video code */
		function get_insert_video_html(){
		 	if(isset($_POST["video_id"])) {
				$api_result = $this->api_call("get_video", array("video_id" => $_POST["video_id"], "width" => '480', "height" => "320", "player" => $_POST["use_player"], "autoplay" => $_POST["use_autoplay"], "viral" => $_POST["use_viral"]));
				echo $api_result["code"];
				die;
			}
		 }

		/* does media insert form itself*/
		function media_upload_type_forms() {

			global $wpdb, $wp_query, $wp_locale, $type, $tab, $post_mime_types;

			media_upload_header();

			?>
			<script type="text/javascript">

				var $ = jQuery;
				
				function insertForm(the_form_id) {
					
					$.post("<?php echo $this->homepage_url; ?>/wp-admin/admin-ajax.php", { action:"pp_insert_form", form_id: the_form_id, 'cookie': encodeURIComponent(document.cookie) },
					 function(str){
						
						if(typeof top.tinyMCE != 'undefined' && (ed = top.tinyMCE.activeEditor)) {

							ed = top.tinyMCE.activeEditor;
							ed.focus();

							if(top.tinymce.isIE) {
								ed.selection.moveToBookmark(top.tinymce.EditorManager.activeEditor.windowManager.bookmark);
							}

							ed.execCommand('mceInsertContent', false, str);
							top.tb_remove();
						} else {
							top.send_to_editor(str);
							top.tb_remove();
						}

					});
				}


			</script>					
			<?php

			$forms_list = $this->api_call("get_form_list","");

			if(is_array($forms_list)) {
				foreach($forms_list as $group => $forms) {
					echo "<div style='padding: 5px; line-height: 16px;'>";
					echo "<h2>{$group}</h2>";
					if(is_array($forms)) {
						echo "<table>";			
						foreach($forms as $idx => $name) {					
							echo "<tr><td><b><a href='JavaScript:insertForm({$idx});' title='form_{$idx}'>{$name}</a></b></td></tr>";
						}
						echo "</table>";	
					}
					echo "</div>";
				}
			}

		}
	
	
		/* same but for videos */
		function media_upload_type_videos() {
			media_upload_header();

			$api_result = $this->api_call("get_video_list","");

			?>

			<style type="text/css">
			div.img
			{
			  background: #EFEFEF;
			  margin:2px;
			  border:1px solid #CCC;
			  height:auto;
			  width:auto;
			  float:left;
			}
			div.img img
			{
			  display:inline;
			  margin:3px;
			  border:1px solid #ffffff;
			}
			div.desc
			{
			  font-size: 10px;
			  width:200px;
			  margin:2px;
			}
			div.controls
			{
			  font-size: 10px;
			}
			div.control_button img {
				padding: 0px;
				margin: 0px;
			}
			div.control_button {
				padding: 0px;
				margin: 0px;
				border: 1px solid #CCC;
			}
			</style>

			<script>
				var $ = jQuery;

				function toggle_autoplay(the_video_id) {
					if($('#autoplay_'+the_video_id).val() != 0) {
						$('#autoplay_'+the_video_id).val(0);
						$('#autoplaybtn_'+the_video_id).css('background-color','#EEE');
					} else {
						$('#autoplay_'+the_video_id).val(1);
						$('#autoplaybtn_'+the_video_id).css('background-color','#CCC');
					}
				}
				
				function toggle_viral(the_video_id) {
					if($('#viral_'+the_video_id).val() != 0) {
						$('#viral_'+the_video_id).val(0);
						$('#viralbtn_'+the_video_id).css('background-color','#EEE');
					} else {
						$('#viral_'+the_video_id).val(1);
						$('#viralbtn_'+the_video_id).css('background-color','#CCC');
					}
				}

				function insertVideo(the_video_id) {
					
					var player = $('#player_'+the_video_id).val();
					var autoplay = $('#autoplay_'+the_video_id).val();
					var viral = $('#viral_'+the_video_id).val();

					$.post("<?php echo $this->homepage_url; ?>/wp-admin/admin-ajax.php", { action: "pp_insert_video", video_id: the_video_id, use_viral: viral, use_player: player, use_autoplay: autoplay, 'cookie': encodeURIComponent(document.cookie) },
					 function(str){						
						var ed;
						if(typeof top.tinyMCE != 'undefined' && (ed = top.tinyMCE.activeEditor)) {

							ed = top.tinyMCE.activeEditor;
							ed.focus();

							if(top.tinymce.isIE) {
								ed.selection.moveToBookmark(top.tinymce.EditorManager.activeEditor.windowManager.bookmark);
							}

							ed.execCommand('mceInsertContent', false, str);
							top.tb_remove();
						} else {
							top.send_to_editor(str);
							top.tb_remove();
						}

					});
				}
			</script>

			<?php

			if(is_array($api_result["list"]) && count($api_result["list"]) > 0) {
				echo "<div style='padding: 5px; line-height: 16px;'>";
				echo "<h2>Videos</h2>";
				foreach($api_result["list"] as $video) {

					if(empty($api_result["thumb_url"]) OR $api_result["thumb_url"] == "") {
						$thumb = $api_result["default_thumb"];
					} else {
						$thumb = $api_result["thumb_url"].$video["thumb_filename"];
					}

					echo "<div class='img' style=\"cursor: pointer;\"><div onClick='insertVideo({$video["video_id"]})'><img width='200' src='{$thumb}'></div>";
					echo "<div class='desc'>{$video["name"]} <span>({$video["duration"]})</span></div>";
					echo "<table><tr><td><select id='player_{$video["video_id"]}' name='player_{$video["video_id"]}'><option value='0'>Hidden</option><option value='1' selected>Player 1</option><option value='2'>Player 2</option><option value='3'>Player 3</option></select></td>";
					echo "<td><input type='hidden' id='autoplay_{$video["video_id"]}' name='autoplay_{$video["video_id"]}' value='0'><div id='autoplaybtn_{$video["video_id"]}' onClick='toggle_autoplay({$video["video_id"]})' style=\"cursor: pointer;\" class=\"control_button floatLeft\"><img title=\"Autoplay\" src=\"".$this->get_setting("mr_url", "oap")."include/images/boxes/autoplay_ico.gif\"></div></td>";
					echo "<td><input type='hidden' id='viral_{$video["video_id"]}' name='viral_{$video["video_id"]}' value='0'><div id='viralbtn_{$video["video_id"]}' onClick='toggle_viral({$video["video_id"]})' style=\"cursor: pointer;\" class=\"control_button floatLeft\"><img title=\"Viral Features\" src=\"".$this->get_setting("mr_url", "oap")."include/images/boxes/viral_vid_ico.gif\"></div></td></tr></table>";
					echo "</div>";
				}
				echo "</div>";
			}

		}
	
	
	
	
	
	
	
		/* headers for images.. never happened */
		function media_upload_type_images() {
			media_upload_header();
			echo "<div style='padding: 5px; line-height: 16px;'>";
			echo "<h2>Images</h2>";
			echo "</div>";
		}
	
		/* binds tab! */
		function modify_media_tab($tabs) {
			$new_tabs = array(
				'forms' =>  __('Forms', 'wp-media-oapforms'),
				'videos' =>  __('Videos', 'wp-media-oapvideos')
				);
			return array_merge($new_tabs, $tabs);
		}
	
		/* shows tab */
		function media_upload_forms() {
		   		wp_iframe(array($this, 'media_upload_type_forms'));
		}

		function media_upload_images() {
		   		wp_iframe(array($this, 'media_upload_type_images'));
		}

		function media_upload_videos() {
		   		wp_iframe(array($this, 'media_upload_type_videos'));
		}
		
		/* this function is disabled for now as it screws up HTML view tidyness... should be an advanced setting in the future */
		function tinymce_autop() {
			?>
				<script type="text/javascript">
				//<![CDATA[
				jQuery('body').bind('afterPreWpautop', function(e, o){
					o.data = o.unfiltered
						.replace(/caption\]\[caption/g, 'caption] [caption')
						.replace(/<object[\s\S]+?<\/object>/g, function(a) {
							return a.replace(/[\r\n]+/g, ' ');
				        });

				}).bind('afterWpautop', function(e, o){
					o.data = o.unfiltered;
				});
				//]]>
				</script>
			<?
		}

		function modify_tinymce() {}
	
		/* south side rockers */
		function media_button_add() {

		        global $post_ID, $temp_ID;

				if($this->is_setup()) {
					$uploading_iframe_ID = (int) (0 == $post_ID ? $temp_ID : $post_ID);
			        $media_upload_iframe_src = "media-upload.php?post_id=$uploading_iframe_ID";
			        $media_oap_iframe_src = apply_filters('media_oap_iframe_src', "$media_upload_iframe_src&amp;type=forms&amp;tab=forms");
			        $media_oap_title = __('Add OfficeAutoPilot Media', 'wp-media-oapform');
			        echo "<a href=\"{$media_oap_iframe_src}&amp;TB_iframe=true&amp;height=500&amp;width=640\" class=\"thickbox\" title=\"$media_oap_title\"><img src=\"".$this->get_setting("mr_url", "oap")."static/media-button-pp.gif\" alt=\"$media_oap_title\" /></a>";
				}
		}
	
		/* this function adds the metaboxes defined in construct() to the WP admin */
		function metabox_add() {
			if($this->is_setup()) {
				foreach($this->metaboxes as $id => $details) {
					foreach($this->get_setting("post_types","wp") as $type) {
						add_meta_box($details['id'], $details['title'], array($this, "metabox_display"), $type, $details['context'], $details['priority']);
					}
				}
			}
		}
	
		/* loop through and save some stuff for us */
		function metabox_save($post_id) {

			if (!wp_verify_nonce($_POST[self::NSPACE.'nonce'], basename(__FILE__))) {
				return $post_id;
			}

		    	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		        	return $post_id;
		    	}

		    	if ('page' == $_POST['post_type']) {
		        	if(!current_user_can('edit_page', $post_id)) {
		            		return $post_id;
		        	}
		    	} elseif (!current_user_can('edit_post', $post_id)) {
		        	return $post_id;
		    	}

			foreach($_POST[self::NSPACE."metaboxes"] as $metabox) {
				foreach ($this->metaboxes[$metabox]["fields"] as $field) {

					if(empty($_POST[$field['id']])) {
						delete_post_meta($post_id, $field['id']);
					}

					if(is_array($_POST[$field['id']])) {
						delete_post_meta($post_id, $field["id"]);
						foreach($_POST[$field['id']] as $new) {
							add_post_meta($post_id, $field['id'], $new);
						}
					} else {
						if(!empty($_POST[$field['id']])) {
							update_post_meta($post_id, $field['id'], $_POST[$field['id']]);
						}
					}
				}
			}
		}


		function metabox_display($post_ref, $pass_thru) {

			global $post;

			echo '<input type="hidden" name="'.self::NSPACE.'nonce" value="', wp_create_nonce(basename(__FILE__)), '" />';
			echo '<input type="hidden" name="'.self::NSPACE.'metaboxes[]" value="'.$pass_thru["id"].'" />';
			echo '<table class="form-table">';

			foreach ($this->metaboxes[$pass_thru["id"]]['fields'] as $field) {

				$meta = get_post_meta($post->ID, $field['id']);

				if(is_array($meta) && count($meta) < 2) {
					$meta = $meta[0];
				}

				if(empty($meta)) {
					$meta = array();
				}

				if($field["type"] != "single-checkbox") {
					echo '<tr><td><label for="', $field['id'], '"><b>', $field['name'], '</b></label><br/>';
				}
				
				switch ($field['type']) {
			
						case "text":
							echo "<input type='text' name='{$field['id']}' id='{$field['id']}'";
							if(!empty($meta)) {
								if(is_array($meta)) {
									echo " value='{$meta[0]}'";
								} else {
									echo " value='{$meta}'";
								}
							}
							echo "><br/>";
						break;
			
		                case 'select':
		                    echo '<select name="', $field['id'], '" id="', $field['id'], '">';
		                    foreach ($field['options'] as $option) {
		                        echo '<option', $meta == $option ? ' selected="selected"' : '', '>', $option, '</option>';
		                    }
		                    echo '</select><br/>';
		                break;
		
		    	    	case 'select-keyvalue':

							if($field["id"] == self::NSPACE."redirect_location") {
								$field["options"] = $this->get_routeable_pages(array($post->ID));
			          		}

		    	            echo '<select name="', $field['id'], '" id="', $field['id'], '">';
		    	            foreach ($field['options'] as $key => $option) {
		    	               echo '<option value="'.$key.'" ', $meta == $key ? ' selected="selected"' : '', '>', $option, '</option>';
		    	            }
		    	            echo '</select><br/>';
							
		    	        break;
		    	    	
						case 'multi-checkbox':
							if(in_array($post->ID, $this->system_pages)) {
								echo "<b style='color: green;'>N/A</b><br/>";
							} else {
								if(is_array($field["options"]) && count($field["options"]) > 0) {
									foreach ($field['options'] as $key => $option) {
										if(is_array($meta)) {
											echo '<input type="checkbox" name="'.$field['id'].'[]" value="'.$option.'" ', in_array($option, $meta) ? ' checked' : '', ' /> ', $option, '<br />';
										} else {
											echo '<input type="checkbox" name="'.$field['id'].'[]" value="'.$option.'" ', $option == $meta ? ' checked' : '', ' /> ', $option, '<br />';
										}
				    	  			}
								}
							}
				  			
		    	        break;
		                case 'radio':
		                    foreach ($field['options'] as $option) {
		    					echo '<input type="radio" name="', $field['id'], '" value="', $option['value'], '"', $meta == $option['value'] ? ' checked="checked"' : '', ' />&nbsp;', $option['name'];
		    					echo "&nbsp;";
		                    }
		                break;
		                case 'single-checkbox':
							echo '<tr><td><input type="checkbox" name="', $field['id'], '" id="', $field['id'], '"', $meta ? ' checked="checked"' : '', ' /> <label for="', $field['id'], '"><b>', $field['name'], '</b></label>';
		                    echo '';
		                break;
		            }

			    if($field["id"] != self::NSPACE."redirect_location") {
					
			    } else {
			    	
			    }

			    if($field["desc"]) {
					echo '<span>'.$field["desc"].'</span><td>';
			    }

			    echo '</tr>';
			}

			echo '</table>';
		}
	
	
	
	
		/* ok, time for some seriousness... this does the login. see additional comments inline */
		function user_login($username, $password) {
			if(isset($_POST["wp-submit"])) {
				$api_result = $this->api_call("authenticate_user", array("site" => site_url(), "username" => $username, "password" => $password));
	
				/* user does exist */
				if(is_array($api_result)) {

					if(!username_exists($username)) {
					
						/* if their email is used (might have been a blog user before OAP perhaps), use alternate name */
						if(email_exists($api_result["email"])) {
							$email = $api_result["email_alt"];
							$email_alt = $api_result["email"];
						} else {
							$email = $api_result["email"];
							$email_alt = $api_result["email_alt"];
						}
	
						/* scary WP create user */
						$create_user = wp_create_user($username, $password, $email);
						
						/* if this errors, tell us! */
						if(isset($create_user->errors) && isset($create_user->errors["existing_user_email"])) {
							unset($create_user);
							$create_user = wp_create_user($username, $password, $email_alt);
						}
								
						if(isset($create_user->errors)) {
							$this->api_call("create_user_error", array("message" => site_url()));				
							return false;
						}

					} else {
						
						/* this user does exist, so log us in */
						$user = get_userdatabylogin($username);

						if($user) {
							/* ruhroh, this person is no longer welcomed! */
							if($api_result["status"] == 0) {
								include_once(ABSPATH.'wp-admin/includes/user.php');
								wp_delete_user($user->ID);
								return;
							} else {
								/* ghetto "sync" of password... saves alot of scary stuff as they are already authenticated.. */
								wp_update_user(array("ID" => $user->ID, "user_pass" => $password));
							}
						} else {
							
							return false;
						}
					}
					
					/* store where the user logged in from for redirection after logout */
					$referrer = $_SERVER['HTTP_REFERER'];
					if(!empty($referrer)) $_SESSION["loginURL"] = $referrer;
					
					$user = get_userdatabylogin($username);
					
					//User is not the admin user... admin doesnt get to have their session set.
					if($user->user_level != 10) {
						
						/* this person is not an admit, so lets make this person special */
						if(defined("COOKIE_DOMAIN") && COOKIE_DOMAIN == "") {
							$cookie_domain = str_replace($this->get_protocol(),"",site_url());
						} else {
							$cookie_domain = COOKIE_DOMAIN;
						}
												
						setcookie("contact_id", $api_result["contact_id"], time()+2419200, COOKIEPATH, $cookie_domain, false);
						
						$user_id = $user->ID;
						wp_set_current_user($user_id, $username);
						wp_set_auth_cookie($user_id);
						do_action('wp_login', $username);

						if(!isset($_SESSION["user_name"])) {

							$this->start_session();

							foreach($api_result as $key => $value) {
								$_SESSION[$key] = $value;
							}
							$_SESSION["user_name"] = $api_result["username"];
							$_SESSION["nickname"] = $api_result["nickname"];
							$_SESSION["user_levels"] = $api_result["membership_level"];
							$_SESSION["user_fields"] = $api_result["fields"];
							$_SESSION["rehash"] = true;
						}
						
						/* where to go from here */
						if(isset($_SESSION["redirect_to"]) && !empty($_SESSION["redirect_to"])) {
							$redirect_to = $_SESSION["redirect_to"];
							
							if(is_numeric($redirect_to)) {
								$redirect_to = get_permalink($redirect_to);
							}
							
							unset($_SESSION["redirect_to"]);
							wp_redirect($redirect_to);
							die;
						}
	
						/* this person is an affiliate, put them somewhere nice */
						if(isset($api_result["program_id"])) {							
							$aff_plr = $this->get_setting("pilotpress_affiliate_plr");
							if($aff_plr && $aff_plr != "-1") {
								wp_redirect(get_permalink($aff_plr));
								die;
								exit;
							} else {								
								wp_redirect(site_url());
								die;
							}
						} else {
														
							$cust_plr = $this->get_setting("pilotpress_customer_plr");							
							if($cust_plr && $cust_plr != "-1") {
								wp_redirect(get_permalink($cust_plr));
								die;
							} else {
								wp_redirect(site_url());
								die;
							}
						}
						die;
					}
				} 
			}
		}
		
		/* redirect the user to a failed login page */
		function user_login_failed() {
			$referrer = $_SERVER['HTTP_REFERER'];
			if(!empty($referrer) && !strstr($referrer, "wp-login") && !strstr($referrer, "wp-admin") ) {
				$_SESSION["loginFailed"] = true;
				wp_redirect($referrer);
				die;
			}
		}
		
		function user_lostpassword() {
			$api_result = $this->api_call("user_lostpassword", array("site" => site_url(), "username" => $_POST['user_login']));

			if(!is_array($api_result))
			{
				/* display invalid username or e-mail message*/
				$_POST['user_login'] = "";
			}
			else
			{
				/* notify user of e-mail, end the rest of WP's processing */
				wp_redirect(site_url() . "/wp-login.php?checkemail=confirm");
				die;
			}
		}
		
		function user_logout() {
			$this->end_session(true);
		}

		static function start_session() {
			ob_start();
			if(!session_id()) {
				session_start();
			}
			ob_end_clean();
		}

		static function end_session($logout = false) {
			
			if($logout) {
				/* redirect the user to where they logged in from */
				if(isset($_SESSION["loginURL"]))
					wp_redirect($_SESSION["loginURL"]);
				else
					wp_redirect(site_url());
			}
					
			ob_start();
			if(session_id()) {
				delete_transient("pilotpress_cache");
			  	if(isset($_COOKIE["contact_id"])) {
					delete_transient("usertags_".$_COOKIE["contact_id"]);
					unset($_COOKIE["contact_id"]);
				}
				unset($_SESSION);
				session_destroy();
			}
			ob_end_clean();

			if($logout) die;
		}

		function filter_query_vars($vars) {
			return $vars;
		}

		function filter_rewrite_rules($rules) {
			global $wp_rewrite;
			$newRule = array('ref/(.+)' => 'index.php?ref='.$wp_rewrite->preg_index(1));
			$newRules = $newRule + $rules;
			return $newRules;
		}

		function flush_rewrite_rules() {
			global $wp_rewrite;
			$wp_rewrite->flush_rules();
		}

		function clean_meta() {
			global $wpdb;
			$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_level' AND meta_value = ''");
		}
	
		/* shortcodes for conditional ifs */
		function shortcode_show_if($atts, $content = null) {
			$user_levels = $this->get_setting("levels","user", true);

			if(!is_array($user_levels)) {
				$user_levels = array();
			}
 						
			if(isset($atts["level"])) {
				if(in_array($atts["level"], $user_levels)) {
					return '<span class="pilotpress_protected">'.$content.'</span>';
				}
			} else {
												
				if(isset($atts["has_one"])) {
					$content_levels = explode(",", $atts["has_one"]);
					foreach($user_levels as $level) {
						if(in_array(ltrim(rtrim($level)), $content_levels)) {
							return '<span class="pilotpress_protected">'.$content.'</span>';
						}
					}
				}
				
				if(isset($atts["has_all"])) {
					$content_levels = explode(",", $atts["has_all"]);
					foreach($content_levels as $level) {
						if(!in_array(ltrim(rtrim($level)), $user_levels)) {
							return false;
						}
					}
					return '<span class="pilotpress_protected">'.$content.'</span>'; 
				}
				
				if(isset($atts["not_one"])) {
					$content_levels = explode(",", $atts["not_one"]);
					foreach($content_levels as $level) {
						if(in_array(ltrim(rtrim($level)), $user_levels)) {
							return false;
						}
					}
					return '<span class="pilotpress_protected">'.$content.'</span>'; 
				}
				
				if(isset($atts["not_any"])) {
					$content_levels = explode(",", $atts["not_any"]);
					foreach($user_levels as $level) {
						if(!in_array(ltrim(rtrim($level)), $content_levels)) {
							return '<span class="pilotpress_protected">'.$content.'</span>';
						}
					}
				}
				
				if(isset($atts[0]) && $atts[0] == "not_contact") {
					if(!$this->get_setting("contact_id","user")) {
						return '<span class="pilotpress_protected">'.$content.'</span>';
					}
				}
				
				if(isset($atts[0]) && $atts[0] == "is_contact") {							
					if($this->get_setting("contact_id","user")) {
						return '<span class="pilotpress_protected">'.$content.'</span>';
					}
				}
				
				if(isset($atts["has_tag"])) {
					$tags = $this->get_setting("tags", "user");
					if(is_array($tags)) {
						if(in_array($atts["has_tag"], $tags)) {
							return '<span class="pilotpress_protected">'.$content.'</span>';
						}
					}
				}

				if(in_array($atts[0], $user_levels)) {
					return '<span class="pilotpress_protected">'.$content.'</span>';
				}
				
			}		
		}

		function shortcode_field($atts, $content = null) {

			extract(shortcode_atts(array("name" => "All"), $atts));

			if(isset($atts["name"])) {
				return $this->get_field($atts["name"]);
			}

		}
		
		/* the big nasty content hiding function... tread carefully */
		function post_process() {
			global $wp, $wpdb, $post;

			if(is_front_page()) {
				return;
			}

			if(isset($post->ID)) {
				$id = $post->ID;
			} else {
				$id = $this->get_postid_by_url();
				if (!$id){
					$id = get_option('page_on_front');
				}
			} 
			
			

			if(!$this->is_viewable($id) && !is_home() && !is_front_page()) {
			
				$redirect = get_post_meta($id, self::NSPACE."redirect_location", true);

				if(!empty($redirect)) {
					
					if($redirect == "-1") {						
						wp_redirect(site_url());
						die;
					}

					if($redirect == "-2"){
						if(!empty($id)) {
							$_SESSION["redirect_to"] = $id;
						}
						wp_redirect(wp_login_url());
						return;
					}

					wp_redirect(get_permalink($redirect));
					die;

				} else {
					wp_redirect($this->homepage_url);
					die;
				}
			} else {
				return;
			}


		}
	
		/* is this a special page? if so render such */
		function content_process($content) {
			global $post;

			if($this->do_login == true) {
				if(!is_user_logged_in()) {
					$content = $this->login_page(array(), 1);
				} else {
					$content = $this->login_page(array(), 2);
				}
				$this->do_login = false;
			} else {
				if(isset($_SESSION["loginFailed"])) {
					$content = $this->login_page(array(), 3);
					unset($_SESSION["loginFailed"]);
				}
				else {
					if(is_page() && in_array($post->ID, $this->system_pages)) {
						$content = $this->do_system_page($post->ID);
					}
				}
			}	

			return $content;
		}
	
		/* this is arguably the nastiest part of PilotPress, but unfortunately WP has consistently decided to not allow non-theme based manipulation of viewable pages */
		function get_routeable_pages($exclude = "") {

			global $post, $wpdb;

			$array = array('-1' => "(homepage)", "-2" => "(login page)");
			
			$query = $wpdb->get_results("SELECT ID, post_title FROM $wpdb->posts WHERE post_status = 'publish' AND (post_type = 'page' OR post_type ='oaplesson') AND post_title != ''");
			
			foreach($query as $index => $page) {
				$array[$page->ID] = $page->post_title;
			}			

			if(is_array($exclude)) {
				foreach($exclude as $id) {
					unset($array[$id]);
				}
			}
			
			return $array;
			
		}
	
		/* this is where we part the seas: if something isn't routable, then tree falls in the woods to no fuss */
		function posts_where($where) {
			global $wpdb;

			if(current_user_can('manage_options')) {
				return $where;
			}

			$level_in = "";
			$user_levels = $this->get_setting("levels","user",true);

			$id = $this->get_postid_by_url();
			if(!empty($id)) {
				if($this->is_viewable($id)) {
					return $where;
				} else {
					$redirect = get_post_meta($id, self::NSPACE."redirect_location", true);
					if($redirect == "-2") {
						$this->do_login = $id;
						return $where;
					}
				}
			}
			
			if(is_array($user_levels)) {
				foreach($user_levels as $level) {
					if(!in_array($level, $this->get_setting("membership_levels", "oap", true))) {
						$level_in .= "'".addslashes($level)."',";
					}
				}
			}

			$level_in = rtrim($level_in,",");
			if(!empty($level_in)) {
				$where .= " AND ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_pilotpress_level' AND meta_value IN ({$level_in}){$post_extra})";
			}			
			return $where;
		}
	
		/* filters nav menu objects */
		function get_pages($pages) {
			global $wpdb;
			
			$show_in_nav = $this->get_setting("show_in_nav", "pilotpress", true);
					
			$filtered = array();
			foreach($pages as $page) {
				if($this->is_viewable($page->ID) OR in_array($page->ID, $show_in_nav)) {
					$filtered[] = $page;
				}
			}
			return $filtered;
		}
		
		function get_nav_menu_objects($menus) {
			$show_in_nav = $this->get_setting("show_in_nav", "pilotpress", true);
			$new_menus = array();	
			foreach($menus as $id => $object) {					
				$object_id = $object->object_id;
				if($this->is_viewable($object_id)) {
					$new_menus[] = $object;
				} else {				
					if(in_array($object_id, $show_in_nav)) {
						$new_menus[] = $object;
					}
				}
			}
			return $new_menus;
		}
	
		/* really returns filtered menus */
		function get_nav_menus($menus) {
			
			$show_in_nav = $this->get_setting("show_in_nav", "pilotpress", true);	
								
			$excludes = array();
			$output = $menus;	
			$xml = @simplexml_load_string($menus);

			if(is_object($xml)) {
				if(isset($xml->ul->li)) {
					foreach($xml->ul->li as $obj) {	
						$post_id = url_to_postid((string)$obj->a->attributes()->href);
						if(!$post_id){
						  	$pages = preg_replace('#^.+/([^/]+)/*$#','$1',(string)$obj->a->attributes()->href);
						  	$query = new WP_Query('pagename='.$pages);
							if($query->is_page) {
								$post_id = $query->queried_object->ID;
							}
						}

						if(!$this->is_viewable($post_id) AND !in_array($post_id, $show_in_nav)) {
							$excludes[] = (string)$obj->attributes()->id;	
						}
					}
				}

				if(count($excludes) > 0) {
					$output = "<style type='text/css'>";
					foreach($excludes as $index => $id) {
						$output .= '#'.$id." { display: none; }\n";
					}
					$output .= "</style>";
					$output .= $menus;
				}
			}

			return $output;
		}
	
		/* i take it back, this is horrible. at the time of writing, WP cannot find what page(s) are being displayed, so this finds it by URL. */
		function get_postid_by_url() {

			global $wp, $wpdb;

			if(isset($wp->query_vars["page_id"])) {
				return $wp->query_vars["page_id"];
			}

			if(isset($wp->query_vars["p"])) {
				return $wp->query_vars["p"];
			} else {

				if(!empty($wp->query_vars["pagename"])) {
					$subpage = explode("/",$wp->query_vars["pagename"]);
					if(count($subpage) > 1) {
						$wp->query_vars["name"] = $subpage[1];
					} else {
						$wp->query_vars["name"] = $wp->query_vars["pagename"];
					}
				}

				if(!empty($wp->query_vars["name"])) {
					$id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_name = '".$wp->query_vars["name"]."'");									
					if(!empty($id)) {
						return $id;
					} else {
						return false;
					}
				} else {
					return false;
				}
			}
		}
	
		/* the most important function for content hiding. this finally decides if something can be seen or not. */
		function is_viewable($id) {				
			global $wpdb, $post;

			$ref = get_query_var("ref");
			if($ref) {
				switch($ref) {
					case "customer_center":
						$page_id = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = 'customer_center'", ARRAY_A);
						if($page_id) {
							wp_redirect(get_permalink($page_id));
							die;
						}
					break;
					case "affiliate_center":
						$page_id = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = 'affiliate_center'", ARRAY_A);
						if($page_id) {
							wp_redirect(get_permalink($page_id));
							die;
						}
					break;
					default:
					break;
				}
			}
			

			if(current_user_can('manage_options')) {
				return true;
			}

			$page_levels = get_post_meta($id, "_pilotpress_level");
			$user_levels = $this->get_setting("levels","user",true);

			if(!is_array($user_levels)) {
				$user_levels = array($user_levels);
			}
			
			
			if(in_array($id, $this->system_pages)) {
				if(!is_user_logged_in()) {
					return false;
				} else {
					return true;
				}
			}

			if(count($page_levels) == 0 OR empty($page_levels)) {
				return true;
			}

			if(count($page_levels) > 0) {
				if(count($user_levels) == 0) {
					return false;
				} else {
					foreach($user_levels as $level) {				
						if(in_array($level, $page_levels)) {
							return true;
						}
					}
					return false;	
				}
			}	
		}
	
		/* simple getter */
		function get_system_pages() {
			global $wpdb;
			$return = array();
			$results = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page'", ARRAY_A);
			if(is_array($results)) {
				foreach($results as $q_post) {
					$return[] = $q_post["post_id"];
				}
			}
			return $return;
		}
	
		/* renders a system page */
		function do_system_page($id) {

			$type = get_post_meta($id, self::NSPACE."system_page", true);

			if(!is_user_logged_in()) {
				$return = $this->login_page(array(), 1);
				return $return;
			}

			if($type == "affiliate_center") {
				$api_result = $this->api_call("get_".$type, array("username" => $_SESSION["user_name"], "program_id" => $_SESSION["program_id"], "site" => site_url()));
			}  

			if($type == "customer_center"){
				$api_result = $this->api_call("get_".$type, array("username" => $_SESSION["user_name"], "site" => site_url(), "nonce" => wp_create_nonce(basename(__FILE__))));
			}	

			if($api_result) {
				if($api_result["code"] != "0") {
					return $api_result["code"];
				} else {
					$return = $this->login_page(array(), 2);
					return $return;
				}
			}
		}
	
		/* creates a system page in a post somewhere */
		function create_system_page($name) {
			global $wpdb;
			$pages = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = '{$name}'", ARRAY_A);
			if(count($pages) == 0) {
				$post = array(
					'post_title' => "{$this->centers[$name]["title"]}",
					'slug' => "{$this->centers[$name]["slug"]}",
					'post_status' => 'publish', 
					'post_type' => 'page',
					'comment_status' => "closed",
					'visibility' => "public",
					'ping_status' => "closed",
					'post_category' => array(1),
					'post_content' => "{$this->centers[$name]["content"]}");					
				$post_id = wp_insert_post($post);
				add_post_meta($post_id, PilotPress::NSPACE."system_page", $name);
				add_post_meta($post_id, PilotPress::NSPACE."redirect_location", "-2");
				$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'trash' AND post_name = '{$name}'");
				$wpdb->query("UPDATE $wpdb->posts SET post_name = '{$name}' WHERE ID = '{$post_id}'");
				$this->flush_rewrite_rules();
			}
		}
	
		/* banished. */
		function delete_system_page($name) {
			global $wpdb;
			$pages = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = '{$name}'", ARRAY_A);
			if(!empty($pages)) {
				foreach($pages as $page) {
					delete_post_meta($page["post_id"], "_pilotpress_system_page");
					wp_delete_post($page["post_id"], true);
				}	
			}
		}
	
		/* renders cute login page */
		function login_page($atts, $message = false) {

			global $wpdb;

			$output = "<style type='text/css'>#loginform p { margin: 1px; padding: 0px; } .login-submit { margin-bottom: 0px; } .login_box { padding: 2px; padding-left: 4px; border: 1px solid #E6D855; background-color: lightYellow; }</style>";
			
			if(!empty($message)) {
				switch($message) {
					case "1":
						$output_message = "Must be logged in to see this page.";
					break;
					case "2":
						$output_message = "You do not have sufficient access to view this page.";
					break;
					case "3":
						$output_message = "Invalid Username or Password.";
					break;
					default:
						$output_message = $message;
					break;
				}
				$output .= "<p class='login_box' id='login_message_normal'>{$output_message}</p>";
			}
			
			if(isset($_SESSION["redirect_to"]) && !empty($_SESSION["redirect_to"])) {
				$redirect = get_permalink($_SESSION["redirect_to"]);
			} else {
				$redirect = site_url($_SERVER['REQUEST_URI']);
			}
			
			$args = array(
			        'echo' => false,
			        'redirect' => $redirect, 
			        'form_id' => 'loginform',
			        'label_username' => __('Username'),
			        'label_password' => __('Password'),
			        'label_remember' => __('Remember Me'),
			        'label_log_in' => __('Log In'),
			        'id_username' => 'user_login',
			        'id_password' => 'user_pass',
			        'id_remember' => 'rememberme',
			        'id_submit' => 'wp-submit',
			        'remember' => true,
			        'value_username' => NULL,
			        'value_remember' => true);			
			$output .= wp_login_form($args);
						
			return $output;

		}
		
		/* the first process... enable the plugin create some values and cleanup "older" PilotPress metadata. could probably do with a redo. */
		public function do_enable() {

			global $wpdb;

			$data = array();
			$data["site"] = site_url();
			$data["version"] = self::VERSION;
			$data["url"] = $this->uri."/".basename(__FILE__);
			$api_result = $this->api_call("enable_pilotpress", $data);

			$um = array();
			$user = get_userdatabylogin("pilotpress-user");
			if(isset($user->ID)) {
				wp_delete_user($user->ID);
			}

			$meta = $wpdb->get_results("SELECT meta_id, post_id, meta_key, meta_value FROM $wpdb->postmeta WHERE meta_key LIKE '".PilotPress::NSPACE."%'");
			foreach($meta as $result) {

				if($result->meta_key == "_pilotpress_system_page" && $result->meta_value == "1") {
					delete_post_meta($result->post_id, $result->meta_key);
				}

				if($result->meta_key == "_pilotpress_affiliate_center") {
					$ac_exists = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = 'affiliate_center'");

					if(empty($ac_exists)) {
						delete_post_meta($result->post_id, $result->meta_key);
						add_post_meta($post_id, PilotPress::NSPACE."system_page", "affiliate_center");
						wp_update_post(array("ID" => $post_id, "post_content" => "This content will be replaced by the Affiliate Center."));
					}
				}

				if($result->meta_key == "_pilotpress_customer_center") {
					$cc_exists = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = 'customer_center'");

					if(empty($cc_exists)) {
						delete_post_meta($result->post_id, $result->meta_key);
						add_post_meta($post_id, PilotPress::NSPACE."system_page", "customer_center");
						wp_update_post(array("ID" => $post_id, "post_content" => "This content will be replaced by the Customer Center."));
					}
				}

				if($result->meta_key == "_pilotpress_user_level") {
					if($result->meta_value == "All") {
						delete_post_meta($result->post_id, $result->meta_key);
					} else {
						$um[$result->meta_value][] = $result->post_id;
					}
				}
			}

			if(isset($api_result["upgrade"])) {
				$levels = $this->get_setting("membership_levels", "oap");
				$keys = array_flip($levels);								
				if(count($um) > 0) {
					foreach($keys as $level => $pos) {
						$rec = array_slice($levels, $pos);
						if(isset($um[$level])) {
							foreach($um[$level] as $idx => $post_id) {
								foreach($rec as $value) {
									add_post_meta($post_id, PilotPress::NSPACE."level", $value);
								}
								delete_post_meta($post_id, PilotPress::NSPACE."user_level");
							}
						}
					}
				}
			}
		}
	
		/* let us know */
		public function do_disable() {
			$data = array();
			$data["site"] = site_url();
			$data["version"] = self::VERSION;
			$data["url"] = $this->uri."/".basename(__FILE__);
			$return = $this->api_call("disable_pilotpress", $data);
		}
	
		/* used by external plugins: get items available to this account */
		static function get_oap_items() {
			$options = get_option("pilotpress-settings");
			if(!empty($options)) {
				if(isset($options["app_id"]) && isset($options["api_key"])) {
					$return = array();
					$return["files"] = PilotPress::api_call_static("get_files_list", "", $options["app_id"], $options["api_key"], $options["disablesslverify"]);
					$return["videos"] = PilotPress::api_call_static("get_video_list", "", $options["app_id"], $options["api_key"], $options["disablesslverify"]);
					return $return;
				}
			}
			return false;
		}
		
		/* grab some video code! also for external plugins */
		static function get_oap_video($video_id) {
			$options = get_option("pilotpress-settings");
			if(!empty($options)) {
				if(isset($options["app_id"]) && isset($options["api_key"])) {
					$return= PilotPress::api_call_static("get_video", 
														array(
															"video_id" => $video_id, 
															"width" => '480', 
															"height" => "320", 
															"player" => 1, 
															"autoplay" => 0, 
															"viral" => 0
														), 
														$options["app_id"], 
														$options["api_key"], 
														$options["disablesslverify"]);
					return $return;
				}
			}
			return false;
		}

	}
	
	function enable_pilotpress() {
		$pilotpress = new PilotPress;
		$pilotpress->do_enable();
	}
	
	function disable_pilotpress() {
		$pilotpress = new PilotPress;
		$pilotpress->do_disable();
	}
	/* remember Xevious? this is it... render some JS for the tinymce plugin! */
	if(isset($_GET["ping"])) {
		switch($_GET["ping"]) {
			case "s":
				echo json_encode(array("version" => PilotPress::VERSION));
			break;
			case "js":

				PilotPress::start_session();
				header("Content-Type: text/javascript");
				?>
				(function(){

				    tinymce.PluginManager.requireLangPack('pilotpress');

				    tinymce.create('tinymce.plugins.pilotpress', {

				        init : function(ed, url){
				        },
				        createControl : function(n, cm){
							switch(n) {
								case "merge_fields":
									var mlb = cm.createListBox('merge_fields', {
									                     title : 'Merge Fields',
									                     onselect : function(v) {
													     	if(v!="") {
																tinyMCE.activeEditor.execCommand('mceInsertContent', false, '[field name=\''+v+'\']');
															}	
														 }
									                });


									<?php
										$fields = $_SESSION["default_fields"];
										if(!empty($fields) && is_array($fields)) {
											foreach($fields as $group => $items) {
												echo "                                    ";
												echo "mlb.add('".addslashes($group)."', '');\n";
												foreach($items as $key => $value) {
													 echo "                                    ";
													 echo "mlb.add(' + ".addslashes($key)."', '".addslashes($key)."');\n";
												}
											}
										}
									?>

									return mlb;


								break;
							}
				            return null;
				        },

				        getInfo : function(){
				            return {
				                longname: 'PilotPress',
				                author: 'MoonRay LLC',
				                authorurl: 'http://officeautopilot.com/',
				                infourl: 'http://officeautopilot.com/',
				                version: "<?php echo PilotPress::VERSION; ?>"
				            };
				        }
				    });
				    tinymce.PluginManager.add('pilotpress', tinymce.plugins.pilotpress);
				})();
				<?php
			break;
			default:
				echo "goodPing();";
				die;
			break;
		}
		die;
	}
	
?>