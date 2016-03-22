<?php 
/*
Plugin Name: PilotPress
Plugin URI: http://ontraport.com/
Description: OfficeAutoPilot / ONTRAPORT WordPress integration plugin.
Version: 1.8.6
Author: ONTRAPORT Inc.
Author URI: http://ontraport.com/
Text Domain: pilotpress
Copyright: 2013, Ontraport
*/

	if(defined("ABSPATH")) {
		include_once(ABSPATH.WPINC.'/class-http.php');
		global $wp_version;
		if (version_compare($wp_version,"3.1","<"))
		{
			include_once(ABSPATH.WPINC.'/registration.php');
		}
		register_activation_hook(__FILE__, "enable_pilotpress");
		register_deactivation_hook(__FILE__, "disable_pilotpress");
		$pilotpress = new PilotPress;
		//create and load up the PilotPress Text Widget statically
		add_action( 'widgets_init',array( 'PilotPress_Widget', 'register' ) );
		//Hook into the admin footer so as to load this JS 
		add_action( 'admin_footer-widgets.php' , "pilotpress_widget_js" );
	}


	
	class PilotPress {

        const VERSION = "1.8.6";
		const WP_MIN = "3.0";
		const NSPACE = "_pilotpress_";
		const URL_JQCSS = "https://ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/smoothness/jquery-ui.css";
        const AUTH_SALT = "M!E%VxpKvuQHn!PTPOTohtLbnOl&)5&0mb(Uj^c#Zz!-0898yfS#7^xttNW(x1ia";

		public $system_pages = array();
	
		public static $brand = "OfficeAutoPilot";
		public static $brand_url = "OfficeAutoPilot.com";
		public static $url_api = "https://www1.moon-ray.com/api.php";
		public static $backup_url_api = "https://web.moon-ray.com/api.php";
		public static $url_tjs = "https://www1.moon-ray.com/tracking.js";
		public static $url_jswpcss = "https://forms.moon-ray.com/v2.4/include/scripts/moonrayJS/moonrayJS-only-wp-forms.css";
		public static $url_mrcss = "https://forms.moon-ray.com/v2.4/include/minify/?g=moonrayCSS";

		/* Various runtime, shared variables */
		private $uri;
		private $metaboxes;
		private $settings;
		private $status = 0;
		private $do_login = false;
		private $homepage_url;
		private $incrementalnumber = 1;
		private $tagsSequences;
	
		function __construct() {
	
			$this->bind_hooks(); /* hook into WP */
			$this->start_session(); /* use sessions, controversial but easy */

			/* Used for keeping a record of the current shortcodes to be merged */
			$this->shortcodeFields = array();
			
			/* use this var, it's handy */
			$this->uri = get_option('siteurl').'/wp-content/plugins/'.dirname(plugin_basename(__FILE__));
			
			/* the various Centers */
			$this->centers = array(
				"customer_center" => array(
					"title" => "Customer Center",
					"slug" => "customer-center",
					"content" => "This content will be replaced by the Customer Center"
				),
				"affiliate_center" => array(
					"title" => "Partner Center",
					"slug" => "affiliate-center",
					"content" => "This content will be replaced by the Partner Center"
				),
			);
		}
		
		/* this function loads up runtime settings from API or transient caches for both plugin and user (if logged in) */
		function load_settings() {

			global $wpdb;
			
			$this->system_pages = $this->get_system_pages();

			if($this->get_setting("disablecaching") && get_transient('pilotpress_cache') && !isset($_SESSION["rehash"])) { 
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
				
				$this->api_version = get_option("pilotpress_api_version");

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

					$app_id = explode("_", $this->get_setting("app_id"));
					if(count($app_id) == 3) {
						if($app_id[1] > 20000 || $this->api_version ) {
							self::$brand = "ONTRAPORT";
							self::$brand_url = "ontraport.com";

							self::$url_api = "https://api.ontraport.com/pilotpress.php";
							self::$backup_url_api = "https://api.ontraport.com/pilotpress.php";

							self::$url_tjs = "https://optassets.ontraport.com/tracking.js";
							self::$url_jswpcss = "https://forms.ontraport.com/v2.4/include/scripts/moonrayJS/moonrayJS-only-wp-forms.css";
							self::$url_mrcss = "https://forms.ontraport.com/v2.4/include/minify/?g=moonrayCSS";
						}

                        // for debugging
                        if(is_file(ABSPATH . "/pp_debug_include.php"))
                        {
                            include_once(ABSPATH . "/pp_debug_include.php");
                        }
					}

					//check if these are stored in the cache first
					$pilotPressTrackingURL = get_transient("pilotpress_tracking_url");
					$pilotPressTracking = get_transient("pilotpress_tracking");
					$getSiteSettings = true;

					if ($pilotPressTrackingURL !== false && $pilotPressTracking !== false)
					{
						$this->settings["oap"]["tracking_url"] = $pilotPressTrackingURL;
						$this->settings["oap"]["tracking"] = $pilotPressTracking;
						$getSiteSettings = false;
					}

					//Check to make sure we really need to even make this API call...
					if (is_user_logged_in() || $getSiteSettings )
					{
						//Only make use of cookie if not an admin user.
						if(isset($_COOKIE["contact_id"]) && !current_user_can('manage_options')) {
							global $current_user;
							get_currentuserinfo();
							$username = $current_user->user_login;
							$api_result = $this->api_call("get_site_settings", array("site" => site_url(), "contact_id" => $_COOKIE["contact_id"], "username" => $username , "version"=>self::VERSION ));
						}
						else {
							$api_result = $this->api_call("get_site_settings", array("site" => site_url() , "version"=>self::VERSION ));
						}

						if(is_array($api_result)) {
							$this->settings["oap"] = $api_result;
							
							if(isset($this->settings["user"])) {
								unset($this->settings["user"]);
							}
							
							if(!$this->get_setting("disablecaching")) {
								set_transient('pilotpress_cache', $this->settings, 60 * 60 * 12); 
							}
																		
							$_SESSION["default_fields"] = $this->settings["oap"]["default_fields"];

							if(isset($api_result["membership_level"])) {
								$_SESSION["user_levels"] = $api_result["membership_level"];
								if(!empty($username))
								{
									$_SESSION["user_name"] = $username;
								}							
							}
							$this->status = 1;

							//Lets store the API version into their options table if available
							if (isset($api_result["pilotpress_api_version"]))
							{
								update_option("pilotpress_api_version" , $api_result["pilotpress_api_version"]);
							}

							//Cache the tracking link and custom domain so we can avoid calling this every page load
							if (isset($api_result["tracking_url"]))
							{
								set_transient('pilotpress_tracking_url', $api_result["tracking_url"],60 * 60 * 24);
							}

							if (isset($api_result["tracking_url"]))
							{
								set_transient('pilotpress_tracking', $api_result["tracking"],60 * 60 * 24);
							}							

						}
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
				if(isset($fields[$key])) 
				{
					return $fields[$key];
				}
				else if (isset($fields[html_entity_decode($key)]))
				{
					return $fields[html_entity_decode($key)];
				}
				
			}

			foreach($this->get_setting("default_fields", "oap", true) as $group => $fields) {
				if(isset($fields[$key])) 
				{
					return $fields[$key];
				}
				else if (isset($fields[html_entity_decode($key)]))
				{
					return $fields[html_entity_decode($key)];
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
			
			if (isset($_SESSION['contact_id'])) {
				$return["contact_id"] = $_SESSION["contact_id"];
			}
						
			if(isset($_SESSION["user_levels"])) {
				$return["name"] = $_SESSION["user_name"];
				$return["username"] = $_SESSION["user_name"];
				$return["nickname"] = $_SESSION["nickname"];
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
			add_settings_field('wp_userlockout', __('Lock all users without Admin role out of profile editor', 'pilotpress'), array(&$this, 'display_settings_userlockout'), 'pilotpress-settings', 'pilotpress-settings-general');

			add_settings_section('settings_section_oap', __(self::$brand . ' Integration Settings', 'pilotpress'), array(&$this, 'settings_section_oap'), 'pilotpress-settings'); 
			add_settings_field('customer_center',  __('Enable Customer Center', 'pilotpress'), array(&$this, 'display_settings_cc'), 'pilotpress-settings', 'settings_section_oap');
			add_settings_field('affiliate_center',  __('Enable Partner Center', 'pilotpress'), array(&$this, 'display_settings_ac'), 'pilotpress-settings', 'settings_section_oap');

			add_settings_section('pilotpress-redirect-display', __('Post Login Redirect Settings', 'pilotpress'), array(&$this, 'settings_section_redirect'), 'pilotpress-settings'); 
			add_settings_field('pilotpress_customer_plr', __('Customers Redirect To', 'pilotpress'), array(&$this, 'display_settings_customer_plr'), 'pilotpress-settings', 'pilotpress-redirect-display');
			add_settings_field('pilotpress_affiliate_plr', __('Partners Redirect To', 'pilotpress'), array(&$this, 'display_settings_affiliate_plr'), 'pilotpress-settings', 'pilotpress-redirect-display');

			//Add the Customer Center Settings
			add_settings_section('pilotpress-customer-center-display', __('Customer Center Settings', 'pilotpress'), array(&$this, 'settings_section_customer_settings'), 'pilotpress-settings'); 
			add_settings_field('pilotpress_customer_center_header_image', __('Custom Header Image', 'pilotpress'), array(&$this, 'display_settings_customer_center_header_image'), 'pilotpress-settings', 'pilotpress-customer-center-display');
			add_settings_field('pilotpress_customer_center_primary_color', __('Primary Color', 'pilotpress'), array(&$this, 'display_settings_customer_center_primary_color'), 'pilotpress-settings', 'pilotpress-customer-center-display');
			add_settings_field('pilotpress_customer_center_secondary_color', __('Secondary (Background) Color', 'pilotpress'), array(&$this, 'display_settings_customer_center_secondary_color'), 'pilotpress-settings', 'pilotpress-customer-center-display');

			//Add the New User Register Settings
			add_settings_section('pilotpress-new-user-display', __('New User Register Settings', 'pilotpress'), array(&$this, 'settings_section_new_user_settings'), 'pilotpress-settings'); 
			add_settings_field('pilotpress_add_newly_registered_users', __('Add newly registered WordPress users to your ONTRAPORT contacts', 'pilotpress'), array(&$this, 'display_settings_add_newly_registered_users'), 'pilotpress-settings', 'pilotpress-new-user-display');
			add_settings_field('pilotpress_newly_registered_tags', __('What tags should they have?', 'pilotpress'), array(&$this, 'display_settings_newly_registered_tags'), 'pilotpress-settings', 'pilotpress-new-user-display');
			add_settings_field('pilotpress_newly_registered_sequences', __('What sequences should they be on?', 'pilotpress'), array(&$this, 'display_settings_newly_registered_sequences'), 'pilotpress-settings', 'pilotpress-new-user-display');


			//Add the Logout Settings
			add_settings_section('pilotpress-logout-users-display', __('Logout Settings', 'pilotpress'), array(&$this, 'settings_section_logout_settings'), 'pilotpress-settings'); 
			add_settings_field('pilotpress_logout_users', __('Would you like to keep user\'s logged into your site longer than normal?  <br /> <br /> <i>(*Please note that if the browser is closed for a long period the user will have to log in again.</i>) ', 'pilotpress'), array(&$this, 'display_settings_logout_users'), 'pilotpress-settings', 'pilotpress-logout-users-display');
		

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
		
		/**  @brief settings hook for showing the customer center header image 	*/
		function display_settings_customer_center_header_image()
		{
			$setting = $this->get_setting("pilotpress_customer_center_header_image");
			if (!$setting){
				$setting = "";
			}

			$output = "<input name='pilotpress-settings[pilotpress_customer_center_header_image]' class='pilotpress_customer_center_header_image_url' type='text' name='header_logo' size='60' value='$setting'>
                <a href='#' class='button pilotpress_header_logo_upload'>Upload</a>";

			echo $output;

		}

		/**  @brief settings hook for showing the customer center primary color	*/
		function display_settings_customer_center_primary_color()
		{
			$setting = $this->get_setting("pilotpress_customer_center_primary_color");
			if (!$setting){
				$setting = "";
			}
			$output = "<input type='text' name='pilotpress-settings[pilotpress_customer_center_primary_color]' id='primary-color' value='".$setting."' data-default-color='#ffffff' class='pilotpress-color-picker' />";

			echo $output;

		}

		/**  @brief settings hook for showing the customer center secondary (background) color 	*/
		function display_settings_customer_center_secondary_color()
		{
			$setting = $this->get_setting("pilotpress_customer_center_secondary_color");
			if (!$setting){
				$setting = "";
			}
			$output = "<input type='text' name='pilotpress-settings[pilotpress_customer_center_secondary_color]' id='secondary-color' value='".$setting."' data-default-color='#ffffff' class='pilotpress-color-picker' />";

			echo $output;			
		}

		/** @brief displays the setting for the option to add new registered users to ONTRAPORT */
		function display_settings_add_newly_registered_users()
		{
			$setting = $this->get_setting("pilotpress_add_newly_registered_users");
			if (!$setting){
				$setting = "-1";
			}
			echo  "<select name=pilotpress-settings[pilotpress_add_newly_registered_users]>";
			echo  "<option value='0' ".selected( $setting, 0 ).">No</option>";
			echo  "<option value='1' ".selected( $setting, 1 ).">Yes</option>";
			echo  "</select>";
		}

		/** @brief displays the setting for the sequences that should be added to the new user */
		function display_settings_newly_registered_sequences() 
		{
			$setting = $this->get_setting("pilotpress_newly_registered_sequences");
			if (!$setting){
				$setting = "-1";
			}
			$output = "<select multiple name=pilotpress-settings[pilotpress_newly_registered_sequences][]>";
			$sequences = json_decode($this->tagsSequences["sequences"] ,true );
			foreach($sequences as $sequence)
			{
				$selected = "";
				if(is_array($setting))
				{
					if (in_array($sequence["drip_id"] , $setting))
					{
						$selected = "selected='selected'";
					}
				}
				$output .= "<option value='".$sequence["drip_id"]."' ".$selected . ">".$sequence["name"]."</option>";
			}
			$output .= "</select>";
			echo $output;			
		}

		/** @brief displays the setting for the tags to be added to new users */
		function display_settings_newly_registered_tags() 
		{
			$setting = $this->get_setting("pilotpress_newly_registered_tags");
			if (!$setting){
				$setting = "";
			}
			$output = "<select multiple name=pilotpress-settings[pilotpress_newly_registered_tags][]>";
			$tags = json_decode($this->tagsSequences["tags"] , true );
			foreach($tags as $tag)
			{
				$selected = "";
				if(is_array($setting))
				{
					if (in_array($tag["tag_name"] , $setting))
					{
						$selected = "selected='selected'";
					}
				}
				$output .= "<option value='".$tag["tag_name"]."' ".$selected.">".$tag["tag_name"]."</option>";
			}
			$output .= "</select>";
			echo $output;
		}

		/** @brief displays the setting for enabling or disabling logout duration settings */
		function display_settings_logout_users()
		{
			$setting = $this->get_setting("pilotpress_logout_users");
			if (!$setting){
				$setting = "-1";
			}
			echo  "<select name=pilotpress-settings[pilotpress_logout_users]>";
			echo  "<option value='0' ".selected( $setting, 0 ).">No</option>";
			echo  "<option value='1' ".selected( $setting, 1 ).">Yes</option>";
			echo  "</select>";		
		}

		/* section output, blank for austerity */
		function settings_section_customer_settings() {}
		function settings_section_new_user_settings() {}
		function settings_section_logout_settings() {}
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
				_e('PilotPress must be configured with an ' . self::$brand . ' API Key and App ID.', 'pilotpress');

				if($_GET['page'] != 'pilotpress-settings') {
					_e(sprintf('Go to the <a href="%s" title="PilotPress Admin Page">PilotPress Admin Page</a> to finish setting up your site!', 'options-general.php?page=pilotpress-settings'), 'pilotpress');
					echo ' ' ;
					_e(sprintf('You need an <a href="%s" title="Visit '. self::$brand_url .'">' . self::$brand . '</a> account to use this plugin.', 'http://' . self::$brand_url));
					echo ' ';
					_e('Don\'t have one yet?', 'pilotpress');
					echo ' ';
					_e(sprintf('<a href="%s" title="' . self::$brand . ' SignUp">Sign up</a> now!', 'http://' . self::$brand_url, 'pilotpress'));				
				}

				echo '</div>';
			}

			if(!$this->is_setup() && $this->get_setting('api_key') && $this->get_setting('app_id')) {
				echo '<div class="error" style="padding-top: 5px; padding-bottom: 5px;">';
				_e('Either this site <b>'.str_replace("http://","",(string)site_url()).'</b> is not configured in ' . self::$brand . ' or the <a href="options-general.php?page=pilotpress-settings">API Key / App Id settings</a> are incorrect. ', 'pilotpress');
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

			//get the sequences and tags...	
			$this->tagsSequences = $this->api_call("get_tags_sequences", array("site" => site_url()));
			
			?>			
			<div class="wrap"><h2><?php _e('PilotPress Settings', 'pilotpress'); ?></h2><?php

			?><form name="pilotpress-settings" method="post" action="options.php"><?php

			settings_fields('pilotpress-settings');
			do_settings_sections('pilotpress-settings');
			
			?>
						
			<p class="submit"><input type="submit" class="button-primary" name="save" value="<?php _e('Save Changes', 'pilotpress'); ?>" />&nbsp;<input type="button" class="button-secondary" name="advanced" value="<?php _e('Advanced Settings', 'pilotpress'); ?>"></p></form></div>
			
			<script type="text/javascript">
				jQuery(document).ready(function() {
					jQuery(document).find("[name=pilotpress-settings] h3:eq(6)").toggle();
					jQuery(document).find(".pilotpress-advanced-warning").toggle();
					jQuery(document).find("[name=pilotpress-settings] table:eq(6)").toggle();
					jQuery(document).find("[name=advanced]").click(function() {
						jQuery(document).find("[name=pilotpress-settings] h3:eq(6)").toggle();
						jQuery(document).find(".pilotpress-advanced-warning").toggle();
						jQuery(document).find("[name=pilotpress-settings] table:eq(6)").toggle();
					});

					//media uploader
 					jQuery('.pilotpress_header_logo_upload').click(function(e) {
			            e.preventDefault();

			            var custom_uploader = wp.media({
			                title: 'Customer Center Header Image',
			                button: {
			                    text: 'Upload Image'
			                },
			                multiple: false  // Set this to true to allow multiple files to be selected
			            })
			            .on('select', function() {
			                var attachment = custom_uploader.state().get('selection').first().toJSON();
			                jQuery('.pilotpress_customer_center_header_image').attr('src', attachment.url);
			                jQuery('.pilotpress_customer_center_header_image_url').val(attachment.url);

			            })
			            .open();
			        });
				//primary color picker init
				jQuery('#primary-color.pilotpress-color-picker').iris();
				jQuery('#primary-color.pilotpress-color-picker').iris({ change: function(event, ui)
                {
                  var colorpickervar = jQuery("#primary-color.pilotpress-color-picker").val()
                  jQuery("#primary-color.pilotpress-color-picker").siblings('.iris-border').css('background-color', colorpickervar);
                }
            	});

				//secondary color picker init
				jQuery('#secondary-color.pilotpress-color-picker').iris();
				jQuery('#secondary-color.pilotpress-color-picker').iris({ change: function(event, ui)
                {
                  var colorpickervar = jQuery("#secondary-color.pilotpress-color-picker").val()
                  jQuery("#secondary-color.pilotpress-color-picker").siblings('.iris-border').css('background-color', colorpickervar);
                }
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

			$endpoint = sprintf(self::$url_api.'/%s/%s/%s', "json", "pilotpress", $method);
			$response = wp_remote_post($endpoint, $post);
			if(is_object($response))
			{
				if ($response->errors['http_request_failed']){
					$endpoint = sprintf(self::$backup_url_api.'/%s/%s/%s', "json", "pilotpress", $method);
					$response = wp_remote_post($endpoint, $post);
				}
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

			/* hitup the API or grab transient */
			add_action("init", array(&$this, "load_settings") , 1);
			add_action("init", array(&$this, "load_scripts") , 10);
			add_action('init', array(&$this,'sessionslap_ping'));
			add_action('wp_print_styles', array(&$this, 'stylesheets'));
			add_action('wp_print_footer_scripts', array(&$this, 'tracking'));
			add_action('retrieve_password', array(&$this, 'retrieve_password'));
			add_action('profile_update', array(&$this, 'profile_update'));
			
			add_action("wp_ajax_pp_update_aff_details", array(&$this, 'update_aff_details'));
			add_action("wp_ajax_pp_update_cc_details", array(&$this, 'update_cc_details'));

			if(is_admin()) {
				add_action('admin_menu', array(&$this, 'settings_init'));
				add_filter('admin_init', array(&$this, 'clean_meta'));
				add_filter('admin_init', array(&$this, 'flush_rewrite_rules'));
				add_filter('admin_init', array(&$this, 'user_lockout'));
				add_action('admin_enqueue_scripts', array(&$this, 'admin_load_scripts'));
				add_action('admin_notices', array(&$this, 'display_notice'));

				add_action('admin_menu', array(&$this, 'metabox_add'));
				add_action('pre_post_update', array(&$this, 'metabox_save'));

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
				add_filter('mce_buttons_3', array(&$this, 'mce_buttons'));

				add_filter('manage_posts_columns', array(&$this, 'page_list_col'));
				add_action('manage_posts_custom_column', array(&$this, 'page_list_col_value'), 10, 2);
				add_filter('manage_pages_columns', array(&$this, 'page_list_col'));
				add_action('manage_pages_custom_column', array(&$this, 'page_list_col_value'), 10, 2);
				add_filter('user_has_cap', array(&$this, 'lock_delete'), 0, 3);
				add_filter('media_upload_tabs', array(&$this, 'modify_media_tab'));
				add_action('wp_loaded', array(&$this, 'update_post_types'));

				// For login_form
				add_action('admin_head', array(&$this, 'include_form_admin_options'));
				
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

				add_shortcode('pilotpress_protected', array(&$this, 'shortcode_show_if'));
				add_shortcode('pilotpress_show_if', array(&$this, 'shortcode_show_if'));
				add_shortcode('pilotpress_login_page', array(&$this, 'login_page'));
				add_shortcode('pilotpress_field', array(&$this, 'shortcode_field'));
			}

			add_action('wp_authenticate', array(&$this, 'user_login'), 1, 2);
			add_action("wp_login_failed", array(&$this, 'user_login_failed'));
			add_action("lostpassword_post", array(&$this, 'user_lostpassword'));
			add_action('wp_logout', array(&$this, 'user_logout'));
			add_action('init', array(&$this, 'pp_login_button'));
			add_action('user_register', array(&$this, 'add_new_register_user_to_ONTRAPORT') , 10, 1);

		}

		function retrieve_password($name) {
			$return = $this->api_call("retrieve_password", array("site" => site_url(), "username" => $name));
		}
		
		/* update a persons profile */
		function profile_update($user_id) {
			if(isset($_POST['first_name']) && isset($_POST['last_name']) && isset($_POST['nickname']) && isset($_POST['pass1'])) {
				$user = get_userdata($user_id);
				
				$details = array();
				$details["site"] = site_url();
				$details["username"] = $user->user_login;
				$details["firstname"] = $_POST['first_name'];
				$details["lastname"] = $_POST['last_name'];
				$details["nickname"] = $_POST['nickname'];
				$details["password"] = $_POST["pass1"];
				
				$return = $this->api_call("profile_update", $details);
			}
		}

		function user_lockout() {
			global $current_user;
			if(!current_user_can('manage_options') && $this->get_setting("wp_userlockout") && !isset($_POST["action"])) {
				
				$customer = $this->get_setting("pilotpress_customer_plr");
				if(!empty($customer) && $customer != "-1") {
					self::redirect(get_permalink($customer));
				} else {
					self::redirect($this->homepage_url);
				}
				die;
			}
		}
	
		/* please load scripts here vs. printing. it's so much healthier */
		function load_scripts() {
			wp_enqueue_script("jquery");
			wp_register_script("mr_tracking", self::$url_tjs, array(), false, true);
			wp_enqueue_script("mr_tracking");
		}

		/*
			@brief only load these scripts if in the admin dashboard

		*/
		function admin_load_scripts() 
		{
			// Here to determine if the automattic color picker 'iris' is included with wordpress... if not, include and use it
			$version = get_bloginfo('version');
			if ($version < 3.5)
			{
			    wp_register_style('irisstyle', plugins_url( '/js/iris.css' , __FILE__ )); 
			    wp_enqueue_style('irisstyle');
			    wp_register_style('jquery-ui', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery.ui.all.css');
			    wp_enqueue_style('jquery-ui');

			    wp_deregister_script('jquery-color');
			    wp_register_script('jquery-color', plugins_url( 'color.js' , __FILE__ ));
			    wp_enqueue_script('jquery-color');
			    wp_enqueue_script('jquery-ui-core');
			    wp_enqueue_script('jquery-ui-draggable');
			    wp_enqueue_script('jquery-ui-slider');
			    wp_enqueue_script('jquery-ui-widget');
			    wp_enqueue_script('jquery-ui-mouse');
			    wp_enqueue_script('jquery-ui-tabs');
			    wp_register_script('iris', plugins_url( '/js/iris.js' , __FILE__ ), array( 'jquery', 'jquery-color', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-ui-mouse', 'jquery-ui-tabs' )); 
			    wp_enqueue_script('iris'); 
			}
			else
			{
			    wp_register_style('jquery-ui', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery.ui.all.css');
			    wp_enqueue_style('jquery-ui');
			    wp_enqueue_script('jquery-ui-tabs');
			    wp_enqueue_style( 'wp-color-picker' );
			    wp_enqueue_script('iris'); 
			}
			if(function_exists( 'wp_enqueue_media' )){
			    wp_enqueue_media();
			}else{
			    wp_enqueue_style('thickbox');
			    wp_enqueue_script('media-upload');
			    wp_enqueue_script('thickbox');
			}

		}
		
		function stylesheets() {

			wp_register_style("mrjswp", self::$url_jswpcss);
			wp_enqueue_style("mrjswp");

			wp_register_style("mrcss", self::$url_mrcss);
			wp_enqueue_style("mrcss");

			wp_register_style("jqcss", self::URL_JQCSS);
			wp_enqueue_style("jqcss");
		}
	
		/* except this one. */
		function tracking() {
			echo "<script>_mri = \"".$this->get_setting('tracking','oap')."\";_mr_domain = \"" . $this->get_setting('tracking_url', 'oap') . "\"; mrtracking();</script>";
		}
	
		/* first of a few tinymce functions, this registers some of our buttons */
		function mce_buttons($buttons) {
			array_push($buttons, "separator", "merge_fields");
			return $buttons;
		}
		
		/* load up our marshalled plugin code (see comment prefixed: Xevious) */
		function mce_external_plugins($plugin_array) {
			global $wp_version;
			$version = 3.9;
			//test for wordpress version to load proper plugin scripts
			if ( version_compare( $wp_version, $version, '>=' ) ) {
				$plugin_array['pilotpress']  =  plugins_url('/' . plugin_basename(__FILE__) . '?ping=js39');
			} 
			else 
			{
				$plugin_array['pilotpress']  =  plugins_url('/' . plugin_basename(__FILE__) . '?ping=js');
			}

			return $plugin_array;
		}
	
		/* i forget what this did, but it is important */
		function tiny_mce_version($version) {
			return ++$version;
		}
	
		/* right so... lets just make most useful elements avaliable */
		function mce_valid_elements($in) {
			$em = '#p[*],p[*],form[*],div[*],span[*],script[*],link[*]';
			
			if(!is_array($in)) 
			{
				$in = array();
			}
			
			if(isset($in["extended_valid_elements"])) 
			{
				$in["extended_valid_elements"] .= ',';
				$in["extended_valid_elements"] .= $em;
			} else {
				$in["extended_valid_elements"] = $em;
			}

			if (isset($in['valid_children'])) 
			{
		        $in['valid_children'] .= ',+body[link]';
		    }
		    else 
		    {
		        $in['valid_children'] = '+body[link]';
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
					

				if(($_POST["oguser"] != $_POST["username"]) && username_exists($_POST["username"])) {
					echo "display_notice('Error: That username is taken. Please try another username.');";
					die();
				}			
					
				$current_user = wp_get_current_user();
				$return = $this->api_call("update_cc_details", $_POST);
				if(isset($return["updateUser"])) {
					$wpdb->query("UPDATE {$wpdb->users} SET user_login = '" . $wpdb->escape($_POST["username"]) . "' WHERE ID = '".$current_user->ID."'");
					if($_POST["nickname"] == $_POST["oguser"]) {
						wp_update_user(array("ID" => $current_user->ID, "nickname" => $_POST["username"], "display_name" => $_POST["username"]));
					}
					$this->end_session();
				}
				else {
					wp_update_user(array("ID" => $current_user->ID, "user_pass" => $_POST["password"]));
				}

				echo($return["update"]);
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
				$api_result = $this->api_call("get_video", array(
                    "video_id" => $_POST["video_id"],
                    "width" => '480',
                    "height" => "320",
                    "player" => $_POST["use_player"],
                    "autoplay" => $_POST["use_autoplay"],
                    "viral" => $_POST["use_viral"],
                    "omit_flowplayerjs" => ($_POST["omit_flowplayerjs"] == "true" ? true : false)
                ));
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
                    var omit_flowplayerjs = false;

                    if($("textarea.wp-editor-area", top.document).val().indexOf("oap_flow/flowplayer") !== -1) {
                        omit_flowplayerjs = true;
                    }

					$.post("<?php echo $this->homepage_url; ?>/wp-admin/admin-ajax.php", { action: "pp_insert_video", video_id: the_video_id, use_viral: viral, use_player: player, use_autoplay: autoplay, 'cookie': encodeURIComponent(document.cookie), "omit_flowplayerjs": omit_flowplayerjs },
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
			<?php
		}

		function modify_tinymce() {}
	
		/* south side rockers */
		function media_button_add() {

		        global $post_ID, $temp_ID;

				if($this->is_setup()) {
					$uploading_iframe_ID = (int) (0 == $post_ID ? $temp_ID : $post_ID);
			        $media_upload_iframe_src = "media-upload.php?post_id=$uploading_iframe_ID";
			        $media_oap_iframe_src = apply_filters('media_oap_iframe_src', "$media_upload_iframe_src&amp;tab=forms");
			        $media_oap_title = __('Add ' . self::$brand . ' Media', 'wp-media-oapform');
			        echo "<a href=\"{$media_oap_iframe_src}&amp;TB_iframe=true&amp;height=500&amp;width=640\" class=\"thickbox\" title=\"$media_oap_title\"><img src=\"".$this->get_setting("mr_url", "oap")."static/media-button-pp.gif\" alt=\"$media_oap_title\" /></a>";
				}
		}
	
		/* this function adds the metaboxes defined in construct() to the WP admin */
		function metabox_add() {
			if($this->is_setup()) {
				$this->load_metaboxes();
				foreach($this->metaboxes as $id => $details) {
					$types = array();
					foreach($this->get_setting("post_types","wp") as $type) {
						add_meta_box($details['id'], $details['title'], array($this, "metabox_display"), $type, $details['context'], $details['priority']);
						array_push($types, $type);
					}
					if ( !in_array( 'ontrapage', $types ) )
					{
						add_meta_box($details['id'], $details['title'], array($this, "metabox_display"), 'ontrapage', $details['context'], $details['priority']);
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

				if(is_array($meta) && count($meta) < 2 && array_key_exists(0, $meta)) {
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

				//Wordpress trims trailing and leading spaces before authenticating, lets do the same.
				$password = trim($password);

                $hashed_password = $username . self::VERSION . $password . self::AUTH_SALT;
 
                $supported_algos = hash_algos();
                if (in_array("sha256", $supported_algos))
                {
                    $algo = "sha256";
                    $hash = hash("sha256", $hashed_password);
                }
                else
                {
                    $algo = "md5";
                    $hash = md5($hashed_password);
                }

                $api_result = $this->api_call("authenticate_user", array("site" => site_url(), "username" => $username, "password" => $hash, "version" => self::VERSION, "algo" => $algo));

				/* user does exist */
				if(is_array($api_result)) {

					if(!username_exists($username) && $api_result["status"] != 0) {
					
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
						
						if(isset($api_result["nickname"])) {
							wp_update_user(array("ID" => $create_user, "nickname" => $api_result["nickname"], "display_name" => $api_result["nickname"]));
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
							} else if($user->user_level != 10) {
								/* ghetto "sync" of password... saves alot of scary stuff as they are already authenticated.. */
								wp_set_password($password , $user->ID);
							}
							else {
								return false;
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

                        setcookie("contact_id", $api_result["contact_id"], (time() + 2419200), COOKIEPATH, $cookie_domain, false);
												
						$user_id = $user->ID;
						$remember = false;
						if (!empty($_POST["rememberme"]))
						{
							$remember = true;
						}
						wp_set_current_user($user_id, $username);
						wp_set_auth_cookie($user_id ,$remember);
						do_action('wp_login', $username , $user);

						if(!isset($_SESSION["user_name"])) {

							$this->start_session();


							foreach($api_result as $key => $value) {
								$_SESSION[$key] = $value;
							}
							$_SESSION["user_name"] = $api_result["username"];
							$_SESSION["nickname"] = $api_result["nickname"];
							$_SESSION["user_levels"] = $api_result["membership_level"];
							$_SESSION["rehash"] = true;
						}
						
						/* where to go from here */
						if(isset($_SESSION["redirect_to"]) && !empty($_SESSION["redirect_to"])) {
							$redirect_to = $_SESSION["redirect_to"];
							
							if(is_numeric($redirect_to)) {
								$redirect_to = get_permalink($redirect_to);
							}
							
							unset($_SESSION["redirect_to"]);
							self::redirect($redirect_to);
							die;
						}
	
						/* this person is an affiliate, put them somewhere nice */
						if(isset($api_result["program_id"])) {							
							$aff_plr = $this->get_setting("pilotpress_affiliate_plr");
							if($aff_plr && $aff_plr != "-1") {
								self::redirect(get_permalink($aff_plr));
								die;
								exit;
							} else {								
								self::redirect(site_url());
								die;
							}
						} else {
														
							$cust_plr = $this->get_setting("pilotpress_customer_plr");							
							if($cust_plr && $cust_plr != "-1") {
								self::redirect(get_permalink($cust_plr));
								die;
							} else {
								self::redirect(site_url());
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
				self::redirect($referrer);
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
				self::redirect(site_url() . "/wp-login.php?checkemail=confirm");
				die;
			}
		}
		
		function user_logout() {
			$this->end_session(true);
		}

		/** @brief if possible add th enew user to ONTRAPORT when registered in WordPress */
		function add_new_register_user_to_ONTRAPORT($user_id) {

			$bAddUser = $this->get_setting("pilotpress_add_newly_registered_users");
			if (!$bAddUser)
			{
				return;
			}

			$appid = $this->get_setting("app_id");
			$key = $this->get_setting("api_key");
			$tagList = $this->get_setting("pilotpress_newly_registered_tags");
			$sequenceList = $this->get_setting("pilotpress_newly_registered_sequences");
			$user = get_userdata($user_id);
			$userData = array(
				"firstname"=>$user->user_firstname,
				"lastname"=>$user->user_lastname,
				"email"=>$user->user_email,
				"tags"=>$tagList,
				"sequences"=>$sequenceList
			);

			$api_result = $this->api_call("add_newly_registered_contact", $userData);
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
					self::redirect($_SESSION["loginURL"]);
				else
					self::redirect(site_url());
			}
					
			ob_start();
			if(session_id()) {
				delete_transient("pilotpress_cache");
			  	if(isset($_SESSION["contact_id"])) {
					delete_transient("usertags_".$_SESSION["contact_id"]);
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

		/* Load up the membership level meta boxes but after we have gotten the levels */
		function load_metaboxes() {

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

		}

		/* shortcodes for conditional ifs */
		function shortcode_show_if($atts, $content = null) {

            if(isset($atts[0]) && $atts[0] == "not_contact") {
                if(!$this->get_setting("contact_id","user")) {
                    return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>';
                }
            }
			//make cookie check befor login check to bypass it
			if ( isset($atts[0]) && $atts[0] == "is_cookied_contact")
			{
				if (isset($_SESSION["contact_id"])) 
				{
					return '<span class="pilotpress_prtoected">'.do_shortcode($content) . '</span>';
				}
			}

			if (isset($atts[0]) && $atts[0] == "not_cookied_contact") 
			{
				if (!isset($_SESSION["contact_id"])) 
				{
					return '<span class="pilotpress_prtoected">'.do_shortcode($content) . '</span>';
				}
			}            
			if(!is_user_logged_in()) {
				return;
			}

			if ($found = self::DoShortcodeMagic($atts,$content))
			{
				return $found;
			}

			//if we fail to find something lets make sure Wordpress hasnt encoded the tags and membership levels.
			foreach ($atts as $key => $att)
			{
				$atts[$key] = html_entity_decode($atts[$key]);
			}
			//process shortcodes with decoded entities
			return self::DoShortcodeMagic($atts,$content);
		}

		/* 
		 *	@brief Process additional shortcode logic here
		 * 
		 **/
		function DoShortcodeMagic($atts,$content)
		{
			$user_levels = $this->get_setting("levels","user", true);

			if(!is_array($user_levels)) {
				$user_levels = array();
			}
 						
			if(isset($atts["level"])) {
				if(in_array($atts["level"], $user_levels)) {
					return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>';
				}
			} else {
												
				if(isset($atts["has_one"])) {
					$content_levels = explode(",", $atts["has_one"]);
					foreach($user_levels as $level) {
						if(in_array(ltrim(rtrim($level)), $content_levels)) {
							return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>';
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
					return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>'; 
				}
				
				if(isset($atts["not_one"])) {
					$content_levels = explode(",", $atts["not_one"]);
					foreach($content_levels as $level) {
						if(in_array(ltrim(rtrim($level)), $user_levels)) {
							return false;
						}
					}
					return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>'; 
				}
				
				if(isset($atts["not_any"])) {
					$content_levels = explode(",", $atts["not_any"]);
					foreach($user_levels as $level) {
						if(!in_array(ltrim(rtrim($level)), $content_levels)) {
							return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>';
						}
					}
				}
				
				if(isset($atts[0]) && $atts[0] == "is_contact") {							
					if($this->get_setting("contact_id","user")) {
						return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>';
					}
				}
				
				if(isset($atts["has_tag"])) {
					$tags = $this->get_setting("tags", "user");
					if(is_array($tags)) {
						if(in_array($atts["has_tag"], $tags)) {
							return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>';
						}
					}
				}

				if(isset($atts[0]) && in_array($atts[0], $user_levels)) {
					return '<span class="pilotpress_protected">'.do_shortcode($content).'</span>';
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
        function post_process ()
        {
            global $wp, $wpdb, $post;

            if (isset($post->ID))
            {
                $id = $post->ID;
            }
            else
            {
                $id = $this->get_postid_by_url();
                if (empty($id) && get_option('show_on_front') == 'page')
                {
                    $id = get_option('page_on_front');
                }
            }

            if (!$this->is_viewable($id) && !is_home())
            {
                $redirect = get_post_meta($id, self::NSPACE."redirect_location", true);
                if (!empty($redirect))
                {
                    if ($redirect == "-1")
                    {
                        return self::redirect(site_url());
                    }
                    else if ($redirect == "-2")
                    {
                        if (!empty($id))
                        { 
                            $_SESSION["redirect_to"] = $id;
                        }
                    }
                    return self::redirect(get_permalink($redirect));
                }
                else
                {
                    return self::redirect($this->homepage_url);
                }
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
					$login_page = $this->login_page(array(), 3);
					$content = str_replace("[login_page]", $login_page, $content, $count);
					if($count == 0) {
						$content = $login_page;
					}
					unset($_SESSION["loginFailed"]);
				}
				else {
					if(is_page() && in_array($post->ID, $this->system_pages)) {
						$content = $this->do_system_page($post->ID);
					}

					if (has_shortcode($content, "pilotpress_field") || has_shortcode($content, "field"))
					{
						// Lets grab all the fields here with the API call and store them later
						// Since the shortcode hook runs after this one it is a safe spot to check and make if needed.
						$this->get_merge_field_settings($content);
					}
				}
			}	

			return $content;
		}

		
		/** 
		 *  @brief Make api call to grab merge fields that are only present in the content
		 *
		 *  @param String $content the string to check if merge fields are present
		 *
		 */
		function get_merge_field_settings($content , $makeApiCall = true)
		{
		    $pattern = get_shortcode_regex();

		    preg_match_all('/'.$pattern.'/uis', $content, $matches);

		    for ( $i=0; $i < count($matches[0]); $i++ ) 
		    {
		    	$fields = shortcode_parse_atts($matches[3][$i]);
		    	
		        if ( isset( $matches[2][$i] ) && ($matches[2][$i] == "pilotpress_field" || $matches[2][$i] == "field") ) 
		        {
		           $this->shortcodeFields[$fields["name"]] = 1;
		        }
		        elseif (!empty($matches[5][$i]))
		        {
		        	//call this recursively so we can process shortcodes inside shortcodes
		        	$this->get_merge_field_settings($matches[5][$i] , false);
		        }
		    }

		    //Since this can be called recursively lets make sure when it does call it we only make this at the initial call of the function
		    if ($makeApiCall)
		    {
			    //make API call now as well if needed!
			    if (!empty($this->shortcodeFields) && is_array($this->shortcodeFields) && !empty($_SESSION["user_name"]))
			    {
			    	$data = array(
			    		"username" => $_SESSION["user_name"],
			    		"fields" => $this->shortcodeFields,
			    		"site" => site_url()
			    	);
			    	
			    	$api_result = $this->api_call("get_contact_merge_fields" , $data);
			    	
	    			if(isset($api_result["fields"])) 
	    			{
	    				// In order for the get_field() to work later on we need to add these fields to the group list of known merged fields.
						$_SESSION["user_fields"]["--merged fields--"] = $api_result["fields"];
						$this->settings["user"]["fields"]["--merged fields--"] = $api_result["fields"];
					}
			    }
		    }
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
			
			return apply_filters("pilotpress_get_routeable_pages",$array);
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
			if (empty($id) && get_option('show_on_front') == 'page')
            {
                $id = get_option('page_on_front');
            }
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
				$where .= " AND ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_pilotpress_level' AND meta_value IN (" . $wpdb->escape($level_in) . "){$post_extra})";
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
					$id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_name = '" . $wpdb->escape($wp->query_vars["name"]) . "'");									
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
							self::redirect(get_permalink($page_id));
							die;
						}
					break;
					case "affiliate_center":
						$page_id = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = 'affiliate_center'", ARRAY_A);
						if($page_id) {
							self::redirect(get_permalink($page_id));
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
			//send over our colors to style the pages nicely
			$styles["primary_color"] = $this->get_setting("pilotpress_customer_center_primary_color");
			$styles["secondary_color"] = $this->get_setting("pilotpress_customer_center_secondary_color");
			$styles["header_image"] = $this->get_setting("pilotpress_customer_center_header_image");

			if(!is_user_logged_in()) {
				$return = $this->login_page(array(), 1);
				return $return;
			}

			if($type == "affiliate_center") {
				$api_result = $this->api_call("get_".$type, array("username" => $_SESSION["user_name"], "program_id" => $_SESSION["program_id"], "site" => site_url()  , "styles"=>$styles ));
			}  

			if($type == "customer_center"){
				$api_result = $this->api_call("get_".$type, array("username" => $_SESSION["user_name"], "site" => site_url(), "nonce" => wp_create_nonce(basename(__FILE__)) , "styles"=>$styles , "version"=>self::VERSION ));
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
				$wpdb->query("DELETE FROM $wpdb->posts WHERE post_status = 'trash' AND post_name = '" . $wpdb->escape($name) . "'");
				$wpdb->query("UPDATE $wpdb->posts SET post_name = '{$name}' WHERE ID = '" . $wpdb->escape($post_id) . "'");
				$this->flush_rewrite_rules();
			}
		}
	
		/* banished. */
		function delete_system_page($name) {
			global $wpdb;
			$pages = $wpdb->get_results("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_pilotpress_system_page' AND meta_value = '" . $wpdb->escape($name) . "'", ARRAY_A);
			if(!empty($pages)) {
				foreach($pages as $page) {
					delete_post_meta($page["post_id"], "_pilotpress_system_page");
					wp_delete_post($page["post_id"], true);
				}	
			}
		}

		/**
		 * Ping Logic
		 * 
		 * Imports jQuery logic to head which can then be utilized 
		 * to send ajax calls to the same file to update the PilotPress
		 * session.
		 *
		 *
		 * @uses add_action()
		 */
		function sessionslap_ping(){
			// Register JavaScript
			wp_enqueue_script('jquery');

			require_once( plugin_dir_path( __FILE__ ) . "/ping.php");
			
			// Append dynamic js to both admin and regular users head.
			add_action( "admin_head", "pilotpress_sessionslap_face" );
			add_action( "wp_head", "pilotpress_sessionslap_face" );
			
		}
			
		/* renders cute login page */
		function login_page ($atts, $message = false) 
		{
			// Allows shortcodes to be put in text widgets
			add_filter('widget_text', 'do_shortcode');

			global $wpdb;
			// This section allows the users to add custom styling by adding custom attributes to the shortcode [login_page]
			// Form general styling options
			if ( isset($atts['width']) ) 
			{ 
				$width = $atts['width'];
				$width = 'max-width: '.$width.'!important;';
			}
			else
			{
				$width = 'max-width: 320px;';
			}

			if ( isset($atts['formalign']) ) 
			{ 
				$formalign = $atts['formalign'];

				if ( $formalign == 'left' ) 
				{
					$formalign = 'margin: 30px 30px 30px 0px; float: left;';
				}
				else if ( $formalign == 'center' ) 
				{
					$formalign = 'margin: 30px auto!important;';
				}
				else if ( $formalign == 'right' ) 
				{
					$formalign = 'margin: 30px 0px 30px 30px; float: right;';
				}
				else 
				{
					$formalign = 'margin: 30px 0px;';
				}
			}
			else
			{
				$formalign = 'margin: 30px 0px; width: 100%;';
			}

			if ( isset($atts['bgcolor']) ) 
			{ 
				$bgcolor = $atts['bgcolor'];
				$bgcolor = 'background-color: '.$bgcolor.'!important;';
			}
			else
			{
				$bgcolor = 'background-color: #fff;';
			}

			if ( isset($atts['textcolor']) ) 
			{ 
				$textcolor = $atts['textcolor'];
				$textcolor = 'color: '.$textcolor.'!important;';
			}
			else
			{
				$textcolor = '';
			}

			// Header Text styling
			if ( isset($atts['headertextalignment']) ) 
			{ 
				$headertextalignment = $atts['headertextalignment'];
				$headertextalignment = 'text-align: '.$headertextalignment.'!important;';
			}
			else
			{
				$headertextalignment = '';
			}

			if ( isset($atts['headertextfont']) ) 
			{ 
				$headertextfont = $atts['headertextfont'];
				$headertextfont = 'font-family: '.$headertextfont.'!important;';
			}
			else
			{
				$headertextfont = '';
			}

			if ( isset($atts['headertextfontsize']) ) 
			{ 
				$headertextfontsize = $atts['headertextfontsize'];
				$headertextfontsize = 'font-size: '.$headertextfontsize.'!important;';
			}
			else
			{
				$headertextfontsize = 'font-size: 20pt;';
			}

			if ( isset($atts['headertextfontcolor']) ) 
			{ 
				$headertextfontcolor = $atts['headertextfontcolor'];
				$headertextfontcolor = 'color: '.$headertextfontcolor.'!important;';
			}
			else
			{
				$headertextfontcolor = 'color: #222;';
			}

			// Supporting Text styling
			if ( isset($atts['supportingtextfont']) ) 
			{ 
				$supportingtextfont = $atts['supportingtextfont'];
				$supportingtextfont = 'font-family: '.$supportingtextfont.'!important;';
			}
			else
			{
				$supportingtextfont = '';
			}

			if ( isset($atts['supportingtextfontsize']) ) 
			{ 
				$supportingtextfontsize = $atts['supportingtextfontsize'];
				$supportingtextfontsize = 'font-size: '.$supportingtextfontsize.'!important;';
			}
			else
			{
				$supportingtextfontsize = 'font-size: 12pt;';
			}

			if ( isset($atts['supportingtextfontcolor']) ) 
			{ 
				$supportingtextfontcolor = $atts['supportingtextfontcolor'];
				$supportingtextfontcolor = 'color: '.$supportingtextfontcolor.'!important;';
			}
			else
			{
				$supportingtextfontcolor = 'color: #555;';
			}

			// Form Input styling
			if ( isset($atts['inputcolor']) ) 
			{ 
				$inputcolor = $atts['inputcolor'];
				$inputcolor = 'background-color: '.$inputcolor.'!important;';
			}
			else
			{
				$inputcolor = '';
			}

			if ( isset($atts['inputtextcolor']) ) 
			{ 
				$inputtextcolor = $atts['inputtextcolor'];
				$inputtextcolor = 'color: '.$inputtextcolor.'!important;';
			}
			else
			{
				$inputtextcolor = '';
			}

			if ( isset($atts['inputbordercolor']) ) 
			{ 
				$inputbordercolor = $atts['inputbordercolor'];
				$inputbordercolor = 'border: 1px solid '.$inputbordercolor.'!important;';
			}
			else
			{
				$inputbordercolor = '';
			}

			if ( isset($atts['inputfieldsize']) ) 
			{ 
				$inputfieldsize = $atts['inputfieldsize'];
				if ( $inputfieldsize == 'large' ) 
				{
					$inputfieldsize = 'padding: 16px!important; font-size: 15pt;';
				}
				if ( $inputfieldsize == 'medium' ) 
				{
					$inputfieldsize = 'padding: 9px!important; font-size: 12pt;';
				}
				if ( $inputfieldsize == 'small' ) 
				{
					$inputfieldsize = 'padding: 6px!important; font-size: 10pt;';
				}
			}
			else
			{
				$inputfieldsize = 'padding: 6px!important; font-size: 10pt;';
			}

			// Form Button styling
			if ( isset($atts['buttonbgcolor']) ) 
			{ 
				$buttonbgcolor = $atts['buttonbgcolor'];
				$buttonbgcolor = 'background-color: '.$buttonbgcolor.'!important; background-image: none!important;';
			}
			else
			{
				$buttonbgcolor = '';
			}

			if ( isset($atts['buttontextcolor']) ) 
			{ 
				$buttontextcolor = $atts['buttontextcolor'];
				$buttontextcolor = 'color: '.$buttontextcolor.'!important;';
			}
			else
			{
				$buttontextcolor = '';
			}

			if ( isset($atts['buttonbordercolor']) ) 
			{ 
				$buttonbordercolor = $atts['buttonbordercolor'];
				$buttonbordercolor = 'border: 1px solid '.$buttonbordercolor.'!important;';
			}
			else
			{
				$buttonbordercolor = '';
			}

			if ( isset($atts['buttonfont']) ) 
			{ 
				$buttonfont = $atts['buttonfont'];
				$buttonfont = 'font-family: '.$buttonfont.'!important;';
			}
			else
			{
				$buttonfont = '';
			}

			if ( isset($atts['buttonfontsize']) ) 
			{ 
				$buttonfontsize = $atts['buttonfontsize'];
				$buttonfontsize = 'font-size: '.$buttonfontsize.'!important;';
			}
			else
			{
				$buttonfontsize = 'font-size: 11pt;';
			}

			if ( isset($atts['buttonhovertextcolor']) ) 
			{ 
				$buttonhovertextcolor = $atts['buttonhovertextcolor'];
				$buttonhovertextcolor = 'color: '.$buttonhovertextcolor.'!important;';
			}
			else
			{
				$buttonhovertextcolor = '';
			}

			if ( isset($atts['buttonhoverbgcolor']) ) 
			{ 
				$buttonhoverbgcolor = $atts['buttonhoverbgcolor'];
				$buttonhoverbgcolor = 'background-color: '.$buttonhoverbgcolor.'!important;';
			}
			else
			{
				$buttonhoverbgcolor = '';
			}

			if ( isset($atts['buttonhoverbordercolor']) ) 
			{ 
				$buttonhoverbordercolor = $atts['buttonhoverbordercolor'];
				$buttonhoverbordercolor = 'border: 1px solid '.$buttonhoverbordercolor.'!important;';
			}
			else
			{
				$buttonhoverbordercolor = '';
			}

			if ( isset($atts['buttonsize']) ) 
			{ 
				$buttonsize = $atts['buttonsize'];
				switch ($buttonsize) 
				{
					case 'extralarge':
						$buttonsize = 'padding: 25px!important; font-size: 23pt;';
					break;

					case 'large':
						$buttonsize = 'padding: 18px!important; font-size: 18pt;';
					break;

					case 'medium':
						$buttonsize = 'padding: 10px!important; font-size: 13pt;';
					break;

					case 'small':
						$buttonsize = 'padding: 6px!important; font-size: 10pt;';
					break;
				}
			}
			else
			{
				$buttonsize = 'padding: 10px!important; font-size: 13pt;';
			}

			// Form Style - Responsible for the full width or side by side form style
			$default = '#pp-loginform .login-username LABEL
				{
					max-width: 100%!important;
					width: 100%!important;
				}
				#pp-loginform .login-username INPUT
				{
					max-width: 100%!important;
					width: 100%!important;
				}
				#pp-loginform .login-password LABEL
				{
					max-width: 100%!important;
					width: 100%!important;
				}
				#pp-loginform .login-password INPUT
				{
					max-width: 100%!important;
					width: 100%!important;
				}';

			if ( isset($atts['style']) ) 
			{ 
				$style = $atts['style'];

				if ( $style == 'default' ) 
				{
					$style = $default;
				}
				else if ( $style == 'fullwidth' ) 
				{
					$style = $default . '.op-login-form { max-width: 100%!important; }';
				}
			}
			else
			{
				$style = $default;
			}

			
			// TEXT - Options to change the form text
			if ( isset($atts['headertext']) ) 
			{ 
				$headertext = $atts['headertext'];
			}
			else
			{
				$headertext = '';
			}

			if ( isset($atts['supportingtext']) ) 
			{ 
				$supportingtext = $atts['supportingtext'];
			}
			else
			{
				$supportingtext = '';
			}

			if ( isset($atts['usernametext']) ) 
			{ 
				$usernametext = $atts['usernametext'];
				$usernametext = __($usernametext);
			}
			else
			{
				$usernametext = __('Username');
			}

			if ( isset($atts['passwordtext']) ) 
			{ 
				$passwordtext = $atts['passwordtext'];
				$passwordtext = __($passwordtext);
			}
			else
			{
				$passwordtext = __('Password');
			}

			if ( isset($atts['remembertext']) ) 
			{ 
				$remembertext = $atts['remembertext'];
				$remembertext = __($remembertext);
			}
			else
			{
				$remembertext = __('Remember me');
			}

			if ( isset($atts['buttontext']) ) 
			{ 
				$buttontext = $atts['buttontext'];
				$buttontext = __($buttontext);
			}
			else
			{
				$buttontext = __('Log In');
			}

			
			// New style for the [login_page] forms with variables for user customization
			$output = "<style type='text/css'>
				.op-login-form-".$this->incrementalnumber."
				{
					".$formalign."
					padding: 30px;
					box-sizing: border-box;
					-webkit-box-sizing: border-box;
					-moz-box-sizing: border-box;
					-moz-box-shadow: 0px 0px 2px 1px rgba(51,51,51,0.27);
					-webkit-box-shadow: 0px 0px 2px 1px rgba(51,51,51,0.27);
					box-shadow: 0px 0px 2px 1px rgba(51, 51, 51, 0.27);
					-ms-filter: 'progid:DXImageTransform.Microsoft.Glow(Color=#ff333333,Strength=3)';
					filter: progid:DXImageTransform.Microsoft.Glow(Color=#ff333333,Strength=3);
					".$bgcolor."
					".$width."
				}
				.op-login-form-".$this->incrementalnumber." .op-header-text-container
				{
					margin-bottom: 25px;
					width: 100%;
					".$headertextalignment."
				}
				.op-login-form-".$this->incrementalnumber." .op-header-text
				{
					line-height: 1.2!important;
					margin-bottom: 4px;".$headertextfont.$headertextfontsize.$headertextfontcolor."
				}
				.op-login-form-".$this->incrementalnumber." .op-supporting-text
				{
					line-height: 1.2!important;".$supportingtextfont.$supportingtextfontsize.$supportingtextfontcolor."
				}
				.op-login-form-".$this->incrementalnumber." #pp-loginform P
				{
					width: 100%;
					display: table;
					margin: 0px 0px 4px;
					padding: 0px;
				}
				.op-login-form-".$this->incrementalnumber." LABEL,
				.op-login-form-".$this->incrementalnumber." INPUT
				{
					display: table-cell;
					box-sizing: border-box;
					-webkit-box-sizing: border-box;
					-moz-box-sizing: border-box;
					line-height: 1.3;
				}
				.op-login-form-".$this->incrementalnumber." .login-username
				{
					position: relative;
				}
				.op-login-form-".$this->incrementalnumber." .login-username LABEL
				{
					width: 100%;
					max-width: 25%;
					min-width: 90px;
					padding-right: 3%;
					float: left;".$textcolor."
				}
				.op-login-form-".$this->incrementalnumber." .login-username INPUT
				{
					width: 100%;
					max-width: 72%;
					float: right;
					border-radius: 3px;".$inputcolor.$inputtextcolor.$inputbordercolor.$inputfieldsize."
				}
				.op-login-form-".$this->incrementalnumber." .login-password LABEL
				{
					width: 100%;
					max-width: 25%;
					min-width: 90px;
					padding-right: 3%;
					float: left;".$textcolor."
				}
				.op-login-form-".$this->incrementalnumber." .login-password INPUT
				{
					width: 100%;
					max-width: 72%;
					float: right;
					border-radius: 3px;".$inputcolor.$inputtextcolor.$inputbordercolor.$inputfieldsize."
				}
				.op-login-form-".$this->incrementalnumber." .login-remember
				{
					text-align: right;
					font-style: italic;
					cursor: pointer;".$textcolor."
				}
				.op-login-form-".$this->incrementalnumber." .login-remember INPUT
				{
					float: right;
					margin-left: 10px;
					margin-top: 5px;
					cursor: pointer;
				}
				.op-login-form-".$this->incrementalnumber." .login-remember LABEL
				{
					cursor: pointer;".$textcolor."
				}
				.op-login-form-".$this->incrementalnumber." #wp-submit
				{
					width: 100%;
					padding: 10px;
					margin-top: 15px;
					margin-bottom: 0px;
					white-space: pre-wrap;
					border-radius: 3px;".$buttonbgcolor.$buttontextcolor.$buttonbordercolor.$buttonfont.$buttonfontsize.$buttonsize."
				}
				.op-login-form-".$this->incrementalnumber." #wp-submit:hover
				{
					transition: background-color 1s ease, color 1s ease;
					-moz-transition: background-color 1s ease, color 1s ease;
					-webkit-transition: background-color 1s ease, color 1s ease;".$buttonhovertextcolor.$buttonhoverbgcolor.$buttonhoverbordercolor."
				}
				.op-login-form-".$this->incrementalnumber." .login_box
				{
					margin-top: 6px;
					padding: 5px;
					border: 1px solid #E6D855;
					background-color: #FFFFE0;
					box-sizing: border-box;
					-webkit-box-sizing: border-box;
					-moz-box-sizing: border-box;
				}
				@media screen and (max-width: 480px) 
				{
					.op-login-form-".$this->incrementalnumber." .login-username LABEL
					{
						max-width: 100%!important;
					}
					.op-login-form-".$this->incrementalnumber." .login-username INPUT
					{
						max-width: 100%!important;
					}
					.op-login-form-".$this->incrementalnumber." .login-password LABEL
					{
						max-width: 100%!important;
					}
					.op-login-form-".$this->incrementalnumber." .login-password INPUT
					{
						max-width: 100%!important;
					}
				}
				".$style."
				</style>";

			// Start Form output
			$output .= '<div class="op-login-form-'.$this->incrementalnumber.'">';

			// Setting header text
			if ( isset($atts['headertext']) || isset($atts['supporting']) ) 
			{ 
				$output .= '<div class="op-header-text-container"><div class="op-header-text">'.$headertext.'</div><div class="op-supporting-text">'.$supportingtext.'</div></div>';
			}

			if(!empty($message)) 
			{
				switch($message) 
				{
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
			
			if ( isset($atts['redirect']) ) 
			{ 
				$redirect = $atts['redirect'];
			}
			else
			{
				if(isset($_SESSION["redirect_to"]) && !empty($_SESSION["redirect_to"])) 
				{
					$redirect = get_permalink($_SESSION["redirect_to"]);
				} 
				else 
				{
					$redirect = site_url($_SERVER['REQUEST_URI']);
				}
			}

			$args = array(
			        'echo' => false,
			        'redirect' => $redirect, 
			        'form_id' => 'pp-loginform',
			        'label_username' => $usernametext,
			        'label_password' => $passwordtext,
			        'label_remember' => $remembertext,
			        'label_log_in' => $buttontext,
			        'id_username' => 'user_login',
			        'id_password' => 'user_pass',
			        'id_remember' => 'rememberme',
			        'id_submit' => 'wp-submit',
			        'remember' => true,
			        'value_username' => NULL,
			        'value_remember' => true);			
			$output .= wp_login_form($args);

			// Adds functionality for Lost Passwords
			if ( isset($atts['forgotpw']) && $atts['forgotpw'] == 'true' )
			{
				$output .= '<div class="pp-lf-forgot-username" style="text-align: right;"><a id="pp-lf-forgotpw" href="javascript://">Forgotten password?</a></div>';

				$output .= '<script>
						jQuery(".op-login-form-'.$this->incrementalnumber.' #pp-lf-forgotpw").click(function()
							{ 
								jQuery(".op-login-form-'.$this->incrementalnumber.' .login-password, .op-login-form-'.$this->incrementalnumber.' .login-remember").hide(300);
								jQuery(".op-login-form-'.$this->incrementalnumber.' #pp-loginform").attr( "action", "'.site_url().'/wp-login.php?action=lostpassword");
								jQuery(".op-login-form-'.$this->incrementalnumber.' .login-username label").text("Enter your Username or Email");
								jQuery(".op-login-form-'.$this->incrementalnumber.' .login-username input").attr("name", "user_login");
								jQuery(".op-login-form-'.$this->incrementalnumber.' #wp-submit").attr("value", "Send me my Password!");
							});
						</script>';
			}

			$output .= '</div>';

			$this->incrementalnumber++;
				
			return $output;

		}

		public function include_form_admin_options () 
		{
			include_once(plugin_dir_path(__FILE__) . "/login-button.php");
		}

		public function register_login_button ( $buttons ) 
		{
			array_push( $buttons, "|", "addloginform" );
   			return $buttons;
		}

		public function add_login_button ( $plugin_array ) 
		{
		   $plugin_array['addloginform'] = plugins_url( '/js/login-button.js' , __FILE__ );
		   return $plugin_array;
		}

		public function pp_login_button () 
		{
		    if ( ! current_user_can('edit_posts') && ! current_user_can('edit_pages') ) 
		    {
		    	return;
		    }
		    if ( get_user_option('rich_editing') == 'true' ) 
		    {
		      	add_filter( 'mce_external_plugins', array(&$this, 'add_login_button') );
		      	add_filter( 'mce_buttons_3', array(&$this, 'register_login_button') );
		    }
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
						wp_update_post(array("ID" => $post_id, "post_content" => "This content will be replaced by the Partner Center."));
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

		public static function redirect($url) {
			// Workaround for trac bug #21602
			$current_url = $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];

			if(substr($current_url, -1) == "/") {
				$current_url = substr($current_url, 0, -1);
			}
			$compare_url = str_replace("https://", "", $url);
			$compare_url = str_replace("http://", "", $compare_url);
			
			if($current_url != $compare_url) {
				return wp_redirect($url);
			}
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


	//Since we ping this file independent of the WordPress bootstrap lets make sure the class exists...
	if (class_exists("WP_Widget"))
	{
		// Creating the widget 
		class Pilotpress_Widget extends WP_Widget {

			//Registers the widget with the WordPress Widget API.
		    public static function register() {
		        register_widget( __CLASS__ );
		    }

			public function __construct() {

				parent::__construct(
					// Base ID of your widget
					'pilotpress_widget', 

					// Widget name will appear in UI
					__('PilotPress Text', 'pilotpress_widget_domain'), 

					// Widget description
					array( 
						'description' => __( 'An enhanced text area widget that helps you display your ONTRAPORT merge fields', 'pilotpress_widget_domain' )
					)
				);
			}

			// Creating widget front-end
			public function widget( $args, $instance ) {
				global $pilotpress;
				$title = apply_filters( 'widget_title', $instance['title'] );
				
				$textarea =  $instance["textarea"];
				// before and after widget arguments are defined by themes
				echo $args['before_widget'];
				if ( ! empty( $title ) )
				{
					echo $args['before_title'] . $title . $args['after_title'];
				}
				//Lets check and process merge fields if we need too!
				if (has_shortcode( $textarea , "pilotpress_field") || has_shortcode( $textarea ,"field")  )
				{
					$pilotpress->get_merge_field_settings($textarea);
				}
				//Apply the default filter in case they have somehting to make PHP work...
				echo apply_filters( 'widget_text' , do_shortcode($textarea) );
				
				echo $args['after_widget'];
			}
					
			// Widget Backend 
			public function form( $instance ) {
				global $pilotpress;

				//Handle merge codes
				$mergeFieldDropDown = "<p>";
				$mergeFieldDropDown .= "<label for='" . $this->get_field_id( "merge-codes" ) ."'>" . __("Merge Fields:", "pilotpress_widget_domain") . "</label>";
				$mergeFieldDropDown .= "<select id='" . $this->get_field_id( "merge-codes" ) . "' class='op-merge-codes__select' name='" . $this->get_field_id( "merge-codes" ) . "'>";
				$mergeFieldDropDown .= "</p>";

				foreach($pilotpress->get_setting("default_fields", "oap", true) as $group => $fields) {
					
					$mergeFieldDropDown .= "<option value=''>  " . $group . "</option>";
					foreach ($fields as $key => $field)
					{
						
						$mergeFieldDropDown .= "<option value='[pilotpress_field name=\"{$key}\"]'>&nbsp;&nbsp;&nbsp;" . $key . "</option>";
					}
				}

				$mergeFieldDropDown .= "</select>";

				if ( isset( $instance[ 'title' ] ) ) {
					$title = $instance[ 'title' ];
				}
				else {
					$title = __( '', 'pilotpress_widget_domain' );
				}
				
				if (isset( $instance[ 'textarea' ] )) {
					$textarea = $instance[ 'textarea' ];
				}
				else {
					$textarea = __( '', 'pilotpress_widget_domain' );
				}

				$titleText = "<p>";
				$titleText .= "<label for='" . $this->get_field_id( 'title' ) ."'>". __( 'Title:' ) ."</label>";
				$titleText .= "<input class='widefat' id='". $this->get_field_id( 'title' ) ."' name='". $this->get_field_name( 'title' )."' type='text' value='". esc_attr( $title )."' />";
				$titleText .= "</p>";

				$textAreaText = "<p>";
				$textAreaText .= "<textarea class='widefat' id='". $this->get_field_id( 'textarea' )."' name='" . $this->get_field_name( 'textarea' ) ."' rows='16' cols='20'>". esc_attr( $textarea ) ."</textarea>";
				$textAreaText .= "</p>";

				//echo out the actual widget content block
				echo $mergeFieldDropDown;
				echo $titleText;
				echo $textAreaText;
				
			}
				
			// Updating widget replacing old instances with new
			public function update( $new_instance, $old_instance ) {
				$instance = array();
				$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
				$instance['textarea'] = ( ! empty( $new_instance['textarea'] ) ) ?  $new_instance['textarea']  : '';
				return $instance;
			}
		} // Class pilotpress_widget ends here

	}

	function pilotpress_widget_js() {
		$widgetJavascript = "
			<script type='text/javascript'>
				jQuery( document ).ready( function(){
				     	jQuery( 'body' ).on( 'change', 'select.op-merge-codes__select', function( ev ) {
							var textarea = jQuery(this).closest( 'form' ).find( 'textarea' );
							textarea.val(textarea.val() + jQuery(this).val());
				     	} );
				 } );
			</script>
		";
		echo $widgetJavascript;
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
			case "js39":

				PilotPress::start_session();
				header("Content-Type: text/javascript");

				?>

				  tinymce.PluginManager.add('pilotpress', function(editor, url) 
				  { 

				    editor.addButton('merge_fields', {
				        type: 'listbox',
					
				        text: 'Merge Fields',
				        icon: false,
				        classes: 'fixed-width btn widget',
				        onselect: function(e) {
				            if (this.value() != "" ) 
					    {
				                editor.insertContent("[pilotpress_field name='"+this.value()+"']");
				            } 
				        },
				        values: [
						<?php
							$fields = $_SESSION["default_fields"];
							if(!empty($fields) && is_array($fields)) 
							{
								foreach($fields as $group => $items) 
								{
									echo json_encode(array( "text" => $group , "value" => "") ) . " , ";
									foreach($items as $key => $value) 
									{
										echo json_encode( array( "text" => " + " . $key , "value" => $key) ) . " , ";
									}
									
								}
							}
						?>
				        ],
				        onPostRender: function() {
				            // Select the second item by default
				        }
				    });
				});
				
				<?php


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
																tinyMCE.activeEditor.execCommand('mceInsertContent', false, '[pilotpress_field name=\''+v+'\']');
															}	
														 }
									                });


									<?php
										$fields = $_SESSION["default_fields"];
										if(!empty($fields) && is_array($fields)) 
										{
											foreach($fields as $group => $items) 
											{
												echo "                                    ";
												echo "mlb.add('".addslashes($group)."', '');\n";
												foreach($items as $key => $value) 
												{
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
				                author: 'Ontraport Inc.',
				                authorurl: 'http://<?php echo PilotPress::$brand_url ?>',
				                infourl: 'http://<?php echo PilotPress::$brand_url ?>/',
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
