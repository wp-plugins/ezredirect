<?php
/*
Plugin Name: ezRedirect
Plugin URI: http://www.nuagelab.com/wordpress-plugins/ezredirect
Description: Allows creation of URL that redirects to pages or other URLs
Author: NuageLab <wordpress-plugins@nuagelab.com>
Version: 0.0.3
License: GPLv2 or later
Author URI: http://www.nuagelab.com/wordpress-plugins
*/

// --

/**
 * ezRedirect class
 *
 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
 */
class ezredirect {
	private static $_instance = null;

	/**
	 * Bootstrap
	 *
	 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
	 * @access	public
	 */
	public static function boot()
	{
		if (self::$_instance === null) {
			self::$_instance = new ezredirect();
			self::$_instance->setup();
			return true;
		}
		return false;
	} // boot()


	/**
	 * Setup plugin
	 *
	 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
	 * @access	public
	 */
	public function setup()
	{
		global $current_blog;

		// Add admin menu
		add_action('admin_menu', array(&$this, 'add_admin_menu'));

		// Add options
		add_option('ezredirect-dbversion', false);

		// Load text domain
		load_theme_textdomain('ezredirect', dirname(__FILE__).'/languages/');

		// Check if the domain was changed
		$this->upgrade_database();
		
		// Do redirect
		add_action('parse_request', array(&$this, 'do_redirect'), 10);
	} // setup()

	
	/**
	 * Setup/upgrade the database
	 *
	 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
	 * @access	public
	 */
	private function upgrade_database()
	{
		global $wpdb;
		
		$queries = array(
			1=>	"CREATE TABLE IF NOT EXISTS `%prefix%ezredirect` (
					`ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
					`source` varchar(128) NOT NULL,
					`type` enum('post','page','url') NOT NULL DEFAULT 'post',
					`target` varchar(256) NOT NULL,
					PRIMARY KEY (`ID`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;",
			2=>	"ALTER TABLE  `%prefix%ezredirect` ADD UNIQUE (`source`);",
			3=>	"ALTER TABLE  `%prefix%ezredirect` ADD  `anchor` VARCHAR( 128 ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT  '';"
		);
		$max_version = 3;

		$current_version = get_option('ezredirect-dbversion');
		
		
		if ($max_version > $current_version) {
			foreach ($queries as $version=>$query) {
				if ($version > $current_version) {
					$query = str_replace('%prefix%', $wpdb->prefix, $query);
					$wpdb->query($query);
					$max_version = max($max_version, $version);
				}
			}
			update_option('ezredirect-dbversion', $version);
		}
	} // upgrade_database()

	
	/**
	 * Process the redirections
	 *
	 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
	 * @access	public
	 */
	public function do_redirect() 
	{
		global $wpdb;
		
		// Get address
		$addr = $_SERVER['REQUEST_URI'];
		
		// Strip
		if (strpos($addr,'?') !== false) $addr = substr($addr,0,strpos($addr,'?'));
		if (substr($addr,-1) == '/') $addr = substr($addr,0,-1);
		
		$sql = $wpdb->prepare("SELECT * FROM ".$wpdb->prefix.'ezredirect WHERE `source`=%s;', $addr );
		
		$redirs = $wpdb->get_results( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix.'ezredirect WHERE `source`=%s OR `source`=%s;', $addr, urldecode($addr) ) );
		if (count($redirs) > 0) {
			$redir = reset($redirs);
			
			switch ($redir->type) {
				case 'page':
				case 'post':
					$target = get_permalink(intval($redir->target));
					if (!empty($redir->anchor)) $target .= '#'.$redir->anchor;
					break;
				case 'url':
					$target = $redir->target;
					break;
			}
			if (!empty($target)) {
				header('Location: '.$target);
				echo <<<EOD
<html>
<head><title>This page has moved</title></head>
<body>
	Redirecting to <a href="${target}">${target}</a>.
	<script type="text/javascript">
		document.location = "${target}";
	</script>
</body>
</html>
EOD;
				die;
			}
		}
	} // do_redirect()
	
	
	/**
	 * Add admin menu action; added by setup()
	 *
	 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
	 * @access	public
	 */
	public function add_admin_menu()
	{
		add_options_page(__("ezRedirect",'ezredirect'), __("ezRedirect",'auto-domain-change'), 'update_core', basename(__FILE__), array(&$this, 'admin_page'));
	} // add_admin_menu()


	/**
	 * Admin page action; added by add_admin_menu()
	 *
	 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
	 * @access	public
	 */
	public function admin_page()
	{
		global $wpdb;
		if (isset($_POST['action'])) {
			if (wp_verify_nonce($_POST['nonce'],$_POST['action'])) {
				$parts = explode('+',$_POST['action']);
				switch ($parts[0]) {
					case 'add-redir':
						// Get source
						$source = $_POST['redir_source'];
						if (substr($source,0,1) != '/') $source = '/'.$source;
						while (substr($source,-1) == '/') $source = substr($source,0,-1);
						
						// Get type
						$type = $_POST['redir_type'];
						
						// Get target
						switch ($type) {
							case 'page': $target = $_POST['redir_page_id']; $anchor = trim($_POST['redir_anchor']); break;
							case 'post': $target = $_POST['redir_post_id']; $anchor = trim($_POST['redir_anchor']); break;
							case 'url': $target = $_POST['redir_url']; $anchor = ''; break;
						}
						
						// Trim anchor
						if (substr($anchor,0,1) == '#') $anchor = substr($anchor,1);
						
						// Insert
						$wpdb->insert( 
							$wpdb->prefix.'ezredirect', 
							array( 
								'source' => $source,
								'type' => $type,
								'target' => $target,
								'anchor' => $anchor,
							), 
							array( 
								'%s', 
								'%s', 
								'%s', 
								'%s', 
							) 
						);
						break;
					case 'del-redir':
						/*print '<pre>';
						print_r($_POST);
						die;*/
						
						foreach ($_POST as $k=>$v) {
							if (substr($k,0,7) == 'delete_') {
								$id = substr($k,7);
								$wpdb->query( 
									$wpdb->prepare( 
										"DELETE FROM ".$wpdb->prefix."ezredirect WHERE ID=%d;",
										$id
									)
								);
							}
						}
						break;
				}
			}
		}

		if (!isset($error_terms)) $error_terms = false;

		echo '<div class="wrap">';

		echo '<div id="icon-tools" class="icon32"><br></div>';
		echo '<h2>'.__('ezRedirect','ezredirect').'</h2>';

		echo '<form method="post">';
		$action = 'del-redir+'.uniqid();
		wp_nonce_field($action,'nonce');
		echo '<input type="hidden" name="action" value="'.$action.'" />';

		echo '<h3>'.__('Active redirection(s)').'</h3>';
		
		echo '<table class="widefat fixed">';
		echo '<thead>';
		echo '<tr><th>'.__('Source URL','ezredirect').'</th><th>'.__('Destination','ezredirect').'</th><th>'.__('Action','ezredirect').'</th></tr>';
		echo '</thead>';
		
		echo '<tbody>';
		
		$pages = get_posts(array('post_type'=>'page','orderby'=>'title','order'=>'ASC','posts_per_page'=>-1));
		$posts = get_posts(array('post_type'=>'post','orderby'=>'title','order'=>'ASC','posts_per_page'=>-1));
		$redirs = $wpdb->get_results( "SELECT * FROM ".$wpdb->prefix.'ezredirect ORDER BY `source`;' );
		if (count($redirs) > 0) {
			foreach ($redirs as $redir) {
				echo '<tr valign="middle">';
				echo '<td>'.esc_html($redir->source).'</td>';
				echo '<td>';
				if ($redir->anchor != '') $anchor = '#'.$redir->anchor;
				else $anchor = '';
				switch ($redir->type) {
					case 'page':
						$page = get_page($redir->target);
						echo __('Page','ezredirect').', <a target="_blank" href="'.get_permalink($page->ID).$anchor.'">'.esc_html($page->post_title).'</a>'.$anchor;
						break;
					case 'post':
						$post = get_post($redir->target);
						echo __('Post','ezredirect').', <a target="_blank" href="'.get_permalink($post->ID).$anchor.'">'.esc_html($post->post_title).'</a>'.$anchor;
						break;
					case 'url':
						echo __('URL','ezredirect').', <a target="_blank" href="'.esc_url($redir->target).'">'.esc_url($redir->target).'</a>';
						break;
				}
				echo '</td>';
				echo '<td>';
				echo '<input type="submit" name="delete_'.$redir->ID.'" id="submit" class="button" value="'.esc_html(__('Delete','ezredirect')).'">';
				echo '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr valign="top">';
			echo '<td colspan="2" align="center"><em>('.__('no redirections, add one below','ezredirect').')</em></td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
		
		echo '</form>';
		
		echo '<h3>'.__('Add a redirection').'</h3>';

		echo '<form method="post" style="max-width:500px;">';
		$action = 'add-redir+'.uniqid();
		wp_nonce_field($action,'nonce');
		echo '<input type="hidden" name="action" value="'.$action.'" />';
		$redir = new stdClass;
		echo '<p><label for="redir_type">'.__('Source:','ezredirect').'</label><br/>';
		echo '<input type="text" name="redir_source" id="redir_source" class="widefat" value="'.esc_attr($redir->source).'"/><br/>';
		echo '<small>'.__('Example: /some-short-url','ezredirect').'</small>';
		echo '</p>';
		
		echo '<p><label for="redir_type">'.__('Type:','ezredirect').'</label><br/>';
		echo '<select name="redir_type" id="redir_type" class="widefat">';
		echo '<option value="page"'.($redir->type == 'page' ? ' selected="selected"' : '').'>'.__('Redirect to internal page','ezredirect').'</option>';
		echo '<option value="post"'.($redir->type == 'post' ? ' selected="selected"' : '').'>'.__('Redirect to internal post','ezredirect').'</option>';
		echo '<option value="url"'.($redir->type == 'url' ? ' selected="selected"' : '').'>'.__('Redirect to external URL','ezredirect').'</option>';
		echo '</select>';
		echo '</p>';
		
		echo '<p class="redir_page_id"><label for="redir_page_id">'.__('Page:','ezredirect').'</label><br/>';
		echo '<select name="redir_page_id" id="redir_page_id" class="widefat">';
		//foreach ($pages as $page) echo '<option value="'.$page->ID.'"'.(($redir->type == 'page' && $page->ID == $redir->target)?' selected="selected"':'').'>'.esc_html($page->post_title).'</option>';
		$this->display_page_options();
		echo '</select>';
		echo '</p>';
		
		echo '<p class="redir_post_id" style="display:none;">';
		echo '<label for="redir_post_id">'.__('Post:','ezredirect').'</label><br/>';
		echo '<select name="redir_post_id" id="redir_post_id" class="widefat">';
		foreach ($posts as $post) echo '<option value="'.$post->ID.'"'.(($redir->type == 'post' && $post->ID == $redir->target)?' selected="selected"':'').'>'.esc_html($post->post_title).'</option>';
		echo '</select>';
		echo '</p>';
		
		echo '<p class="redir_url" style="display:none;">';
		echo '<label for="redir_url">'.__('URL:','ezredirect').'</label><br/>';
		echo '<input type="text" name="redir_url" id="redir_url" class="widefat" value=""><br>';
		echo '<small>'.__('Example: http://www.somedomain.com/some-short-url','ezredirect').'</small>';
		echo '</p>';
		
		echo '<p class="redir_anchor">';
		echo '<label for="redir_anchor">'.__('Anchor name (optional):','ezredirect').'</label><br/>';
		echo '<input type="text" name="redir_anchor" id="redir_anchor" class="widefat" value=""><br>';
		echo '<small>'.__('Example: some-anchor will add #some-anchor','ezredirect').'</small>';
		echo '</p>';
		
		echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button-primary" value="'.esc_html(__('Add','ezredirect')).'"></p>';

		echo '</form>';

		echo '</div>';
		
		echo <<<EOD
	
	<script>
		(function($){
			$('#redir_type').change(function(){
				$('.redir_url,.redir_page_id,.redir_post_id').hide();
				switch ($(this).val()) {
					case 'page':
						$('.redir_page_id').show();
						$('.redir_anchor').show();
						break;
					case 'post':
						$('.redir_post_id').show();
						$('.redir_anchor').show();
						break;
					case 'url':
						$('.redir_url').show();
						$('.redir_anchor').hide();
						break
				}
			});
		})(jQuery);
	</script>
	
EOD;
	} // admin_page()

	/**
	 * Display pages in hierarchy in <option> tags for select
	 *
	 * @author	Tommy Lacroix <tlacroix@nuagelab.com>
	 * @access	protected
	 * @internal
	 */
	protected function display_page_options($pages=null, $level=0)
	{
		if ($pages === null) {
			$level = 0;
			$pages = get_posts(array('post_type'=>'page','orderby'=>'title','order'=>'ASC','numberposts'=>-1));
			$map = $tree = array();
			foreach ($pages as &$page) {
				$page->children = array();
				$map[$page->ID] = &$page;
			}
			foreach ($pages as &$page) {
				if (!$page->post_parent) $tree[$page->ID] = $page;
				else $map[$page->post_parent]->children[] = $page;
			}
			unset($page);
			$pages = $tree;
		}


		foreach ($pages as $page) {
			echo '<option value="'.$page->ID.'">';
			if ($level > 0) echo str_repeat('-', $level*3).' ';
			echo apply_filters('the_title',$page->post_title);
			echo '</option>';
			if (count($page->children) > 0) $this->display_page_options($page->children, $level+1);
		}
	} // display_page_options()

} // ezredirect class


// Initialize
ezredirect::boot();
