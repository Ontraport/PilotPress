<?php 
class PPProtect
{

	//This is to have PilotPress set the memberhsip levels so we can use them here
	private $membershipLevels;

	/*
	 * Admin functions & Plugin setup
	 */
    function __construct() 
	{
		if(defined("ABSPATH")) 
		{
			require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
		}
	}

	// Add new hooks into WP
	public function ppprotectHooks() 
	{
		// Creates the PPProtect table
		register_activation_hook( __FILE__, array(&$this, 'ppprotectCreateTable') );

		// Adds admin styles
		add_action( 'admin_enqueue_scripts', array(&$this, 'ppprotectAdminStyles') );

		// Add new options into edit-tags.php?taxonomy=category
		add_action( 'category_edit_form_fields', array(&$this, 'ppprotectEditFormFields') );
		add_action( 'category_add_form_fields', array(&$this, 'ppprotectEditFormFields') );

		// Saves new ppp category options
		add_action ( 'created_category', array(&$this, 'ppprotectSaveFields') );
		add_action ( 'edited_category', array(&$this, 'ppprotectSaveFields') );

		// Protect home page and archive page posts
		add_action( 'pre_get_posts', array(&$this, 'ppprotectHomeAndArchivePosts') );

		// Protect categories by hooking into any loops
		add_action ( 'template_redirect', array(&$this, 'ppprotectCategory') );

		// Protect posts by hooking into any loops
		add_action ( 'the_post', array(&$this, 'ppprotectPost') );

		// Add admin area warning that post permission levels are being overridden by a category
		add_action ( 'edit_form_after_editor', array(&$this, 'ppprotectPostWarning') );

		// Add AJAX function to allow users to override each post manually and ignore the category override
		add_action( 'wp_ajax_pp_category_override', array(&$this, 'wp_ajax_ppprotectAllowOverride') );
		
		// Add footer JS on category admin pages to alert the user when they perform certain actions
		add_action( 'admin_footer', array(&$this, 'ppprotectCategoryJS') );

		add_action( 'delete_category', array(&$this, 'ppprotectDeleteCategory') );
	}

	// Create a custom table for PPProtect
	public function ppprotectCreateTable() 
	{
		global $wpdb;
		global $ppprotectDbVersion;
		$ppprotectDbVersion = '1.0';

		$table_name = $wpdb->prefix . 'ppprotect';
		
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			type VARCHAR(50) NOT NULL,
			itemId int UNIQUE NOT NULL,
			name VARCHAR(100) NOT NULL,
			levels TEXT NOT NULL,
			redirect VARCHAR(255) NOT NULL,
			protectposts int(2) NOT NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";

		dbDelta( $sql );

		add_option( 'ppprotectDbVersion', $ppprotectDbVersion );
	}

	// Saves ppprotect data in the ppprotect table in the site's database
	private function ppprotectInsertInDb( $type, $id, $name, $levels, $redirect, $protectposts )
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'ppprotect';
		$wpdb->replace( $table_name, array( 
			'type' => $type, 
			'itemId' => $id, 
			'name' => $name, 
			'levels' => $levels,
			'redirect' => $redirect,
			'protectposts' => $protectposts
			) 
		);
	}

	// Gets ppprotect data from the ppprotect table in the site's database
	private function ppprotectGetFromDb( $itemId )
	{
		global $wpdb;
		$id = intval($itemId);
		$table = $wpdb->prefix . 'ppprotect';

		if( $wpdb->get_var("SHOW TABLES LIKE '$table'") === null ) 
		{
		    $this->ppprotectCreateTable();
		}

		$row = $wpdb->get_row('SELECT * FROM ' . $table . ' WHERE itemId = ' . $id);

		return $row;
	}

	// Deletes $items from the given $table in the site's database. Returns boolean depending upon the success / failure
	private function ppprotectDeleteFromDb( $table, $items )
	{
		global $wpdb;
		if( $wpdb->get_var("SHOW TABLES LIKE '$table'") === null ) 
		{
		    error_log( 'Cant find table to delete - ' . $table );
		    return;
		}

		$row = $wpdb->delete( $table, $items );
		if ( $row === 0 )
		{
			error_log( 'Unable to delete items from the table - Table: ' . $table . ' | Items: ' . json_encode($items) );
		    return;
		}

		return $row;
	}

	// Register & enqueues admin styles
	public function ppprotectAdminStyles() 
	{
        wp_register_style( 'ppprotect_admin_css', plugins_url( 'pp-categories-admin-styles.css', __FILE__ ), false );
        wp_enqueue_style( 'ppprotect_admin_css' );
	}

	// Callback buffer
	public function ppprotectCallback($buffer)
	{
		return $buffer;
	}


	/*
	 * PP Protect Categories
	 */
 	/**
	 * @author William DeAngelis
	 * @param object $tag An object that contains information about a given category
	 * @var integer $tagId The category ID
	 * @var array $cLevels An array that contains all of the category permission settings for a given category
	 * @var array $memLevels An array that contains all of the possible PilotPress permission levels for this site
	 * @var array $pages An array that contains all of the possible pages a user could redirect users to within this site
	 *
	 * @return Generates the HTML content to be displayed in the add and edit categories sections
	 **/
	public function ppprotectEditFormFields ( $tag ) 
	{
		if ( isset($tag->term_id) )
		{
			$tagId = $tag->term_id;
			$cLevels = $this->ppprotectGetFromDb( $tagId );

			if ( isset($cLevels) )
			{
				$checkedLevels = json_decode($cLevels->levels);
				$redirectTo = $cLevels->redirect;
				$postProtect = $cLevels->protectposts;
			}
		}

		if ( !is_array( $this->membershipLevels) || empty( $this->membershipLevels ) )
		{
			return;
		}

		$memLevels = $this->ppprotectGetPPMemLevels();
		
		$ppprotectCat = '<div class="form-field ppprotect-wrap"><label class="ppp-title" for="ppprotect-category">PilotPress Permissions</label><div class="ppprotect-levels-redirect"><div class="ppprotect-levels-message">1. Select the access levels of users that can access this category of posts.</div><div class="ppprotect-category-levels">';

		foreach ( $memLevels as $level )
		{
			if ( isset( $checkedLevels ) && in_array( $level, $checkedLevels ) ) 
			{ 
				$checked = 'checked';
			} 
			else 
			{ 
				$checked = '';
			}

			$ppprotectCat .= '<div class="ppprotect-cat-level-wrap"><label><input type="checkbox" name="ppprotectCat[' . $level . ']" ' . $checked . ' /> ' . $level . '</label></div>';
		}

		$ppprotectCat .= '<p><em>(Leave blank to allow access to all users.)</em></p></div>';

	    // Start redirect code
	    $ppprotectCat .= '<div class="ppprotect-on-error"><div class="ppprotect-levels-message" style="margin-top: 10px;">2. If users don\'t have the above selected access levels, redirect to this page on error.</div><select name="ppprotectRedirect"><option value="">' . esc_attr( __( "Select page" ) ) . '</option>';

		$pages = get_pages();

		$selected1 = '';
		$selected2 = '';
		if ($redirectTo == '-1')
		{
			$selected1 = 'selected="selected"';
		}

		if ($redirectTo == '-2')
		{
			$selected2 = 'selected="selected"';
		}

		$ppprotectCat .= '<option value="-1" ' . $selected1 . '>(homepage)</option>';
		$ppprotectCat .= '<option value="-2" ' . $selected2 . '>(login page)</option>';

		foreach ( $pages as $page ) 
		{
			if ( isset( $redirectTo ) && $redirectTo == get_page_link( $page->ID ) )
			{
				$selected = 'selected="selected"';
			}
			else
			{
				$selected = '';
			}

			$ppprotectCat .= '<option value="' . get_page_link( $page->ID ) . '" ' . $selected . '>' . $page->post_title . '</option>';

		}

		$ppprotectCat .= '</select></div>'; // End Redirect code

		if ( isset( $postProtect ) && $postProtect == true )
		{
			$pChecked = 'checked';
		}
		else
		{
			$pChecked = '';
		}

		$ppprotectCat .= '<div class="ppprotect-all-posts"><div>3. Protect all individual posts in this category?</div><div class="ppprotect-posts"><label><input type="checkbox" name="ppprotectPosts" ' . $pChecked . ' /> Yes</label></div></div>';

		$ppprotectCat .= '</div></div>'; // End PP Permissions code

	    echo $ppprotectCat;
	}

	/**
	 * @author Aaron Lamar
	 *
	 * @param array $levels of pilotpress membershipLevels
	 **/
	public function ppprotectSetPPMemLevels($levels)
	{
		$this->membershipLevels = $levels;
	}

	/**
	 * @author William DeAngelis
	 * @var array $membershipLevels An array containing all of the possible permission levels for the site
	 *
	 * @return Communicates with PilotPress and gets the site's membership levels
	 **/
	protected function ppprotectGetPPMemLevels()
	{
	    return $this->membershipLevels;
	}

	/**
	 * @author William DeAngelis
	 * @param string $memLevel A permission level to test if is in the allowed permissions
	 *
	 * @return Check's the current user's membership level against those in the allowed permissions
	 **/
	protected function ppprotectAccessCheck( $memLevel ) 
	{			
		if( isset( $_SESSION['user_levels'] ) && is_array( $_SESSION['user_levels'] ) && in_array( $memLevel, $_SESSION['user_levels'] )) 
		{
			return 1;
		} 
		else 
		{
			return 0;
		}
	}

	/**
	 * @author William DeAngelis
	 * @param string $postID The ID of the post to check
	 *
	 * @return Check's the ID of a post to check to see if it is being protected by category protection and if true then returns the levels of protection. If false then return false.
	 **/
	public function ppprotectCheckForProtection( $postID ) 
	{			
		global $wpdb;
		$table = $wpdb->prefix . 'ppprotect';
		$protectedCategories = array();
		$postCategories = wp_get_post_categories( $postID );
		$result = 0;

		if( $wpdb->get_var("SHOW TABLES LIKE '$table'") === null ) 
		{
		    $this->ppprotectCreateTable();
		}

		$cats = $wpdb->get_results('SELECT itemId FROM ' . $table);

		foreach ( $cats as $cat )
		{
			$protectedCategories[] = $cat->itemId;
		}
 
		foreach ( $postCategories as $postCategory )
		{
			if ( in_array( $postCategory, $protectedCategories ) )
			{
				$levels = $wpdb->get_results('SELECT levels FROM ' . $table . ' WHERE itemId = ' . $postCategory);
				if ($levels && $levels[0] && $levels[0]->levels)
				{
					$result = implode( ', ', json_decode($levels[0]->levels) );
				}
			}
		}

		return $result;
	}

	/**
	 * @author William DeAngelis
	 * @param integer $term_id The ID of the category to be saved
	 * @var string $redirect The URL of the page to redirect the user to when they don't have proper perms
	 * @var string $protectPosts A checkbox option that tells us whether to protect all the posts in the category or just the category page itself.
	 * @var string $name The name of the category being protected
	 * @var array $levels The permission levels used to protect the category
	 *
	 * @return No return.
	 **/
	public function ppprotectSaveFields( $term_id )
	{
		if ( !is_array( $this->membershipLevels) || empty( $this->membershipLevels ) )
		{
			return;
		}

		$redirect = $_POST['ppprotectRedirect'];

		if ( isset($_POST['ppprotectPosts']) )
		{
			$protectPosts = $_POST['ppprotectPosts'];
		}
		else
		{
			$protectPosts = '';
		}

		if ( $protectPosts === 'on' )
		{
			$protectPosts = 1;
		}
		else
		{
			$protectPosts = 0;
		}

		$type = 'category';

		if ( isset($_POST['name']))
		{
			$name = $_POST['name'];
		}
		else
		{
			$name = get_cat_name( $term_id );
		}

		if ( isset( $_POST['ppprotectCat'] ) ) 
		{
			$pppCategory = array();
					
			foreach ( $_POST['ppprotectCat'] as $key => $val )
			{
				array_push($pppCategory, $key);
			}

			$levels = json_encode($pppCategory);
		}
		else
		{
			$levels = '';
		}

		if ( (!isset($redirect) || $redirect == '') && $levels != '' )
		{
			$redirect = '-2';
		}

		$this->ppprotectInsertInDb( $type, $term_id, $name, $levels, $redirect, $protectPosts );
	}

	/**
	 * @author William DeAngelis
	 * @var integer $id The ID of the category to be deleted
	 *
	 * @return boolean $response True or false if the category was deleted from the ppprotect table
	 **/
	public function ppprotectDeleteCategory( $id )
	{
		if ( !is_array( $this->membershipLevels) || empty( $this->membershipLevels ) )
		{
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ppprotect';

		$id = intval($id);
		$items = array( 'itemId' => $id );

		$response = $this->ppprotectDeleteFromDb( $table, $items );

		return $response;
	}

	/**
	 * @author William DeAngelis
	 * @var integer $catId The ID of the category in the loop
	 * @var array $perms An array of all the category's permission options for a given category
	 * @var array $levels An array that contains all of the user's permission levels
	 * @var array $userAccessLevels An array that contains the users permission levels IF they match the levels needed to view this post. IF empty then it will use the redirect
	 *
	 * @return string Checks the users permission levels and the redirects them if they don't have the proper perms
	 **/
	public function ppprotectCategory()
	{
		global $wp_query;

		if ( isset($wp_query->queried_object) && 
			isset($wp_query->queried_object->term_id) && 
			$wp_query->queried_object->slug !== 'uncategorized' )
		{
			$catId = $wp_query->queried_object->term_id;
			$perms = $this->ppprotectGetFromDb( $catId );

			if ( isset( $perms ) && $perms != null && !empty($perms->levels) )
			{
				$userAccessLevels = array();

				$levels = json_decode($perms->levels);			
				if( is_array( $levels ) )
				{
					foreach ( $levels as $level )
					{
						if ( $this->ppprotectAccessCheck($level) === 1 )
						{
							array_push($userAccessLevels, $level);
						}
					}
				}

				// If user does not have any access levels granted... redirect them
				if ( empty($userAccessLevels) )
				{

					if ( !current_user_can('administrator') ) 
					{
						if ( $perms->redirect == '-1' || $perms->redirect == '-2' ) 
						{
							wp_safe_redirect( home_url() );
							exit;
						}

						wp_safe_redirect( $perms->redirect );
						exit;
					}
				}
			}
		}
	}

	/**
	 * @author William DeAngelis
	 * @var integer $postID The ID of the current post
	 * @var array $catOfPost An array containing all of the categories the post is assigned to
	 * @var integer $catId The ID of the category in the loop
	 * @var array $perms An array of all the category's permission options for a given category
	 * @var array $protectCategories A blank array that gets created here and contains all the possible category permission settings
	 * @var array $userAccessLevels An array that contains the users permission levels IF they match the levels needed to view this post. IF empty then it will use the redirect
	 * @var string $selectedOverride Variable set by the user. Two options 'post-override' (Allows the post to override the category settings) and 'category-override' (Allows the category's settings to override the post's.). These options are used here to determine which option is currently selected and to update the option accordingly in the dropdown select.
	 *
	 * @return string Checks the users permission levels and the redirects them if they don't have the proper perms
	 **/
	public function ppprotectPost()
	{
		if (!is_admin() && is_single())
		{
			global $wp_query;
			$postID = $wp_query->post->ID;
			$catOfPost = get_the_category($postID);
			$selectedOverride = get_post_meta( $postID, '_ppProtectCatOverride', true );

			$userAccessLevels = array();
			$protectCategories = array();
			foreach ( $catOfPost as $cat )
			{
				$catId = $cat->term_id;
				$perms = $this->ppprotectGetFromDb( $catId );

				if ( isset($perms) )
				{
					array_push($protectCategories, $perms);
				}
			
				if ( isset($perms) )
				{
					$levels = json_decode($perms->levels);
					if ( isset($levels) )
					{
						foreach ( $levels as $level )
						{
							if ( $this->ppprotectAccessCheck($level) === 1 )
							{
								array_push($userAccessLevels, $level);
							}
						}
					}
				}
			}

			// If user does not have any access levels granted & the post is in a protected category & the post isn't manually specified to use it's own permissions... redirect them to the first categories redirect
			if ( empty($userAccessLevels) && !empty($protectCategories) && $selectedOverride != 'post-override' )
			{
				// If admin user is logged in, let them see the page. Comment this out to test functionality
				if ( !current_user_can('administrator') ) 
				{
					foreach ( $protectCategories as $protectedCat )
					{
						if ( $protectedCat->protectposts == 1 )
						{
							if ( $protectedCat->redirect == '-1' ) 
							{
								wp_redirect( home_url() );
								exit;
							}
							else if ( $protectedCat->redirect == '-2' ) 
							{
								add_filter('the_content', array(&$this, 'ppprotectLoginPage'));
								add_filter( 'comments_open', array(&$this, 'ppprotectCloseComments'), 10, 2 );
								return;
							}

							wp_redirect( $protectedCat->redirect );
							exit;
						}
					}
				}
			}
		}
	}


	/**
	 * @author William DeAngelis
	 * @var object $content The content being filtered
	 *
	 * @return string $lp Removes the page content and replaces it with the login_page shortcode content which is a basic WP login form
	 *
	 **/
	public function ppprotectLoginPage( $content )
	{
		$lp = do_shortcode('[login_page]');
		return $lp;
	}


	/**
	 * @author William DeAngelis
	 * @var boolean $open If the page has comments enabled
	 * @var integer $post_id The id of the page
	 *
	 * @return string $open Disables comments on this page
	 *
	 **/
	public function ppprotectCloseComments( $open, $post_id )
	{
		$open = false;
		return $open;
	}


	/**
	 * @author William DeAngelis
	 * @var object $query The query being called
	 *
	 * @return string The modified query with protected categories being protected.
	 *
	 **/
	function ppprotectHomeAndArchivePosts( $query ) 
	{
	    if ( ($query->is_home() && $query->is_main_query()) || is_archive() ) 
	    {
	        global $wpdb;
			$table = $wpdb->prefix . 'ppprotect';
			$protectedCategories = array();
			$postCategories = get_terms('category', array('hide_empty' => false) );
			$result = 0;

			if( $wpdb->get_var("SHOW TABLES LIKE '$table'") === null ) 
			{
			    $this->ppprotectCreateTable();
			}

			$cats = $wpdb->get_results('SELECT itemId,levels FROM ' . $table . ' WHERE protectposts = 1');
			$protectedCategories = array();
			foreach ( $cats as $key => $value )
			{
				
				if( isset( $_SESSION['user_levels'] ) && is_array( $_SESSION['user_levels'] ) ) 
				{
					$perm = array_intersect( json_decode($value->levels), $_SESSION['user_levels'] );
					if ( empty($perm) )
					{
						array_push( $protectedCategories, '-' . $value->itemId );
					}
				}
				else
				{
					array_push( $protectedCategories, '-' . $value->itemId );
				}

			}
			$protectedCategories = implode(',', $protectedCategories);
			if ($protectedCategories)
			{
				$query->set( 'cat', $protectedCategories );
			}
	    }
	}


	/**
	 * @author William DeAngelis
	 *
	 * @return string After updating the _ppProtectCatOverride setting that determines whether the category or the post's permissions will be used to protect it, it returns the selected option from the db and gives it to AJAX query set in ppprotectAdminCategoryScripts()
	 *
	 * @see ppprotectAdminCategoryScripts() The function that uses this function via AJAX to update and check the override setting
	 **/
	public function wp_ajax_ppprotectAllowOverride()
	{
		if( !empty($_POST) )
	    {
	        update_post_meta( $_POST['postID'], '_ppProtectCatOverride', $_POST['ppOverride']);
	        $response = get_post_meta( $_POST['postID'], '_ppProtectCatOverride', true );
	    } 
	    else 
	    {
	        $response = "No POST detected.";
	    }

	    header( "Content-Type: application/json" );
	    echo json_encode($response);
	    exit();
	}

	/**
	 * @author William DeAngelis
	 * @var integer $postID The ID of the current post
	 * @var array $catOfPost An array containing all of the categories the post is assigned to
	 * @var integer $catId The ID of the category in the loop
	 * @var array $perms An array of all the category's permission options for a given category
	 * @var array $protectedCategories An blank array that gets created here and contains all the possible category permission settings
	 * @var string $selectedOverride Variable set by the user. Two options 'post-override' (Allows the post to override the category settings) and 'category-override' (Allows the category's settings to override the post's.). These options are used here to determine which option is currently selected and to update the option accordingly in the dropdown select.
	 *
	 * @return string Warns users when global cateogry protection settings are taking prescendence, provides an interface to see exactly what the category settings will do, and provides an option to override the category's protection settings
	 **/
	public function ppprotectPostWarning()
	{
		if ( is_admin() )
		{
			global $post;
			$postID = $post->ID;
			$catOfPost = get_the_category($postID);

			$protectedCategories = array();
			foreach ( $catOfPost as $cat )
			{
				$catId = $cat->term_id;
				$perms = $this->ppprotectGetFromDb( $catId );
				if ( isset($perms) && $perms->protectposts == 1 )
				{
					array_push($protectedCategories, $perms);
				}
			}

			$selectedOverride = get_post_meta( $postID, '_ppProtectCatOverride', true );
			switch ($selectedOverride)
			{
				case 'post-override': 
					$postOverride = 'selected="selected"';
					$catOverride = '';
					break;

				case 'category-override':
					$catOverride = 'selected="selected"';
					$postOverride = '';
					break;

				default:
					$catOverride = '';
					$postOverride = '';
			}

			// If post is protected globally, change the PP options to reflect the override.
			if ( !empty($protectedCategories) )
			{
				$message = '<div class="ppprotect-protected-global-wrapper inside">
					<div class="ppprotect-protected-globally">
						<div class="ppprotect-global-message">This post has global category access permissions set in the following category(s) that take priority over the permissions usually found here.</div>
						<div class="ppprotect-global-cats">';

				foreach ( $protectedCategories as $protectedCategory )
				{
					$message .= '<a href="' . site_url() . '/wp-admin/edit-tags.php?action=edit&taxonomy=category&tag_ID=' . $protectedCategory->itemId . '">' . $protectedCategory->name . '</a>';
					
				}

				$message .= '</div>';

				if ( is_array($protectedCategories) && count($protectedCategories) !== 1 )
				{
					$message .= '<div class="ppprotect-main-override">The category whose redirect will take prescedence is:</div>
						<div class="ppprotect-override-name"><a href="' . site_url() . '/wp-admin/edit-tags.php?action=edit&taxonomy=category&tag_ID=' . $protectedCategories[0]->itemId . '">' . $protectedCategories[0]->name . '</a></div>';
				}

				if ( $protectedCategories[0]->redirect == '-1' || $protectedCategories[0]->redirect == '-2' )
				{
					if ( $protectedCategories[0]->redirect == '-1' )
					{
						$redirect = '(homepage)';
					}
					else
					{
						$redirect = '(login page)';
					}
					
					$message .= '<div class="ppprotect-override-location">It currently redirects users to the:<br />' . $redirect . '</div>
						<div class="ppprotect-override-perms">Users with the following access levels have access to this post:';
				}
				else
				{
					$message .= '<div class="ppprotect-override-location">It currently redirects users to:<br /><a href="' . $protectedCategories[0]->redirect . '" target="_blank">' . $protectedCategories[0]->redirect . '</a></div>
						<div class="ppprotect-override-perms">Users with the following access levels have access to this post:';
				}

				if ( isset($protectedCategories[0]->levels) && !empty($protectedCategories[0]->levels) )
				{
					foreach ( json_decode($protectedCategories[0]->levels) as $level )
					{
						$message .= '<div>' . $level . '</div>';
					}
				}
				else
				{
					$message .= '<div>No permissions have been selected.</div>';
				}

				$message .= '</div>
					</div>
					<div class="ppprotect-override-override">
						<select name="ppprotectManualOverride">
							<option value="category-override" ' . $catOverride . '>Category override</option>
							<option value="post-override" ' . $postOverride . '>Set permissions manually</option>
						</select>
					</div>
				</div>';

				echo $message;

				// Bind the JS to the footer to control the category override settings
				add_action( 'admin_footer', array( &$this, 'ppprotectAdminCategoryScripts') );

			}
			
		}		
	}

	/**
	 * @author William DeAngelis
	 * @var integer $postID The ID of the current post
	 * @var string $selectedOverride Variable set by the user. Two options 'post-override' (Allows the post to override the category settings) and 'category-override' (Allows the category's settings to override the post's.). These options are used here to determine what to display in the post's PP options metabox.
	 *
	 * @return string Adds footer scripts to post pages that have category permissions applied to the posts. This js manages the ability to override the category permissions by using an AJAX call to set the $selectedOverride varabile via the wp_ajax_ppprotectAllowOverride function. This variable gets used to control whether the category permission settings or the post's permission settings take prescedence. 
	 *
	 * @see wp_ajax_ppprotectAllowOverride() The function that updates the override variable
	 **/ 
	public function ppprotectAdminCategoryScripts()
	{
		global $post;
		$postID = $post->ID;

		$selectedOverride = get_post_meta( $postID, '_ppProtectCatOverride', true );

		$jsMods = '<script type="text/javascript">
			jQuery(document).ready(function()
			{
				jQuery("#_pilotpress_page_box .inside").addClass("pp-page-box").hide();
				jQuery(".ppprotect-protected-global-wrapper").appendTo(jQuery("#_pilotpress_page_box"));';

			if ( !isset($selectedOverride) || $selectedOverride == 'post-override' )
			{
				$jsMods .= 'jQuery(".pp-page-box").show();
							jQuery(".ppprotect-protected-globally").hide();';
			}

		$jsMods .= '
				jQuery(".ppprotect-override-override select").change(function() {
						
					var selectedOption = jQuery(this).val();
					jQuery.ajax({
						type: "POST",
						url: ajaxurl,
						data: { 
							action: "pp_category_override",
							postID: ' . $postID . ', 
							ppOverride: selectedOption 
						}
					}).done(function( response ) {
						if ( response == "post-override" ) {
							jQuery(".pp-page-box").show();
							jQuery(".ppprotect-protected-globally").hide();
						}
						else if ( response == "category-override" ) {
							jQuery(".pp-page-box").hide();
							jQuery(".ppprotect-protected-globally").show();
						}
					});

				});
			});
		</script>';

		echo $jsMods;
	}

	/**
	 * @author William DeAngelis
	 *
	 * @return string Adds a footer script to admin category setting pages. Informs the user that by choosing to protect all posts in this cateogry the category permissions will override the post permissions.
	 **/
	public function ppprotectCategoryJS()
	{
		$categories = get_current_screen();
		if ( isset($categories) )
		{
			if ( $categories->base == 'edit-tags' )
			{

				$catFoot = '<script type="text/javascript">
					jQuery(document).ready(function()
					{
						jQuery(".ppprotect-posts input:checkbox").change(function()
						{
							if ( this.checked === true )
							{
								var accept = confirm("IMPORTANT - By selecting this option you will override the PilotPress permission settings you may have already added to any of the posts in this category. This means that the settings you just selected here will take prescedence. Once you save this setting you will still be able to manually set permissions for each post, but you will have to open each post and select the option \'Set permissions manually\' to do so. Are you sure you want to proceed with this setting?");
								if ( accept != true )
								{
									jQuery(this).prop("checked", false);
								}
							}
						});
					});
				</script>';

				echo $catFoot;

			}
		}
	}

}