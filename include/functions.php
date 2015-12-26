<?php

/**
 * Copyright (C) 2013-2014 ModernBB
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://opensource.org/licenses/MIT MIT
 */

include FORUM_ROOT.'include/srand.php';

//
// Return current timestamp (with microseconds) as a float
//
function get_microtime()
{
	list($usec, $sec) = explode(' ', microtime());
	return ((float)$usec + (float)$sec);
}

//
// Cookie stuff!
//
function check_cookie(&$luna_user)
{
	global $db, $db_type, $luna_config, $cookie_name, $cookie_seed;

	$now = time();

	// If the cookie is set and it matches the correct pattern, then read the values from it
	if (isset($_COOKIE[$cookie_name]) && preg_match('%^(\d+)\|([0-9a-fA-F]+)\|(\d+)\|([0-9a-fA-F]+)$%', $_COOKIE[$cookie_name], $matches))
	{
		$cookie = array(
			'user_id'			=> intval($matches[1]),
			'password_hash' 	=> $matches[2],
			'expiration_time'	=> intval($matches[3]),
			'cookie_hash'		=> $matches[4],
		);
	}

	// If it has a non-guest user, and hasn't expired
	if (isset($cookie) && $cookie['user_id'] > 1 && $cookie['expiration_time'] > $now)
	{
		// If the cookie has been tampered with
		if (forum_hmac($cookie['user_id'].'|'.$cookie['expiration_time'], $cookie_seed.'_cookie_hash') != $cookie['cookie_hash'])
		{
			$expire = $now + 31536000; // The cookie expires after a year
			luna_setcookie(1, luna_hash(uniqid(rand(), true)), $expire);
			set_default_user();

			return;
		}

		// Check if there's a user with the user ID and password hash from the cookie
		$result = $db->query('SELECT u.*, g.*, o.logged, o.idle FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'groups AS g ON u.group_id=g.g_id LEFT JOIN '.$db->prefix.'online AS o ON o.user_id=u.id WHERE u.id='.intval($cookie['user_id'])) or error('Unable to fetch user information', __FILE__, __LINE__, $db->error());
		$luna_user = $db->fetch_assoc($result);

		// If user authorisation failed
		if (!isset($luna_user['id']) || forum_hmac($luna_user['password'], $cookie_seed.'_password_hash') !== $cookie['password_hash'])
		{
			$expire = $now + 31536000; // The cookie expires after a year
			luna_setcookie(1, luna_hash(uniqid(rand(), true)), $expire);
			set_default_user();

			return;
		}

		// Send a new, updated cookie with a new expiration timestamp
		$expire = ($cookie['expiration_time'] > $now + $luna_config['o_timeout_visit']) ? $now + 1209600 : $now + $luna_config['o_timeout_visit'];
		luna_setcookie($luna_user['id'], $luna_user['password'], $expire);

		// Set a default language if the user selected language no longer exists
		if (!file_exists(FORUM_ROOT.'lang/'.$luna_user['language']))
			$luna_user['language'] = $luna_config['o_default_lang'];

		// Set a default style if the user selected style no longer exists
		if (!file_exists(FORUM_ROOT.'style/'.$luna_user['style'].'/style.css'))
			$luna_user['style'] = $luna_config['o_default_style'];

		if (!$luna_user['disp_topics'])
			$luna_user['disp_topics'] = $luna_config['o_disp_topics_default'];
		if (!$luna_user['disp_posts'])
			$luna_user['disp_posts'] = $luna_config['o_disp_posts_default'];

		// Define this if you want this visit to affect the online list and the users last visit data
		if (!defined('FORUM_QUIET_VISIT'))
		{
			// Update the online list
			if (!$luna_user['logged'])
			{
				$luna_user['logged'] = $now;

				// With MySQL/MySQLi/SQLite, REPLACE INTO avoids a user having two rows in the online table
				switch ($db_type)
				{
					case 'mysql':
					case 'mysqli':
					case 'mysql_innodb':
					case 'mysqli_innodb':
					case 'sqlite':
						$db->query('REPLACE INTO '.$db->prefix.'online (user_id, ident, logged) VALUES('.$luna_user['id'].', \''.$db->escape($luna_user['username']).'\', '.$luna_user['logged'].')') or error('Unable to insert into online list', __FILE__, __LINE__, $db->error());
						break;

					default:
						$db->query('INSERT INTO '.$db->prefix.'online (user_id, ident, logged) SELECT '.$luna_user['id'].', \''.$db->escape($luna_user['username']).'\', '.$luna_user['logged'].' WHERE NOT EXISTS (SELECT 1 FROM '.$db->prefix.'online WHERE user_id='.$luna_user['id'].')') or error('Unable to insert into online list', __FILE__, __LINE__, $db->error());
						break;
				}

				// Reset tracked topics
				set_tracked_topics(null);
			}
			else
			{
				// Special case: We've timed out, but no other user has browsed the forums since we timed out
				if ($luna_user['logged'] < ($now-$luna_config['o_timeout_visit']))
				{
					$db->query('UPDATE '.$db->prefix.'users SET last_visit='.$luna_user['logged'].' WHERE id='.$luna_user['id']) or error('Unable to update user visit data', __FILE__, __LINE__, $db->error());
					$luna_user['last_visit'] = $luna_user['logged'];
				}

				$idle_sql = ($luna_user['idle'] == '1') ? ', idle=0' : '';
				$db->query('UPDATE '.$db->prefix.'online SET logged='.$now.$idle_sql.' WHERE user_id='.$luna_user['id']) or error('Unable to update online list', __FILE__, __LINE__, $db->error());

				// Update tracked topics with the current expire time
				if (isset($_COOKIE[$cookie_name.'_track']))
					forum_setcookie($cookie_name.'_track', $_COOKIE[$cookie_name.'_track'], $now + $luna_config['o_timeout_visit']);
			}
		}
		else
		{
			if (!$luna_user['logged'])
				$luna_user['logged'] = $luna_user['last_visit'];
		}

		$luna_user['is_guest'] = false;
		$luna_user['is_admmod'] = $luna_user['g_id'] == FORUM_ADMIN || $luna_user['g_moderator'] == '1';
	}
	else
		set_default_user();
}


//
// Converts the CDATA end sequence ]]> into ]]&gt;
//
function escape_cdata($str)
{
	return str_replace(']]>', ']]&gt;', $str);
}


//
// Authenticates the provided username and password against the user database
// $user can be either a user ID (integer) or a username (string)
// $password can be either a plaintext password or a password hash including salt ($password_is_hash must be set accordingly)
//
function authenticate_user($user, $password, $password_is_hash = false)
{
	global $db, $luna_user;

	// Check if there's a user matching $user and $password
	$result = $db->query('SELECT u.*, g.*, o.logged, o.idle FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'groups AS g ON g.g_id=u.group_id LEFT JOIN '.$db->prefix.'online AS o ON o.user_id=u.id WHERE '.(is_int($user) ? 'u.id='.intval($user) : 'u.username=\''.$db->escape($user).'\'')) or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());
	$luna_user = $db->fetch_assoc($result);

	if (!isset($luna_user['id']) ||
		($password_is_hash && $password != $luna_user['password']) ||
		(!$password_is_hash && luna_hash($password) != $luna_user['password']))
		set_default_user();
	else
		$luna_user['is_guest'] = false;
}

//
// Display the admin navigation menu
//
function generate_admin_menu($section, $page)
{
	global $luna_config, $luna_user, $lang;

	$is_admin = $luna_user['g_id'] == FORUM_ADMIN ? true : false;

	// What page are we on?
	if ($page == 'index')
		$page_title = '<span class="fa fa-fw fa-tachometer"></span> '.$lang['Backstage'];
	elseif ($page == 'stats')
		$page_title = '<span class="fa fa-fw fa-info-circle"></span> '.$lang['System info'];
	elseif ($page == 'update')
		$page_title = '<span class="fa fa-fw fa-cloud-upload"></span> '.$lang['ModernBB software updates'];
	elseif ($page == 'about')
		$page_title = '<span class="fa fa-fw fa-moon-o"></span> '.$lang['About Luna'];

	elseif ($page == 'board')
		$page_title = '<span class="fa fa-fw fa-sort-amount-desc"></span> '.$lang['Board'];
	elseif ($page == 'censoring')
		$page_title = '<span class="fa fa-fw fa-eye-slash"></span> '.$lang['Censoring'];
	elseif ($page == 'reports')
		$page_title = '<span class="fa fa-fw fa-exclamation-triangle"></span> '.$lang['Reports'];

	elseif ($page == 'users')
		$page_title = '<span class="fa fa-fw fa-search"></span> '.$lang['Search'];
	elseif ($page == 'ranks')
		$page_title = '<span class="fa fa-fw fa-chevron-up"></span> '.$lang['Ranks'];
	elseif ($page == 'groups')
		$page_title = '<span class="fa fa-fw fa-group"></span> '.$lang['Groups'];
	elseif ($page == 'permissions')
		$page_title = '<span class="fa fa-fw fa-check-circle"></span> '.$lang['Permissions'];
	elseif ($page == 'bans')
		$page_title = '<span class="fa fa-fw fa-ban"></span> '.$lang['Bans'];

	elseif ($page == 'settings')
		$page_title = '<span class="fa fa-fw fa-cogs"></span> '.$lang['Settings'];
	elseif ($page == 'features')
		$page_title = '<span class="fa fa-fw fa-sliders"></span> '.$lang['Features'];
	elseif ($page == 'appearance')
		$page_title = '<span class="fa fa-fw fa-eye"></span> '.$lang['Appearance'];
	elseif ($page == 'registration')
		$page_title = '<span class="fa fa-fw fa-plus-circle"></span> '.$lang['Registration'];
	elseif ($page == 'email')
		$page_title = '<span class="fa fa-fw fa-envelope"></span> '.$lang['Email'];
	elseif ($page == 'theme')
		$page_title = '<span class="fa fa-fw fa-paint-brush"></span> '.$lang['Style'];
	elseif ($page == 'maintenance')
		$page_title = '<span class="fa fa-fw fa-coffee"></span> '.$lang['Maintenance'];
	elseif ($page == 'database')
		$page_title = '<span class="fa fa-fw fa-database"></span> '.$lang['Database'];

	elseif ($page == 'info')
		$page_title = '<span class="fa fa-fw fa-info-circle"></span> '.$lang['Info'];
		
	else
		$page_title = $page;

?>
<nav class="navbar navbar-fixed-top navbar-default" role="navigation">
	<div class="container">
		<div class="navbar-header">
			<button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
				<span class="sr-only">Toggle navigation</span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
			</button>
			<a class="navbar-brand" href="../index.php"><span class="fa fa-fw fa-arrow-left hidden-xs"></span><span class="visible-xs-inline"><?php echo $page_title ?></span></a>
		</div>
		<div class="navbar-collapse collapse">
			<ul class="nav navbar-nav">
				<li class="<?php if ($section == 'backstage') echo 'active'; ?>"><a href="index.php"><span class="fa fa-fw fa-dashboard"></span> <?php echo $lang['Backstage'] ?></a></li>
				<?php if ($is_admin) { ?>
					<li class="<?php if ($section == 'content') echo 'active'; ?>"><a href="board.php"><span class="fa fa-fw fa-file"></span> <?php echo $lang['Content'] ?></a></li>
				<?php } else { ?>
					<li class="<?php if ($section == 'content') echo 'active'; ?>"><a href="reports.php"><span class="fa fa-fw fa-file"></span> <?php echo $lang['Content'] ?></a></li>
				<?php } ?>
				<li class="<?php if ($section == 'users') echo 'active'; ?>"><a href="users.php"><span class="fa fa-fw fa-users"></span> <?php echo $lang['Users'] ?></a></li>
				<?php if ($is_admin) { ?><li class="<?php if ($section == 'settings') echo 'active'; ?>"><a href="settings.php"><span class="fa fa-fw fa-cog"></span> <?php echo $lang['Settings'] ?></a></li><?php } ?>
<?php

	// See if there are any plugins
	$plugins = forum_list_plugins($is_admin);

	// Did we find any plugins?
	if (!empty($plugins))
	{
?>
				<li class="dropdown<?php if ($section == ' extensions') echo 'active'; ?>">
					<a href="#" class="dropdown-toggle" data-toggle="dropdown">
						<span class="fa fa-fw fa-cogs"></span> <?php echo $lang['Extensions'] ?> <span class="fa fa-fw fa-angle-down">
					</a>
					<ul class="dropdown-menu">
<?php
		foreach ($plugins as $plugin_name => $plugin)
			echo "\t\t\t\t\t".'<li><a href="loader.php?plugin='.$plugin_name.'">'.str_replace('_', ' ', $plugin).'</a></li>'."\n";
?>
					</ul>
				</li>
<?php } ?>
			</ul>
<?php
$logout_url = '../login.php?action=out&amp;id='.$luna_user['id'].'&amp;csrf_token='.luna_hash($luna_user['id'].luna_hash(get_remote_address()));
?>
			<ul class="nav navbar-nav navbar-right">
				<li class="dropdown usermenu">
					<a href="../profile.php?id=<?php echo $luna_user['id'] ?>" class="dropdown-toggle dropdown-user" data-toggle="dropdown">
						<?php print(luna_htmlspecialchars($luna_user['username'])) ?> <span class="fa fa-fw fa-angle-down hide-if-no-js"></span>
					</a>
					<ul class="dropdown-menu">
						<li><a href="../profile.php?id=<?php echo $luna_user['id'] ?>"><?php echo $lang['Profile'] ?></a></li>
						<li><a href="../profile.php?section=settings&id=<?php echo ''.$luna_user['id'] ?>"><?php echo $lang['Settings'] ?></a></li>
						<li class="divider"></li>
						<li><a href="../help.php"><?php echo $lang['Help'] ?></a></li>
						<li><a href="http://getluna.org"><?php echo $lang['Support'] ?></a></li>
						<li class="divider"></li>
						<li><a href="<?php echo $logout_url; ?>"><?php echo $lang['Logout'] ?></a></li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
</nav>
<div class="jumbotron jumboheader">
	<div class="container">
		<div class="row">
			<h2 class="hidden-xs">
				<?php
					echo $page_title;
					if ($luna_config['o_update_ring'] > 1)
						echo '<span class="pull-right" style="font-size: 70%;">Core '.Version::FORUM_CORE_VERSION.'</span>';
				?>
			</h2>
			<?php if ($section == 'backstage') { ?>
			<ul class="nav nav-tabs" role="tablist">
				<li<?php if($page == 'index') echo ' class="active"' ?>><a href="index.php"><span class="fa fa-fw fa-tachometer"></span><span class="hidden-xs"> <?php echo $lang['Backstage'] ?></span></a></li>
				<li<?php if($page == 'stats') echo ' class="active"' ?>><a href="statistics.php"><span class="fa fa-fw fa-info-circle"></span><span class="hidden-xs"> <?php echo $lang['System info'] ?></span></a></li>
				<li<?php if($page == 'update') echo ' class="active"' ?>><a href="update.php"><span class="fa fa-fw fa-cloud-upload"></span><span class="hidden-xs"> <?php echo $lang['Update'] ?></span></a></li>
			</ul>
			<?php } if ($section == 'content') { ?>
			<ul class="nav nav-tabs" role="tablist">
				<li<?php if($page == 'board') echo ' class="active"' ?>><a href="board.php"><span class="fa fa-fw fa-sort-amount-desc"></span><span class="hidden-xs"> <?php echo $lang['Board'] ?></span></a></li>
				<li<?php if($page == 'censoring') echo ' class="active"' ?>><a href="censoring.php"><span class="fa fa-fw fa-eye-slash"></span><span class="hidden-xs"> <?php echo $lang['Censoring'] ?></span></a></li>
				<li<?php if($page == 'reports') echo ' class="active"' ?>><a href="reports.php"><span class="fa fa-fw fa-exclamation-triangle"></span><span class="hidden-xs"> <?php echo $lang['Reports'] ?></span></a></li>
			</ul>
			<?php } if ($section == 'users') { ?>
			<ul class="nav nav-tabs" role="tablist">
				<li<?php if($page == 'users') echo ' class="active"' ?>><a href="users.php"><span class="fa fa-fw fa-search"></span><span class="hidden-xs"> <?php echo $lang['Search'] ?></span></a></li>
				<li<?php if($page == 'ranks') echo ' class="active"' ?>><a href="ranks.php"><span class="fa fa-fw fa-chevron-up"></span><span class="hidden-xs"> <?php echo $lang['Ranks'] ?></span></a></li>
				<li<?php if($page == 'groups') echo ' class="active"' ?>><a href="groups.php"><span class="fa fa-fw fa-group"></span><span class="hidden-xs"> <?php echo $lang['Groups'] ?></span></a></li>
				<li<?php if($page == 'permissions') echo ' class="active"' ?>><a href="permissions.php"><span class="fa fa-fw fa-check-circle"></span><span class="hidden-xs"> <?php echo $lang['Permissions'] ?></span></a></li>
				<li<?php if($page == 'bans') echo ' class="active"' ?>><a href="bans.php"><span class="fa fa-fw fa-ban"></span><span class="hidden-xs"> <?php echo $lang['Bans'] ?></span></a></li>
			</ul>
			<?php } if ($section == 'settings') { ?>
			<ul class="nav nav-tabs" role="tablist">
				<li<?php if($page == 'settings') echo ' class="active"' ?>><a href="settings.php"><span class="fa fa-fw fa-cogs"></span><span class="hidden-xs"> <?php echo $lang['Settings'] ?></span></a></li>
				<li<?php if($page == 'features') echo ' class="active"' ?>><a href="features.php"><span class="fa fa-fw fa-sliders"></span><span class="hidden-xs"> <?php echo $lang['Features'] ?></span></a></li>
				<li<?php if($page == 'appearance') echo ' class="active"' ?>><a href="appearance.php"><span class="fa fa-fw fa-eye"></span><span class="hidden-xs"> <?php echo $lang['Appearance'] ?></span></a></li>
				<li<?php if($page == 'registration') echo ' class="active"' ?>><a href="registration.php"><span class="fa fa-fw fa-plus-circle"></span><span class="hidden-xs"> <?php echo $lang['Registration'] ?></span></a></li>
				<li<?php if($page == 'email') echo ' class="active"' ?>><a href="email.php"><span class="fa fa-fw fa-envelope"></span><span class="hidden-xs"> <?php echo $lang['Email'] ?></span></a></li>
				<li<?php if($page == 'theme') echo ' class="active"' ?>><a href="theme.php"><span class="fa fa-fw fa-paint-brush"></span><span class="hidden-xs"> <?php echo $lang['Style'] ?></span></a></li>
				<li<?php if($page == 'maintenance') echo ' class="active"' ?>><a href="maintenance.php"><span class="fa fa-fw fa-coffee"></span><span class="hidden-xs"> <?php echo $lang['Maintenance'] ?></span></a></li>
				<li<?php if($page == 'database') echo ' class="active"' ?>><a href="database.php"><span class="fa fa-fw fa-database"></span><span class="hidden-xs"> <?php echo $lang['Database'] ?></span></a></li>
			</ul>
			<?php } ?>
		</div>
	</div>
</div>
<div class="content">
    <div class="container">
        <div class="row">

<?php

}


//
// Delete topics from $forum_id that are "older than" $prune_date (if $prune_sticky is 1, sticky topics will also be deleted)
//
function prune($forum_id, $prune_sticky, $prune_date)
{
	global $db;

	$extra_sql = ($prune_date != -1) ? ' AND last_post<'.$prune_date : '';

	if (!$prune_sticky)
		$extra_sql .= ' AND sticky=\'0\'';

	// Fetch topics to prune
	$result = $db->query('SELECT id FROM '.$db->prefix.'topics WHERE forum_id='.$forum_id.$extra_sql, true) or error('Unable to fetch topics', __FILE__, __LINE__, $db->error());

	$topic_ids = '';
	while ($row = $db->fetch_row($result))
		$topic_ids .= (($topic_ids != '') ? ',' : '').$row[0];

	if ($topic_ids != '')
	{
		// Fetch posts to prune
		$result = $db->query('SELECT id FROM '.$db->prefix.'posts WHERE topic_id IN('.$topic_ids.')', true) or error('Unable to fetch posts', __FILE__, __LINE__, $db->error());

		$post_ids = '';
		while ($row = $db->fetch_row($result))
			$post_ids .= (($post_ids != '') ? ',' : '').$row[0];

		if ($post_ids != '')
		{
			// Delete topics
			$db->query('DELETE FROM '.$db->prefix.'topics WHERE id IN('.$topic_ids.')') or error('Unable to prune topics', __FILE__, __LINE__, $db->error());
			// Delete subscriptions
			$db->query('DELETE FROM '.$db->prefix.'topic_subscriptions WHERE topic_id IN('.$topic_ids.')') or error('Unable to prune subscriptions', __FILE__, __LINE__, $db->error());
			// Delete posts
			$db->query('DELETE FROM '.$db->prefix.'posts WHERE id IN('.$post_ids.')') or error('Unable to prune posts', __FILE__, __LINE__, $db->error());

			// We removed a bunch of posts, so now we have to update the search index
			require_once FORUM_ROOT.'include/search_idx.php';
			strip_search_index($post_ids);
		}
	}
}


//
// Try to determine the current URL
//
function get_current_url($max_length = 0)
{
	$protocol = get_current_protocol();
	$port = (isset($_SERVER['SERVER_PORT']) && (($_SERVER['SERVER_PORT'] != '80' && $protocol == 'http') || ($_SERVER['SERVER_PORT'] != '443' && $protocol == 'https')) && strpos($_SERVER['HTTP_HOST'], ':') === false) ? ':'.$_SERVER['SERVER_PORT'] : '';

	$url = urldecode($protocol.'://'.$_SERVER['HTTP_HOST'].$port.$_SERVER['REQUEST_URI']);

	if (strlen($url) <= $max_length || $max_length == 0)
		return $url;

	// We can't find a short enough url
	return null;
}


//
// Fetch the current protocol in use - http or https
//
function get_current_protocol()
{
	$protocol = 'http';

	// Check if the server is claiming to using HTTPS
	if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) != 'off')
		$protocol = 'https';

	// If we are behind a reverse proxy try to decide which protocol it is using
	if (defined('FORUM_BEHIND_REVERSE_PROXY'))
	{
		// Check if we are behind a Microsoft based reverse proxy
		if (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) != 'off')
			$protocol = 'https';

		// Check if we're behind a "proper" reverse proxy, and what protocol it's using
		if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
			$protocol = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
	}

	return $protocol;
}

//
// Fetch the base_url, optionally support HTTPS and HTTP
//
function get_base_url($support_https = false)
{
	global $luna_config;
	static $base_url;

	if (!$support_https)
		return $luna_config['o_base_url'];

	if (!isset($base_url))
	{
		// Make sure we are using the correct protocol
		$base_url = str_replace(array('http://', 'https://'), get_current_protocol().'://', $luna_config['o_base_url']);
	}

	return $base_url;
}


//
// Fetch admin IDs
//
function get_admin_ids()
{
	if (file_exists(FORUM_CACHE_DIR.'cache_admins.php'))
		include FORUM_CACHE_DIR.'cache_admins.php';

	if (!defined('FORUM_ADMINS_LOADED'))
	{
		if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
			require FORUM_ROOT.'include/cache.php';

		generate_admins_cache();
		require FORUM_CACHE_DIR.'cache_admins.php';
	}

	return $luna_admins;
}

//
// Fill $luna_user with default values (for guests)
//
function set_default_user()
{
	global $db, $db_type, $luna_user, $luna_config;

	$remote_addr = get_remote_address();

	// Fetch guest user
	$result = $db->query('SELECT u.*, g.*, o.logged, o.last_post, o.last_search FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'groups AS g ON u.group_id=g.g_id LEFT JOIN '.$db->prefix.'online AS o ON o.ident=\''.$db->escape($remote_addr).'\' WHERE u.id=1') or error('Unable to fetch guest information', __FILE__, __LINE__, $db->error());
	if (!$db->num_rows($result))
		exit('Unable to fetch guest information. Your database must contain both a guest user and a guest user group.');

	$luna_user = $db->fetch_assoc($result);

	// Update online list
	if (!$luna_user['logged'])
	{
		$luna_user['logged'] = time();

		// With MySQL/MySQLi/QLite, REPLACE INTO avoids a user having two rows in the online table
		switch ($db_type)
		{
			case 'mysql':
			case 'mysqli':
			case 'mysql_innodb':
			case 'mysqli_innodb':
			case 'sqlite':
				$db->query('REPLACE INTO '.$db->prefix.'online (user_id, ident, logged) VALUES(1, \''.$db->escape($remote_addr).'\', '.$luna_user['logged'].')') or error('Unable to insert into online list', __FILE__, __LINE__, $db->error());
				break;

			default:
				$db->query('INSERT INTO '.$db->prefix.'online (user_id, ident, logged) SELECT 1, \''.$db->escape($remote_addr).'\', '.$luna_user['logged'].' WHERE NOT EXISTS (SELECT 1 FROM '.$db->prefix.'online WHERE ident=\''.$db->escape($remote_addr).'\')') or error('Unable to insert into online list', __FILE__, __LINE__, $db->error());
				break;
		}
	}
	else
		$db->query('UPDATE '.$db->prefix.'online SET logged='.time().' WHERE ident=\''.$db->escape($remote_addr).'\'') or error('Unable to update online list', __FILE__, __LINE__, $db->error());

	$luna_user['disp_topics'] = $luna_config['o_disp_topics_default'];
	$luna_user['disp_posts'] = $luna_config['o_disp_posts_default'];
	$luna_user['timezone'] = $luna_config['o_default_timezone'];
	$luna_user['dst'] = $luna_config['o_default_dst'];
	$luna_user['language'] = $luna_config['o_default_lang'];
	$luna_user['style'] = $luna_config['o_default_style'];
	$luna_user['is_guest'] = true;
	$luna_user['is_admmod'] = false;
}


//
// SHA1 HMAC with PHP 4 fallback
//
function forum_hmac($data, $key, $raw_output = false)
{
	if (function_exists('hash_hmac'))
		return hash_hmac('sha1', $data, $key, $raw_output);

	// If key size more than blocksize then we hash it once
	if (strlen($key) > 64)
		$key = pack('H*', sha1($key)); // we have to use raw output here to match the standard

	// Ensure we're padded to exactly one block boundary
	$key = str_pad($key, 64, chr(0x00));

	$hmac_opad = str_repeat(chr(0x5C), 64);
	$hmac_ipad = str_repeat(chr(0x36), 64);

	// Do inner and outer padding
	for ($i = 0;$i < 64;$i++) {
		$hmac_opad[$i] = $hmac_opad[$i] ^ $key[$i];
		$hmac_ipad[$i] = $hmac_ipad[$i] ^ $key[$i];
	}

	// Finally, calculate the HMAC
	$hash = sha1($hmac_opad.pack('H*', sha1($hmac_ipad.$data)));

	// If we want raw output then we need to pack the final result
	if ($raw_output)
		$hash = pack('H*', $hash);

	return $hash;
}


//
// Set a cookie, ModernBB style!
// Wrapper for forum_setcookie
//
function luna_setcookie($user_id, $password_hash, $expire)
{
	global $cookie_name, $cookie_seed;

	forum_setcookie($cookie_name, $user_id.'|'.forum_hmac($password_hash, $cookie_seed.'_password_hash').'|'.$expire.'|'.forum_hmac($user_id.'|'.$expire, $cookie_seed.'_cookie_hash'), $expire);
}


//
// Set a cookie, ModernBB style!
// Wrapper for forum_setcookie
//
function luna_cookiebarcookie()
{
	// In a year, we'll ask again
	$expire = time() + 31536000;

	forum_setcookie('LunaCookieBar', 1, $expire);
}


//
// Set a cookie, ModernBB style!
//
function forum_setcookie($name, $value, $expire)
{
	global $cookie_path, $cookie_domain, $cookie_secure, $luna_config;

	if ($expire - time() - $luna_config['o_timeout_visit'] < 1)
		$expire = 0;

	// Enable sending of a P3P header
	header('P3P: CP="CUR ADM"');

	if (version_compare(PHP_VERSION, '5.2.0', '>='))
		setcookie($name, $value, $expire, $cookie_path, $cookie_domain, $cookie_secure, true);
	else
		setcookie($name, $value, $expire, $cookie_path.'; HttpOnly', $cookie_domain, $cookie_secure);
}


//
// Check whether the connecting user is banned (and delete any expired bans while we're at it)
//
function check_bans()
{
	global $db, $luna_config, $lang, $luna_user, $luna_bans;

	// Admins and moderators aren't affected
	if ($luna_user['is_admmod'] || !$luna_bans)
		return;

	// Add a dot or a colon (depending on IPv4/IPv6) at the end of the IP address to prevent banned address
	// 192.168.0.5 from matching e.g. 192.168.0.50
	$user_ip = get_remote_address();
	$user_ip .= (strpos($user_ip, '.') !== false) ? '.' : ':';

	$bans_altered = false;
	$is_banned = false;

	foreach ($luna_bans as $cur_ban)
	{
		// Has this ban expired?
		if ($cur_ban['expire'] != '' && $cur_ban['expire'] <= time())
		{
			$db->query('DELETE FROM '.$db->prefix.'bans WHERE id='.$cur_ban['id']) or error('Unable to delete expired ban', __FILE__, __LINE__, $db->error());
			$bans_altered = true;
			continue;
		}

		if ($cur_ban['username'] != '' && utf8_strtolower($luna_user['username']) == utf8_strtolower($cur_ban['username']))
			$is_banned = true;

		if ($cur_ban['ip'] != '')
		{
			$cur_ban_ips = explode(' ', $cur_ban['ip']);

			$num_ips = count($cur_ban_ips);
			for ($i = 0; $i < $num_ips; ++$i)
			{
				// Add the proper ending to the ban
				if (strpos($user_ip, '.') !== false)
					$cur_ban_ips[$i] = $cur_ban_ips[$i].'.';
				else
					$cur_ban_ips[$i] = $cur_ban_ips[$i].':';

				if (substr($user_ip, 0, strlen($cur_ban_ips[$i])) == $cur_ban_ips[$i])
				{
					$is_banned = true;
					break;
				}
			}
		}

		if ($is_banned)
		{
			$db->query('DELETE FROM '.$db->prefix.'online WHERE ident=\''.$db->escape($luna_user['username']).'\'') or error('Unable to delete from online list', __FILE__, __LINE__, $db->error());
			message($lang['Ban message'].' '.(($cur_ban['expire'] != '') ? $lang['Ban message 2'].' '.strtolower(format_time($cur_ban['expire'], true)).'. ' : '').(($cur_ban['message'] != '') ? $lang['Ban message 3'].'<br /><br /><strong>'.luna_htmlspecialchars($cur_ban['message']).'</strong><br /><br />' : '<br /><br />').$lang['Ban message 4'].' <a href="mailto:'.luna_htmlspecialchars($luna_config['o_admin_email']).'">'.luna_htmlspecialchars($luna_config['o_admin_email']).'</a>.', true);
		}
	}

	// If we removed any expired bans during our run-through, we need to regenerate the bans cache
	if ($bans_altered)
	{
		if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
			require FORUM_ROOT.'include/cache.php';

		generate_bans_cache();
	}
}


//
// Check username
//
function check_username($username, $exclude_id = null)
{
	global $db, $luna_config, $errors, $lang, $luna_bans;

	// Include UTF-8 function
	require_once FORUM_ROOT.'include/utf8/strcasecmp.php';

	// Convert multiple whitespace characters into one (to prevent people from registering with indistinguishable usernames)
	$username = preg_replace('%\s+%s', ' ', $username);

	// Validate username
	if (luna_strlen($username) < 2)
		$errors[] = $lang['Username too short'];
	else if (luna_strlen($username) > 25) // This usually doesn't happen since the form element only accepts 25 characters
		$errors[] = $lang['Username too long'];
	else if (!strcasecmp($username, 'Guest') || !utf8_strcasecmp($username, $lang['Guest']))
		$errors[] = $lang['Username guest'];
	else if (preg_match('%[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}%', $username) || preg_match('%((([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}:[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){5}:([0-9A-Fa-f]{1,4}:)?[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){4}:([0-9A-Fa-f]{1,4}:){0,2}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){3}:([0-9A-Fa-f]{1,4}:){0,3}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){2}:([0-9A-Fa-f]{1,4}:){0,4}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(([0-9A-Fa-f]{1,4}:){0,5}:((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(::([0-9A-Fa-f]{1,4}:){0,5}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|([0-9A-Fa-f]{1,4}::([0-9A-Fa-f]{1,4}:){0,5}[0-9A-Fa-f]{1,4})|(::([0-9A-Fa-f]{1,4}:){0,6}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){1,7}:))%', $username))
		$errors[] = $lang['Username IP'];
	else if ((strpos($username, '[') !== false || strpos($username, ']') !== false) && strpos($username, '\'') !== false && strpos($username, '"') !== false)
		$errors[] = $lang['Username reserved chars'];
	else if (preg_match('%(?:\[/?(?:b|u|s|ins|del|em|i|h|colou?r|quote|code|img|url|email|list|\*|topic|post|forum|user)\]|\[(?:img|url|quote|list)=)%i', $username))
		$errors[] = $lang['Username BBCode'];

	// Check username for any censored words
	if ($luna_config['o_censoring'] == '1' && censor_words($username) != $username)
		$errors[] = $lang['Username censor'];

	// Check that the username (or a too similar username) is not already registered
	$query = (!is_null($exclude_id)) ? ' AND id!='.$exclude_id : '';

	$result = $db->query('SELECT username FROM '.$db->prefix.'users WHERE (UPPER(username)=UPPER(\''.$db->escape($username).'\') OR UPPER(username)=UPPER(\''.$db->escape(ucp_preg_replace('%[^\p{L}\p{N}]%u', '', $username)).'\')) AND id>1'.$query) or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());

	if ($db->num_rows($result))
	{
		$busy = $db->result($result);
		$errors[] = $lang['Username dupe 1'].' '.luna_htmlspecialchars($busy).'. '.$lang['Username dupe 2'];
	}

	// Check username for any banned usernames
	foreach ($luna_bans as $cur_ban)
	{
		if ($cur_ban['username'] != '' && utf8_strtolower($username) == utf8_strtolower($cur_ban['username']))
		{
			$errors[] = $lang['Banned username'];
			break;
		}
	}
}


//
// Update "Users online"
//
function update_users_online()
{
	global $db, $luna_config;

	$now = time();

	// Fetch all online list entries that are older than "o_timeout_online"
	$result = $db->query('SELECT user_id, ident, logged, idle FROM '.$db->prefix.'online WHERE logged<'.($now-$luna_config['o_timeout_online'])) or error('Unable to fetch old entries from online list', __FILE__, __LINE__, $db->error());
	while ($cur_user = $db->fetch_assoc($result))
	{
		// If the entry is a guest, delete it
		if ($cur_user['user_id'] == '1')
			$db->query('DELETE FROM '.$db->prefix.'online WHERE ident=\''.$db->escape($cur_user['ident']).'\'') or error('Unable to delete from online list', __FILE__, __LINE__, $db->error());
		else
		{
			// If the entry is older than "o_timeout_visit", update last_visit for the user in question, then delete him/her from the online list
			if ($cur_user['logged'] < ($now-$luna_config['o_timeout_visit']))
			{
				$db->query('UPDATE '.$db->prefix.'users SET last_visit='.$cur_user['logged'].' WHERE id='.$cur_user['user_id']) or error('Unable to update user visit data', __FILE__, __LINE__, $db->error());
				$db->query('DELETE FROM '.$db->prefix.'online WHERE user_id='.$cur_user['user_id']) or error('Unable to delete from online list', __FILE__, __LINE__, $db->error());
			}
			else if ($cur_user['idle'] == '0')
				$db->query('UPDATE '.$db->prefix.'online SET idle=1 WHERE user_id='.$cur_user['user_id']) or error('Unable to insert into online list', __FILE__, __LINE__, $db->error());
		}
	}
}


//
// Display the profile navigation menu
//
function generate_profile_menu($page = '')
{
	global $lang, $luna_config, $luna_user, $id;

?>
<div class="list-group">
    <a class="<?php if ($page == 'view') echo 'active'; ?> list-group-item" href="profile.php?section=view&amp;id=<?php echo $id ?>"><?php echo $lang['Profile'] ?></a>
    <?php if ($luna_user['id'] == $id && !$luna_user['is_guest'] || ($luna_user['g_id'] == FORUM_ADMIN || ($luna_user['g_moderator'] == '1' && $luna_user['g_mod_ban_users'] == '1'))): ?>
        <a class="<?php if ($page == 'personality') echo 'active'; ?> list-group-item" href="profile.php?section=personality&amp;id=<?php echo $id ?>"><?php echo $lang['Section personality'] ?></a>
        <a class="<?php if ($page == 'settings') echo 'active'; ?> list-group-item" href="profile.php?section=settings&amp;id=<?php echo $id ?>"><?php echo $lang['Settings'] ?></a>
        <?php if ($luna_user['g_id'] == FORUM_ADMIN || ($luna_user['g_moderator'] == '1' && $luna_user['g_mod_ban_users'] == '1')): ?>
            <a class="<?php if ($page == 'admin') echo 'active'; ?> list-group-item" href="profile.php?section=admin&amp;id=<?php echo $id ?>"><?php echo $lang['Section admin'] ?></a>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php

}


//
// Outputs markup to display a user's avatar
//
function generate_avatar_markup($user_id)
{
	global $luna_config;

	$filetypes = array('jpg', 'gif', 'png');
	$avatar_markup = '';

	foreach ($filetypes as $cur_type)
	{
		$path = $luna_config['o_avatars_dir'].'/'.$user_id.'.'.$cur_type;

		if (file_exists(FORUM_ROOT.$path) && $img_size = getimagesize(FORUM_ROOT.$path))
		{
			$avatar_markup = '<img class="avatar" src="'.luna_htmlspecialchars(get_base_url(true).'/'.$path.'?m='.filemtime(FORUM_ROOT.$path)).'" '.$img_size[3].' alt="" />';
			break;
		}
		else
		{
			$avatar_markup = '<img class="avatar" src="'.luna_htmlspecialchars(get_base_url(true)).'/img/avatars/placeholder.png" alt="" />';
		}
	}

	return $avatar_markup;
}


//
// Outputs info if avatar is available
//
function check_avatar($user_id)
{
	global $luna_config;

	$filetypes = array('jpg', 'gif', 'png');
	$avatar_set = '';

	foreach ($filetypes as $cur_type)
	{
		$path = $luna_config['o_avatars_dir'].'/'.$user_id.'.'.$cur_type;

		if (file_exists(FORUM_ROOT.$path) && $img_size = getimagesize(FORUM_ROOT.$path))
		{
			$avatar_set = true;
			break;
		}
		else
		{
			$avatar_set = false;
		}
	}

	return $avatar_set;
}


//
// Generate browser's title
//
function generate_page_title($page_title, $p = null)
{
	global $luna_config, $lang;

	if (!is_array($page_title))
		$page_title = array($page_title);

	$page_title = array_reverse($page_title);

	if ($p > 1)
		$page_title[0] .= ' ('.sprintf($lang['Page'], forum_number_format($p)).')';

	$crumbs = implode($lang['Title separator'], $page_title);

	return $crumbs;
}


//
// Save array of tracked topics in cookie
//
function set_tracked_topics($tracked_topics)
{
	global $cookie_name, $cookie_path, $cookie_domain, $cookie_secure, $luna_config;

	$cookie_data = '';
	if (!empty($tracked_topics))
	{
		// Sort the arrays (latest read first)
		arsort($tracked_topics['topics'], SORT_NUMERIC);
		arsort($tracked_topics['forums'], SORT_NUMERIC);

		// Homebrew serialization (to avoid having to run unserialize() on cookie data)
		foreach ($tracked_topics['topics'] as $id => $timestamp)
			$cookie_data .= 't'.$id.'='.$timestamp.';';
		foreach ($tracked_topics['forums'] as $id => $timestamp)
			$cookie_data .= 'f'.$id.'='.$timestamp.';';

		// Enforce a byte size limit (4096 minus some space for the cookie name - defaults to 4048)
		if (strlen($cookie_data) > FORUM_MAX_COOKIE_SIZE)
		{
			$cookie_data = substr($cookie_data, 0, FORUM_MAX_COOKIE_SIZE);
			$cookie_data = substr($cookie_data, 0, strrpos($cookie_data, ';')).';';
		}
	}

	forum_setcookie($cookie_name.'_track', $cookie_data, time() + $luna_config['o_timeout_visit']);
	$_COOKIE[$cookie_name.'_track'] = $cookie_data; // Set it directly in $_COOKIE as well
}


//
// Extract array of tracked topics from cookie
//
function get_tracked_topics()
{
	global $cookie_name;

	$cookie_data = isset($_COOKIE[$cookie_name.'_track']) ? $_COOKIE[$cookie_name.'_track'] : false;
	if (!$cookie_data)
		return array('topics' => array(), 'forums' => array());

	if (strlen($cookie_data) > FORUM_MAX_COOKIE_SIZE)
		return array('topics' => array(), 'forums' => array());

	// Unserialize data from cookie
	$tracked_topics = array('topics' => array(), 'forums' => array());
	$temp = explode(';', $cookie_data);
	foreach ($temp as $t)
	{
		$type = substr($t, 0, 1) == 'f' ? 'forums' : 'topics';
		$id = intval(substr($t, 1));
		$timestamp = intval(substr($t, strpos($t, '=') + 1));
		if ($id > 0 && $timestamp > 0)
			$tracked_topics[$type][$id] = $timestamp;
	}

	return $tracked_topics;
}


//
// Update posts, topics, last_post, last_post_id and last_poster for a forum
//
function update_forum($forum_id)
{
	global $db;

	$result = $db->query('SELECT COUNT(id), SUM(num_replies) FROM '.$db->prefix.'topics WHERE forum_id='.$forum_id) or error('Unable to fetch forum topic count', __FILE__, __LINE__, $db->error());
	list($num_topics, $num_posts) = $db->fetch_row($result);

	$num_posts = $num_posts + $num_topics; // $num_posts is only the sum of all replies (we have to add the topic posts)

	$result = $db->query('SELECT last_post, last_post_id, last_poster, last_poster_id, subject FROM '.$db->prefix.'topics WHERE forum_id='.$forum_id.' AND moved_to IS NULL ORDER BY last_post DESC LIMIT 1') or error('Unable to fetch last_post/last_post_id/last_poster', __FILE__, __LINE__, $db->error());
	if ($db->num_rows($result)) // There are topics in the forum
	{
		list($last_post, $last_post_id, $last_poster, $last_poster_id, $last_topic) = $db->fetch_row($result);

		$db->query('UPDATE '.$db->prefix.'forums SET num_topics='.$num_topics.', num_posts='.$num_posts.', last_post='.$last_post.', last_post_id='.$last_post_id.', last_poster=\''.$db->escape($last_poster).'\', last_poster_id=\''.$db->escape($last_poster_id).'\', last_topic=\''.$db->escape($last_topic).'\' WHERE id='.$forum_id) or error('Unable to update last_post/last_post_id/last_poster', __FILE__, __LINE__, $db->error());
	}
	else // There are no topics
		$db->query('UPDATE '.$db->prefix.'forums SET num_topics='.$num_topics.', num_posts='.$num_posts.', last_post=NULL, last_post_id=NULL, last_poster=NULL, last_poster_id=NULL, last_topic=NULL WHERE id='.$forum_id) or error('Unable to update last_post/last_post_id/last_poster/last_topic', __FILE__, __LINE__, $db->error());
}


//
// Deletes any avatars owned by the specified user ID
//
function delete_avatar($user_id)
{
	global $luna_config;

	$filetypes = array('jpg', 'gif', 'png');

	// Delete user avatar
	foreach ($filetypes as $cur_type)
	{
		if (file_exists(FORUM_ROOT.$luna_config['o_avatars_dir'].'/'.$user_id.'.'.$cur_type))
			@unlink(FORUM_ROOT.$luna_config['o_avatars_dir'].'/'.$user_id.'.'.$cur_type);
	}
}


//
// Delete a topic and all of its posts
//
function delete_topic($topic_id)
{
	global $db;

	// Delete the topic and any redirect topics
	$db->query('DELETE FROM '.$db->prefix.'topics WHERE id='.$topic_id.' OR moved_to='.$topic_id) or error('Unable to delete topic', __FILE__, __LINE__, $db->error());

	// Create a list of the post IDs in this topic
	$post_ids = '';
	$result = $db->query('SELECT id FROM '.$db->prefix.'posts WHERE topic_id='.$topic_id) or error('Unable to fetch posts', __FILE__, __LINE__, $db->error());
	while ($row = $db->fetch_row($result))
		$post_ids .= ($post_ids != '') ? ','.$row[0] : $row[0];

	// Make sure we have a list of post IDs
	if ($post_ids != '')
	{
		strip_search_index($post_ids);

		// Delete posts in topic
		$db->query('DELETE FROM '.$db->prefix.'posts WHERE topic_id='.$topic_id) or error('Unable to delete posts', __FILE__, __LINE__, $db->error());
	}

	// Delete any subscriptions for this topic
	$db->query('DELETE FROM '.$db->prefix.'topic_subscriptions WHERE topic_id='.$topic_id) or error('Unable to delete subscriptions', __FILE__, __LINE__, $db->error());
}


//
// Delete a single post
//
function delete_post($post_id, $topic_id)
{
	global $db;

	$result = $db->query('SELECT id, poster, posted FROM '.$db->prefix.'posts WHERE topic_id='.$topic_id.' ORDER BY id DESC LIMIT 2') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
	list($last_id, ,) = $db->fetch_row($result);
	list($second_last_id, $second_poster, $second_posted) = $db->fetch_row($result);

	// Delete the post
	$db->query('DELETE FROM '.$db->prefix.'posts WHERE id='.$post_id) or error('Unable to delete post', __FILE__, __LINE__, $db->error());

	strip_search_index($post_id);

	// Count number of replies in the topic
	$result = $db->query('SELECT COUNT(id) FROM '.$db->prefix.'posts WHERE topic_id='.$topic_id) or error('Unable to fetch post count for topic', __FILE__, __LINE__, $db->error());
	$num_replies = $db->result($result, 0) - 1;

	// If the message we deleted is the most recent in the topic (at the end of the topic)
	if ($last_id == $post_id)
	{
		// If there is a $second_last_id there is more than 1 reply to the topic
		if (!empty($second_last_id))
			$db->query('UPDATE '.$db->prefix.'topics SET last_post='.$second_posted.', last_post_id='.$second_last_id.', last_poster=\''.$db->escape($second_poster).'\', num_replies='.$num_replies.' WHERE id='.$topic_id) or error('Unable to update topic', __FILE__, __LINE__, $db->error());
		else
			// We deleted the only reply, so now last_post/last_post_id/last_poster is posted/id/poster from the topic itself
			$db->query('UPDATE '.$db->prefix.'topics SET last_post=posted, last_post_id=id, last_poster=poster, num_replies='.$num_replies.' WHERE id='.$topic_id) or error('Unable to update topic', __FILE__, __LINE__, $db->error());
	}
	else
		// Otherwise we just decrement the reply counter
		$db->query('UPDATE '.$db->prefix.'topics SET num_replies='.$num_replies.' WHERE id='.$topic_id) or error('Unable to update topic', __FILE__, __LINE__, $db->error());
}


//
// Delete every .php file in the forum's cache directory
//
function forum_clear_cache()
{
	$d = dir(FORUM_CACHE_DIR);
	while (($entry = $d->read()) !== false)
	{
		if (substr($entry, -4) == '.php')
			@unlink(FORUM_CACHE_DIR.$entry);
	}
	$d->close();
}


//
// Replace censored words in $text
//
function censor_words($text)
{
	global $db;
	static $search_for, $replace_with;

	// If not already built in a previous call, build an array of censor words and their replacement text
	if (!isset($search_for))
	{
		if (file_exists(FORUM_CACHE_DIR.'cache_censoring.php'))
			include FORUM_CACHE_DIR.'cache_censoring.php';

		if (!defined('FORUM_CENSOR_LOADED'))
		{
			if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
				require FORUM_ROOT.'include/cache.php';

			generate_censoring_cache();
			require FORUM_CACHE_DIR.'cache_censoring.php';
		}
	}

	if (!empty($search_for))
		$text = substr(ucp_preg_replace($search_for, $replace_with, ' '.$text.' '), 1, -1);

	return $text;
}


//
// Determines the correct title for $user
// $user must contain the elements 'username', 'title', 'posts', 'g_id' and 'g_user_title'
//
function get_title($user)
{
	global $db, $luna_config, $luna_bans, $lang;
	static $ban_list, $luna_ranks;

	// If not already built in a previous call, build an array of lowercase banned usernames
	if (empty($ban_list))
	{
		$ban_list = array();

		foreach ($luna_bans as $cur_ban)
			$ban_list[] = utf8_strtolower($cur_ban['username']);
	}

	// If not already loaded in a previous call, load the cached ranks
	if ($luna_config['o_ranks'] == '1' && !defined('FORUM_RANKS_LOADED'))
	{
		if (file_exists(FORUM_CACHE_DIR.'cache_ranks.php'))
			include FORUM_CACHE_DIR.'cache_ranks.php';

		if (!defined('FORUM_RANKS_LOADED'))
		{
			if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
				require FORUM_ROOT.'include/cache.php';

			generate_ranks_cache();
			require FORUM_CACHE_DIR.'cache_ranks.php';
		}
	}

	// If the user has a custom title
	if ($user['title'] != '')
		$user_title = luna_htmlspecialchars($user['title']);
	// If the user is banned
	else if (in_array(utf8_strtolower($user['username']), $ban_list))
		$user_title = $lang['Banned'];
	// If the user group has a default user title
	else if ($user['g_user_title'] != '')
		$user_title = luna_htmlspecialchars($user['g_user_title']);
	// If the user is a guest
	else if ($user['g_id'] == FORUM_GUEST)
		$user_title = $lang['Guest'];
	else
	{
		// Are there any ranks?
		if ($luna_config['o_ranks'] == '1' && !empty($luna_ranks))
		{
			foreach ($luna_ranks as $cur_rank)
			{
				if ($user['num_posts'] >= $cur_rank['min_posts'])
					$user_title = luna_htmlspecialchars($cur_rank['rank']);
			}
		}

		// If the user didn't "reach" any rank (or if ranks are disabled), we assign the default
		if (!isset($user_title))
			$user_title = $lang['Member'];
	}

	return $user_title;
}


//
// Generate a string with numbered links (for multipage scripts)
//
function paginate($num_pages, $cur_page, $link)
{
	global $lang;

	$pages = array();
	$link_to_all = false;

	// If $cur_page == -1, we link to all pages (used in viewforum.php)
	if ($cur_page == -1)
	{
		$cur_page = 1;
		$link_to_all = true;
	}

	if ($num_pages <= 1)
		$pages = array('');
	else
	{
		// Add a previous page link
		if ($num_pages > 1 && $cur_page > 1)
			$pages[] = '<li><a rel="prev" '.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p='.($cur_page - 1).'">&laquo;</a></li>';
		else
			$pages[] = '<li class="disabled"><span>&laquo;</span></li>';

		if ($cur_page > 3)
		{
			$pages[] = '<li><a'.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p=1">1</a></li>';

			if ($cur_page > 5)
				$pages[] = '<li class="disabled"><span class="spacer">'.$lang['Spacer'].'</span></li>';
		}

		// Don't ask me how the following works. It just does, OK? :-)
		for ($current = ($cur_page == 5) ? $cur_page - 3 : $cur_page - 2, $stop = ($cur_page + 4 == $num_pages) ? $cur_page + 4 : $cur_page + 3; $current < $stop; ++$current)
		{
			if ($current < 1 || $current > $num_pages)
				continue;
			else if ($current != $cur_page || $link_to_all)
				$pages[] = '<li><a'.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p='.$current.'">'.forum_number_format($current).'</a></li>';
			else
				$pages[] = '<li class="active"><span>'.forum_number_format($current).' <span class="sr-only">(current)</span></span></li>';
		}

		if ($cur_page <= ($num_pages-3))
		{
			if ($cur_page != ($num_pages-3) && $cur_page != ($num_pages-4))
				$pages[] = '<li class="disabled"><span class="spacer">'.$lang['Spacer'].'</span></li>';

			$pages[] = '<li><a'.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p='.$num_pages.'">'.forum_number_format($num_pages).'</a></li>';
		}

		// Add a next page link
		if ($num_pages > 1 && !$link_to_all && $cur_page < $num_pages)
			$pages[] = '<li><a rel="next" '.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p='.($cur_page +1).'">&raquo;</a></li>';
		else
			$pages[] = '<li class="disabled"><span>&raquo;</span></li>';
	}

	return implode(' ', $pages);
}


//
// The same script as above, but simplified for inline navigation
//
function simple_paginate($num_pages, $cur_page, $link)
{
	global $lang;

	$pages = array();
	$link_to_all = false;

	// If $cur_page == -1, we link to all pages (used in viewforum.php)
	if ($cur_page == -1)
	{
		$cur_page = 1;
		$link_to_all = true;
	}

	if ($num_pages >= 1)
	{
		if ($cur_page > 3)
		{
			$pages[] = '<a'.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p=1">1</a>';

			if ($cur_page > 5)
				$pages[] = '<span class="spacer">'.$lang['Spacer'].'</span>';
		}

		// Don't ask me how the following works. It just does, OK? :-)
		for ($current = ($cur_page == 5) ? $cur_page - 3 : $cur_page - 2, $stop = ($cur_page + 4 == $num_pages) ? $cur_page + 4 : $cur_page + 3; $current < $stop; ++$current)
		{
			if ($current < 1 || $current > $num_pages)
				continue;
			else if ($current != $cur_page || $link_to_all)
				$pages[] = '<a'.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p='.$current.'">'.forum_number_format($current).'</a>';
			else
				$pages[] = '<a>'.forum_number_format($current).'</a>';
		}

		if ($cur_page <= ($num_pages-3))
		{
			if ($cur_page != ($num_pages-3) && $cur_page != ($num_pages-4))
				$pages[] = '<span class="spacer">'.$lang['Spacer'].'</span>';

			$pages[] = '<a'.(empty($pages) ? ' class="item1"' : '').' href="'.$link.'&amp;p='.$num_pages.'">'.forum_number_format($num_pages).'</a>';
		}
	}

	return implode(' &middot; ', $pages);
}


//
// Display a message in the frontend
//
function message($message, $no_back_link = false, $http_status = null)
{
	global $db, $lang, $luna_config, $luna_start, $tpl_main, $luna_user;

	// Did we receive a custom header?
	if(!is_null($http_status)) {
		header('HTTP/1.1 ' . $http_status);
	}

	if (!defined('FORUM_HEADER'))
	{
		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Info']);
		define('FORUM_ACTIVE_PAGE', 'index');
		require FORUM_ROOT.'header.php';
	}

?>

<div id="posterrorall">
	<h3 class="form-heading form-errors"><?php echo $lang['Info'] ?></h3>
    <div class="form-content">
	<p><?php echo $message ?></p>
	<?php if (!$no_back_link): ?>
		<p><a href="javascript: history.go(-1)"><?php echo $lang['Go back'] ?></a></p>
	<?php endif; ?>
    </div>
</div>
<?php
	require FORUM_ROOT.'footer.php';
}


//
// Display a message in the Backstage
//
function message_backstage($message, $no_back_link = false, $http_status = null)
{
	global $db, $lang, $luna_config, $luna_start, $luna_user;

	// Did we receive a custom header?
	if(!is_null($http_status)) {
		header('HTTP/1.1 ' . $http_status);
	}

	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Admin'], $lang['Info']);
	define('FORUM_ACTIVE_PAGE', 'admin');
	require FORUM_ROOT.'backstage/header.php';
	generate_admin_menu('');

?>
<h2><?php echo $lang['Info'] ?></h2>
<div class="panel panel-default">
	<div class="panel-heading">
		<h3 class="panel-title"><?php echo $lang['Info'] ?></h3>
	</div>
	<div class="panel-body">
		<p><?php echo $message ?></p>
	</div>
	<div class="panel-footer">
		<?php if (!$no_back_link): ?>
			<a href="javascript: history.go(-1)" href="btn btn-link"><?php echo $lang['Go back'] ?></a>
		<?php endif; ?>
	</div>
</div>
<?php
	require FORUM_ROOT .'backstage/footer.php';
}


//
// Format a time string according to $time_format and time zones
//
function format_time($timestamp, $date_only = false, $date_format = null, $time_format = null, $time_only = false, $no_text = false)
{
	global $luna_config, $lang, $luna_user, $forum_date_formats, $forum_time_formats;

	if ($timestamp == '')
		return $lang['Never'];

	$diff = ($luna_user['timezone'] + $luna_user['dst']) * 3600;
	$timestamp += $diff;
	$now = time();

	if(is_null($date_format))
		$date_format = $forum_date_formats[$luna_user['date_format']];

	if(is_null($time_format))
		$time_format = $forum_time_formats[$luna_user['time_format']];

	$date = gmdate($date_format, $timestamp);
	$today = gmdate($date_format, $now+$diff);
	$yesterday = gmdate($date_format, $now+$diff-86400);

	if(!$no_text)
	{
		if ($date == $today)
			$date = $lang['Today'];
		else if ($date == $yesterday)
			$date = $lang['Yesterday'];
	}

	if ($date_only)
		return $date;
	else if ($time_only)
		return gmdate($time_format, $timestamp);
	else
		return $date.' '.gmdate($time_format, $timestamp);
}


//
// A wrapper for PHP's number_format function
//
function forum_number_format($number, $decimals = 0)
{
	global $lang;

	return is_numeric($number) ? number_format($number, $decimals, $lang['lang_decimal_point'], $lang['lang_thousands_sep']) : $number;
}


//
// Generate a random key of length $len
//
function random_key($len, $readable = false, $hash = false)
{
	$key = secure_random_bytes($len);

	if ($hash)
		return substr(bin2hex($key), 0, $len);
	else if ($readable)
	{
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

		$result = '';
		for ($i = 0; $i < $len; ++$i)
			$result .= substr($chars, (ord($key[$i]) % strlen($chars)), 1);

		return $result;
	}

	return $key;
}


//
// Make sure that HTTP_REFERER matches base_url/script
//
function confirm_referrer($scripts, $error_msg = false)
{
	global $luna_config, $lang;

	if (!is_array($scripts))
		$scripts = array($scripts);

	// There is no referrer
	if (empty($_SERVER['HTTP_REFERER']))
		message($error_msg ? $error_msg : $lang['Bad referrer']);

	$referrer = parse_url(strtolower($_SERVER['HTTP_REFERER']));
	// Remove www subdomain if it exists
	if (strpos($referrer['host'], 'www.') === 0)
		$referrer['host'] = substr($referrer['host'], 4);

	$valid_paths = array();
	foreach ($scripts as $script)
	{
		$valid = parse_url(strtolower(get_base_url().'/'.$script));
		// Remove www subdomain if it exists
		if (strpos($valid['host'], 'www.') === 0)
			$valid['host'] = substr($valid['host'], 4);

		$valid_host = $valid['host'];
		$valid_paths[] = $valid['path'];
	}

	// Check the host and path match. Ignore the scheme, port, etc.
	if ($referrer['host'] != $valid_host || !in_array($referrer['path'], $valid_paths))
		message($error_msg ? $error_msg : $lang['Bad referrer']);
}


//
// Generate a random password of length $len
// Compatibility wrapper for random_key
//
function random_pass($len)
{
	return random_key($len, true);
}


//
// Compute a hash of $str with SHA1
//
function luna_hash($str)
{
	return sha1($str);
}


//
// Compute a hash of $str with SHA512
//
function luna_sha2($str, $salt)
{
    return hash("sha512", $salt . hash("sha512", $str));
}


//
// Try to determine the correct remote IP-address
//
function get_remote_address()
{
	$remote_addr = $_SERVER['REMOTE_ADDR'];

	// If we are behind a reverse proxy try to find the real users IP
	if (defined('FORUM_BEHIND_REVERSE_PROXY'))
	{
		if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
		{
			// The general format of the field is:
			// X-Forwarded-For: client1, proxy1, proxy2
			// where the value is a comma+space separated list of IP addresses, the left-most being the farthest downstream client,
			// and each successive proxy that passed the request adding the IP address where it received the request from.
			$forwarded_for = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$forwarded_for = trim($forwarded_for[0]);

			if (@preg_match('%^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$%', $forwarded_for) || @preg_match('%^((([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}:[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){5}:([0-9A-Fa-f]{1,4}:)?[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){4}:([0-9A-Fa-f]{1,4}:){0,2}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){3}:([0-9A-Fa-f]{1,4}:){0,3}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){2}:([0-9A-Fa-f]{1,4}:){0,4}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(([0-9A-Fa-f]{1,4}:){0,5}:((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(::([0-9A-Fa-f]{1,4}:){0,5}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|([0-9A-Fa-f]{1,4}::([0-9A-Fa-f]{1,4}:){0,5}[0-9A-Fa-f]{1,4})|(::([0-9A-Fa-f]{1,4}:){0,6}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){1,7}:))$%', $forwarded_for))
				$remote_addr = $forwarded_for;
		}
	}

	return $remote_addr;
}


//
// Calls htmlspecialchars with a few options already set
//
function luna_htmlspecialchars($str)
{
	return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}


//
// Calls htmlspecialchars_decode with a few options already set
//
function luna_htmlspecialchars_decode($str)
{
	if (function_exists('htmlspecialchars_decode'))
		return htmlspecialchars_decode($str, ENT_QUOTES);

	static $translations;
	if (!isset($translations))
	{
		$translations = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES);
		$translations['&#039;'] = '\''; // get_html_translation_table doesn't include &#039; which is what htmlspecialchars translates ' to, but apparently that is okay?! http://bugs.php.net/bug.php?id=25927
		$translations = array_flip($translations);
	}

	return strtr($str, $translations);
}


//
// A wrapper for utf8_strlen for compatibility
//
function luna_strlen($str)
{
	return utf8_strlen($str);
}


//
// Convert \r\n and \r to \n
//
function luna_linebreaks($str)
{
	return str_replace("\r", "\n", str_replace("\r\n", "\n", $str));
}


//
// A wrapper for utf8_trim for compatibility
//
function luna_trim($str, $charlist = false)
{
	return is_string($str) ? utf8_trim($str, $charlist) : '';
}

//
// Checks if a string is in all uppercase
//
function is_all_uppercase($string)
{
	return utf8_strtoupper($string) == $string && utf8_strtolower($string) != $string;
}


//
// Inserts $element into $input at $offset
// $offset can be either a numerical offset to insert at (eg: 0 inserts at the beginning of the array)
// or a string, which is the key that the new element should be inserted before
// $key is optional: it's used when inserting a new key/value pair into an associative array
//
function array_insert(&$input, $offset, $element, $key = null)
{
	if (is_null($key))
		$key = $offset;

	// Determine the proper offset if we're using a string
	if (!is_int($offset))
		$offset = array_search($offset, array_keys($input), true);

	// Out of bounds checks
	if ($offset > count($input))
		$offset = count($input);
	else if ($offset < 0)
		$offset = 0;

	$input = array_merge(array_slice($input, 0, $offset), array($key => $element), array_slice($input, $offset));
}


//
// Display a message when board is in maintenance mode
//
function maintenance_message()
{
	global $db, $luna_config, $lang, $luna_user;

	// Send no-cache headers
	header('Expires: Thu, 21 Jul 1977 07:30:00 GMT'); // When yours truly first set eyes on this world! :)
	header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache'); // For HTTP/1.0 compatibility

	// Send the Content-type header in case the web server is setup to send something else
	header('Content-type: text/html; charset=utf-8');

	// Deal with newlines, tabs and multiple spaces
	$pattern = array("\t", '  ', '  ');
	$replace = array('&#160; &#160; ', '&#160; ', ' &#160;');
	$message = str_replace($pattern, $replace, $luna_config['o_maintenance_message']);

	if (file_exists(FORUM_ROOT.'style/'.$luna_user['style'].'/maintenance.tpl'))
	{
		$tpl_file = FORUM_ROOT.'style/'.$luna_user['style'].'/maintenance.tpl';
		$tpl_inc_dir = FORUM_ROOT.'style/'.$luna_user['style'].'/';
	}
	else
	{
		$tpl_file = FORUM_ROOT.'style/Core/templates/maintenance.tpl';
		$tpl_inc_dir = FORUM_ROOT.'style/User/';
	}

	$tpl_maint = file_get_contents($tpl_file);

	// START SUBST - <luna_include "*">
	preg_match_all('%<luna_include "([^/\\\\]*?)\.(php[45]?|inc|html?|txt)">%i', $tpl_maint, $luna_includes, PREG_SET_ORDER);

	foreach ($luna_includes as $cur_include)
	{
		ob_start();

		// Allow for overriding user includes, too.
		if (file_exists($tpl_inc_dir.$cur_include[1].'.'.$cur_include[2]))
			require $tpl_inc_dir.$cur_include[1].'.'.$cur_include[2];
		else if (file_exists(FORUM_ROOT.'include/user/'.$cur_include[1].'.'.$cur_include[2]))
			require FORUM_ROOT.'include/user/'.$cur_include[1].'.'.$cur_include[2];
		else
			error(sprintf($lang['Pun include error'], htmlspecialchars($cur_include[0]), basename($tpl_file)));

		$tpl_temp = ob_get_contents();
		$tpl_maint = str_replace($cur_include[0], $tpl_temp, $tpl_maint);
		ob_end_clean();
	}
	// END SUBST - <luna_include "*">


	// START SUBST - <luna_language>
	$tpl_maint = str_replace('<luna_language>', $lang['lang_identifier'], $tpl_maint);
	// END SUBST - <luna_language>


	// START SUBST - <luna_content_direction>
	$tpl_maint = str_replace('<luna_content_direction>', $lang['lang_direction'], $tpl_maint);
	// END SUBST - <luna_content_direction>


	// START SUBST - <luna_head>
	ob_start();

	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Maintenance']);

?>
<title><?php echo generate_page_title($page_title) ?></title>
<link rel="stylesheet" type="text/css" href="style/<?php echo $luna_user['style'].'/style.css' ?>" />
<?php

	$tpl_temp = trim(ob_get_contents());
	$tpl_maint = str_replace('<luna_head>', $tpl_temp, $tpl_maint);
	ob_end_clean();
	// END SUBST - <luna_head>


	// START SUBST - <luna_maint_main>
	ob_start();

?>
<div class="container">
    <div class="form">
        <h1 class="form-heading"><?php echo $lang['Maintenance'] ?></h1>
        <div class="form-content">
            <p><?php echo $message ?></p>
        </div>
    </div>
</div>
<?php

	$tpl_temp = trim(ob_get_contents());
	$tpl_maint = str_replace('<luna_maint_main>', $tpl_temp, $tpl_maint);
	ob_end_clean();
	// END SUBST - <luna_maint_main>


	// End the transaction
	$db->end_transaction();


	// Close the db connection (and free up any result data)
	$db->close();

	exit($tpl_maint);
}


//
// Display $message and redirect user to $destination_url
//
function redirect($destination_url)
{
	global $db;

	// Prefix with base_url (unless there's already a valid URI)
	if (strpos($destination_url, 'http://') !== 0 && strpos($destination_url, 'https://') !== 0 && strpos($destination_url, '/') !== 0)
		$destination_url = get_base_url(true).'/'.$destination_url;

	// Do a little spring cleaning
	$destination_url = preg_replace('%([\r\n])|(\%0[ad])|(;\s*data\s*:)%i', '', $destination_url);

	$db->end_transaction();
	$db->close();

	header('Location: '.str_replace('&amp;', '&', $destination_url));
	exit;
}


//
// Display a simple error message
//
function error($message, $file = null, $line = null, $db_error = false)
{
	global $luna_config, $lang;

	// Set some default settings if the script failed before $luna_config could be populated
	if (empty($luna_config))
	{
		$luna_config = array(
			'o_board_title'	=> 'ModernBB',
			'o_gzip'		=> '0'
		);
	}

	// Set some default translations if the script failed before $lang could be populated
	if (empty($lang))
	{
		$lang = array(
			'Title separator'	=> ' / ',
			'Page'				=> 'Page %s'
		);
	}

	// Empty all output buffers and stop buffering
	while (@ob_end_clean());

	// "Restart" output buffering if we are using ob_gzhandler (since the gzip header is already sent)
	if ($luna_config['o_gzip'] && extension_loaded('zlib'))
		ob_start('ob_gzhandler');

	// Send no-cache headers
	header('Expires: Thu, 21 Jul 1977 07:30:00 GMT'); // When yours truly first set eyes on this world! :)
	header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache'); // For HTTP/1.0 compatibility

	// Send the Content-type header in case the web server is setup to send something else
	header('Content-type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<?php $page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), 'Error') ?>
        <title><?php echo generate_page_title($page_title) ?></title>
		<style type="text/css">
			body { margin: 10% 20% auto 20%; font: 14px "Segoe UI Light", "Segoe UI", Verdana, Arial, Helvetica, sans-serif; letter-spacing: 1px; }
			h2 { margin: 0; color: #00a5f5; font-size: 26px; padding: 0 4px; font-weight: 100; }
			#errorbox div { padding: 0 5px; }
        </style>
    </head>
    <body>
        <div id="errorbox">
            <h2>An error was encountered</h2>
            <div>
<?php

	if (defined('FORUM_DEBUG') && !is_null($file) && !is_null($line))
	{
		echo "\t\t".'<strong>File:</strong> '.$file.'<br />'."\n\t\t".'<strong>Line:</strong> '.$line.'<br /><br />'."\n\t\t".'<strong>ModernBB reported</strong>: '.$message."\n";

		if ($db_error)
		{
			echo "\t\t".'<br /><br /><strong>Database reported:</strong> '.luna_htmlspecialchars($db_error['error_msg']).(($db_error['error_no']) ? ' (Errno: '.$db_error['error_no'].')' : '')."\n";

			if ($db_error['error_sql'] != '')
				echo "\t\t".'<br /><br /><strong>Failed query:</strong> '.luna_htmlspecialchars($db_error['error_sql'])."\n";
		}
	}
	else
		echo "\t\t".'Error: <strong>'.$message.'.</strong>'."\n";

?>
            </div>
        </div>
    </body>
</html>
<?php

	// If a database connection was established (before this error) we close it
	if ($db_error)
		$GLOBALS['db']->close();

	exit;
}


//
// Unset any variables instantiated as a result of register_globals being enabled
//
function forum_unregister_globals()
{
	$register_globals = ini_get('register_globals');
	if ($register_globals === '' || $register_globals === '0' || strtolower($register_globals) === 'off')
		return;

	// Prevent script.php?GLOBALS[foo]=bar
	if (isset($_REQUEST['GLOBALS']) || isset($_FILES['GLOBALS']))
		exit('I\'ll have a steak sandwich and... a steak sandwich.');

	// Variables that shouldn't be unset
	$no_unset = array('GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES');

	// Remove elements in $GLOBALS that are present in any of the superglobals
	$input = array_merge($_GET, $_POST, $_COOKIE, $_SERVER, $_ENV, $_FILES, isset($_SESSION) && is_array($_SESSION) ? $_SESSION : array());
	foreach ($input as $k => $v)
	{
		if (!in_array($k, $no_unset) && isset($GLOBALS[$k]))
		{
			unset($GLOBALS[$k]);
			unset($GLOBALS[$k]); // Double unset to circumvent the zend_hash_del_key_or_index hole in PHP <4.4.3 and <5.1.4
		}
	}
}


//
// Removes any "bad" characters (characters which mess with the display of a page, are invisible, etc) from user input
//
function forum_remove_bad_characters()
{
	$_GET = remove_bad_characters($_GET);
	$_POST = remove_bad_characters($_POST);
	$_COOKIE = remove_bad_characters($_COOKIE);
	$_REQUEST = remove_bad_characters($_REQUEST);
}

//
// Removes any "bad" characters (characters which mess with the display of a page, are invisible, etc) from the given string
// See: http://kb.mozillazine.org/Network.IDN.blacklist_chars
//
function remove_bad_characters($array)
{
	static $bad_utf8_chars;

	if (!isset($bad_utf8_chars))
	{
		$bad_utf8_chars = array(
			"\xcc\xb7"		=> '',		// COMBINING SHORT SOLIDUS OVERLAY		0337	*
			"\xcc\xb8"		=> '',		// COMBINING LONG SOLIDUS OVERLAY		0338	*
			"\xe1\x85\x9F"	=> '',		// HANGUL CHOSEONG FILLER				115F	*
			"\xe1\x85\xA0"	=> '',		// HANGUL JUNGSEONG FILLER				1160	*
			"\xe2\x80\x8b"	=> '',		// ZERO WIDTH SPACE						200B	*
			"\xe2\x80\x8c"	=> '',		// ZERO WIDTH NON-JOINER				200C
			"\xe2\x80\x8d"	=> '',		// ZERO WIDTH JOINER					200D
			"\xe2\x80\x8e"	=> '',		// LEFT-TO-RIGHT MARK					200E
			"\xe2\x80\x8f"	=> '',		// RIGHT-TO-LEFT MARK					200F
			"\xe2\x80\xaa"	=> '',		// LEFT-TO-RIGHT EMBEDDING				202A
			"\xe2\x80\xab"	=> '',		// RIGHT-TO-LEFT EMBEDDING				202B
			"\xe2\x80\xac"	=> '', 		// POP DIRECTIONAL FORMATTING			202C
			"\xe2\x80\xad"	=> '',		// LEFT-TO-RIGHT OVERRIDE				202D
			"\xe2\x80\xae"	=> '',		// RIGHT-TO-LEFT OVERRIDE				202E
			"\xe2\x80\xaf"	=> '',		// NARROW NO-BREAK SPACE				202F	*
			"\xe2\x81\x9f"	=> '',		// MEDIUM MATHEMATICAL SPACE			205F	*
			"\xe2\x81\xa0"	=> '',		// WORD JOINER							2060
			"\xe3\x85\xa4"	=> '',		// HANGUL FILLER						3164	*
			"\xef\xbb\xbf"	=> '',		// ZERO WIDTH NO-BREAK SPACE			FEFF
			"\xef\xbe\xa0"	=> '',		// HALFWIDTH HANGUL FILLER				FFA0	*
			"\xef\xbf\xb9"	=> '',		// INTERLINEAR ANNOTATION ANCHOR		FFF9	*
			"\xef\xbf\xba"	=> '',		// INTERLINEAR ANNOTATION SEPARATOR		FFFA	*
			"\xef\xbf\xbb"	=> '',		// INTERLINEAR ANNOTATION TERMINATOR	FFFB	*
			"\xef\xbf\xbc"	=> '',		// OBJECT REPLACEMENT CHARACTER			FFFC	*
			"\xef\xbf\xbd"	=> '',		// REPLACEMENT CHARACTER				FFFD	*
			"\xe2\x80\x80"	=> ' ',		// EN QUAD								2000	*
			"\xe2\x80\x81"	=> ' ',		// EM QUAD								2001	*
			"\xe2\x80\x82"	=> ' ',		// EN SPACE								2002	*
			"\xe2\x80\x83"	=> ' ',		// EM SPACE								2003	*
			"\xe2\x80\x84"	=> ' ',		// THREE-PER-EM SPACE					2004	*
			"\xe2\x80\x85"	=> ' ',		// FOUR-PER-EM SPACE					2005	*
			"\xe2\x80\x86"	=> ' ',		// SIX-PER-EM SPACE						2006	*
			"\xe2\x80\x87"	=> ' ',		// FIGURE SPACE							2007	*
			"\xe2\x80\x88"	=> ' ',		// FORUMCTUATION SPACE					2008	*
			"\xe2\x80\x89"	=> ' ',		// THIN SPACE							2009	*
			"\xe2\x80\x8a"	=> ' ',		// HAIR SPACE							200A	*
			"\xE3\x80\x80"	=> ' ',		// IDEOGRAPHIC SPACE					3000	*
		);
	}

	if (is_array($array))
		return array_map('remove_bad_characters', $array);

	// Strip out any invalid characters
	$array = utf8_bad_strip($array);

	// Remove control characters
	$array = preg_replace('%[\x00-\x08\x0b-\x0c\x0e-\x1f]%', '', $array);

	// Replace some "bad" characters
	$array = str_replace(array_keys($bad_utf8_chars), array_values($bad_utf8_chars), $array);

	return $array;
}


//
// Converts the file size in bytes to a human readable file size
//
function file_size($size)
{
	global $lang;

	$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB');

	for ($i = 0; $size > 1024; $i++)
		$size /= 1024;

	return sprintf($lang['Size unit '.$units[$i]], round($size, 2));
}


//
// Fetch a list of available frontend styles
//
function forum_list_styles()
{
	$styles = array();

	$d = dir(FORUM_ROOT.'style');
	while (($entry = $d->read()) !== false)
	{
		if ($entry{0} == '.')
			continue;

		if (is_dir(FORUM_ROOT.'style/'.$entry) && file_exists(FORUM_ROOT.'style/'.$entry.'/style.css'))
			$styles[] = $entry;
	}
	$d->close();

	natcasesort($styles);

	return $styles;
}


//
// Fetch a list of available language packs
//
function forum_list_langs()
{
	$languages = array();

	$d = dir(FORUM_ROOT.'lang');
	while (($entry = $d->read()) !== false)
	{
		if ($entry{0} == '.')
			continue;

		if (is_dir(FORUM_ROOT.'lang/'.$entry) && file_exists(FORUM_ROOT.'lang/'.$entry.'/language.php'))
			$languages[] = $entry;
	}
	$d->close();

	natcasesort($languages);

	return $languages;
}


//
// Generate a cache ID based on the last modification time for all stopwords files
//
function generate_stopwords_cache_id()
{
	$files = glob(FORUM_ROOT.'lang/*/stopwords.txt');
	if ($files === false)
		return 'cache_id_error';

	$hash = array();

	foreach ($files as $file)
	{
		$hash[] = $file;
		$hash[] = filemtime($file);
	}

	return sha1(implode('|', $hash));
}


//
// Fetch a list of available admin plugins
//
function forum_list_plugins($is_admin)
{
	$plugins = array();

	$d = dir(FORUM_ROOT.'plugins');
	while (($entry = $d->read()) !== false)
	{
		if ($entry{0} == '.')
			continue;

		$prefix = substr($entry, 0, strpos($entry, '_'));
		$suffix = substr($entry, strlen($entry) - 4);

		if ($suffix == '.php' && ((!$is_admin && $prefix == 'AMP') || ($is_admin && ($prefix == 'AP' || $prefix == 'AMP'))))
			$plugins[$entry] = substr($entry, strpos($entry, '_') + 1, -4);
	}
	$d->close();

	natcasesort($plugins);

	return $plugins;
}


//
// Split text into chunks ($inside contains all text inside $start and $end, and $outside contains all text outside)
//
function split_text($text, $start, $end, $retab = true)
{
	global $luna_config, $lang;

	$result = array(0 => array(), 1 => array()); // 0 = inside, 1 = outside

	// split the text into parts
	$parts = preg_split('%'.preg_quote($start, '%').'(.*)'.preg_quote($end, '%').'%Us', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	$num_parts = count($parts);

	// preg_split results in outside parts having even indices, inside parts having odd
	for ($i = 0;$i < $num_parts;$i++)
		$result[1 - ($i % 2)][] = $parts[$i];

	if ($luna_config['o_indent_num_spaces'] != 8 && $retab)
	{
		$spaces = str_repeat(' ', $luna_config['o_indent_num_spaces']);
		$result[1] = str_replace("\t", $spaces, $result[1]);
	}

	return $result;
}


//
// Extract blocks from a text with a starting and ending string
// This function always matches the most outer block so nesting is possible
//
function extract_blocks($text, $start, $end, $retab = true)
{
	global $luna_config;

	$code = array();
	$start_len = strlen($start);
	$end_len = strlen($end);
	$regex = '%(?:'.preg_quote($start, '%').'|'.preg_quote($end, '%').')%';
	$matches = array();

	if (preg_match_all($regex, $text, $matches))
	{
		$counter = $offset = 0;
		$start_pos = $end_pos = false;

		foreach ($matches[0] as $match)
		{
			if ($match == $start)
			{
				if ($counter == 0)
					$start_pos = strpos($text, $start);
				$counter++;
			}
			elseif ($match == $end)
			{
				$counter--;
				if ($counter == 0)
					$end_pos = strpos($text, $end, $offset + 1);
				$offset = strpos($text, $end, $offset + 1);
			}

			if ($start_pos !== false && $end_pos !== false)
			{
				$code[] = substr($text, $start_pos + $start_len,
					$end_pos - $start_pos - $start_len);
				$text = substr_replace($text, "\1", $start_pos,
					$end_pos - $start_pos + $end_len);
				$start_pos = $end_pos = false;
				$offset = 0;
			}
		}
	}

	if ($luna_config['o_indent_num_spaces'] != 8 && $retab)
	{
		$spaces = str_repeat(' ', $luna_config['o_indent_num_spaces']);
		$text = str_replace("\t", $spaces, $text);
	}

	return array($code, $text);
}


//
// function url_valid($url) {
//
// Return associative array of valid URI components, or FALSE if $url is not
// RFC-3986 compliant. If the passed URL begins with: "www." or "ftp.", then
// "http://" or "ftp://" is prepended and the corrected full-url is stored in
// the return array with a key name "url". This value should be used by the caller.
//
// Return value: FALSE if $url is not valid, otherwise array of URI components:
// e.g.
// Given: "http://www.jmrware.com:80/articles?height=10&width=75#fragone"
// Array(
//	  [scheme] => http
//	  [authority] => www.jmrware.com:80
//	  [userinfo] =>
//	  [host] => www.jmrware.com
//	  [IP_literal] =>
//	  [IPV6address] =>
//	  [ls32] =>
//	  [IPvFuture] =>
//	  [IPv4address] =>
//	  [regname] => www.jmrware.com
//	  [port] => 80
//	  [path_abempty] => /articles
//	  [query] => height=10&width=75
//	  [fragment] => fragone
//	  [url] => http://www.jmrware.com:80/articles?height=10&width=75#fragone
// )
function url_valid($url)
{
	if (strpos($url, 'www.') === 0) $url = 'http://'. $url;
	if (strpos($url, 'ftp.') === 0) $url = 'ftp://'. $url;
	if (!preg_match('/# Valid absolute URI having a non-empty, valid DNS host.
		^
		(?P<scheme>[A-Za-z][A-Za-z0-9+\-.]*):\/\/
		(?P<authority>
		  (?:(?P<userinfo>(?:[A-Za-z0-9\-._~!$&\'()*+,;=:]|%[0-9A-Fa-f]{2})*)@)?
		  (?P<host>
			(?P<IP_literal>
			  \[
			  (?:
				(?P<IPV6address>
				  (?:												 (?:[0-9A-Fa-f]{1,4}:){6}
				  |												   ::(?:[0-9A-Fa-f]{1,4}:){5}
				  | (?:							 [0-9A-Fa-f]{1,4})?::(?:[0-9A-Fa-f]{1,4}:){4}
				  | (?:(?:[0-9A-Fa-f]{1,4}:){0,1}[0-9A-Fa-f]{1,4})?::(?:[0-9A-Fa-f]{1,4}:){3}
				  | (?:(?:[0-9A-Fa-f]{1,4}:){0,2}[0-9A-Fa-f]{1,4})?::(?:[0-9A-Fa-f]{1,4}:){2}
				  | (?:(?:[0-9A-Fa-f]{1,4}:){0,3}[0-9A-Fa-f]{1,4})?::	[0-9A-Fa-f]{1,4}:
				  | (?:(?:[0-9A-Fa-f]{1,4}:){0,4}[0-9A-Fa-f]{1,4})?::
				  )
				  (?P<ls32>[0-9A-Fa-f]{1,4}:[0-9A-Fa-f]{1,4}
				  | (?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}
					   (?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)
				  )
				|	(?:(?:[0-9A-Fa-f]{1,4}:){0,5}[0-9A-Fa-f]{1,4})?::	[0-9A-Fa-f]{1,4}
				|	(?:(?:[0-9A-Fa-f]{1,4}:){0,6}[0-9A-Fa-f]{1,4})?::
				)
			  | (?P<IPvFuture>[Vv][0-9A-Fa-f]+\.[A-Za-z0-9\-._~!$&\'()*+,;=:]+)
			  )
			  \]
			)
		  | (?P<IPv4address>(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}
							   (?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?))
		  | (?P<regname>(?:[A-Za-z0-9\-._~!$&\'()*+,;=]|%[0-9A-Fa-f]{2})+)
		  )
		  (?::(?P<port>[0-9]*))?
		)
		(?P<path_abempty>(?:\/(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@]|%[0-9A-Fa-f]{2})*)*)
		(?:\?(?P<query>		  (?:[A-Za-z0-9\-._~!$&\'()*+,;=:@\\/?]|%[0-9A-Fa-f]{2})*))?
		(?:\#(?P<fragment>	  (?:[A-Za-z0-9\-._~!$&\'()*+,;=:@\\/?]|%[0-9A-Fa-f]{2})*))?
		$
		/mx', $url, $m)) return FALSE;
	switch ($m['scheme'])
	{
	case 'https':
	case 'http':
		if ($m['userinfo']) return FALSE; // HTTP scheme does not allow userinfo.
		break;
	case 'ftps':
	case 'ftp':
		break;
	default:
		return FALSE;	// Unrecognised URI scheme. Default to FALSE.
	}
	// Validate host name conforms to DNS "dot-separated-parts".
	if ($m{'regname'}) // If host regname specified, check for DNS conformance.
	{
		if (!preg_match('/# HTTP DNS host name.
			^					   # Anchor to beginning of string.
			(?!.{256})			   # Overall host length is less than 256 chars.
			(?:					   # Group dot separated host part alternatives.
			  [0-9A-Za-z]\.		   # Either a single alphanum followed by dot
			|					   # or... part has more than one char (63 chars max).
			  [0-9A-Za-z]		   # Part first char is alphanum (no dash).
			  [\-0-9A-Za-z]{0,61}  # Internal chars are alphanum plus dash.
			  [0-9A-Za-z]		   # Part last char is alphanum (no dash).
			  \.				   # Each part followed by literal dot.
			)*					   # One or more parts before top level domain.
			(?:					   # Top level domains
			  [A-Za-z]{2,63}|	   # Country codes are exactly two alpha chars.
			  xn--[0-9A-Za-z]{4,59}) # Internationalized Domain Name (IDN)
			$					   # Anchor to end of string.
			/ix', $m['host'])) return FALSE;
	}
	$m['url'] = $url;
	for ($i = 0; isset($m[$i]); ++$i) unset($m[$i]);
	return $m; // return TRUE == array of useful named $matches plus the valid $url.
}

//
// Replace string matching regular expression
//
// This function takes care of possibly disabled unicode properties in PCRE builds
//
function ucp_preg_replace($pattern, $replace, $subject, $callback = false)
{
	if($callback)
		$replaced = preg_replace_callback($pattern, create_function('$matches', 'return '.$replace.';'), $subject);
	else
		$replaced = preg_replace($pattern, $replace, $subject);

	// If preg_replace() returns false, this probably means unicode support is not built-in, so we need to modify the pattern a little
	if ($replaced === false)
	{
		if (is_array($pattern))
		{
			foreach ($pattern as $cur_key => $cur_pattern)
				$pattern[$cur_key] = str_replace('\p{L}\p{N}', '\w', $cur_pattern);

			$replaced = preg_replace($pattern, $replace, $subject);
		}
		else
			$replaced = preg_replace(str_replace('\p{L}\p{N}', '\w', $pattern), $replace, $subject);
	}

	return $replaced;
}

//
// A wrapper for ucp_preg_replace
//
function ucp_preg_replace_callback($pattern, $replace, $subject)
{
	return ucp_preg_replace($pattern, $replace, $subject, true);
}

//
// Replace four-byte characters with a question mark
//
// As MySQL cannot properly handle four-byte characters with the default utf-8
// charset up until version 5.5.3 (where a special charset has to be used), they
// need to be replaced, by question marks in this case.
//
function strip_bad_multibyte_chars($str)
{
	$result = '';
	$length = strlen($str);

	for ($i = 0; $i < $length; $i++)
	{
		// Replace four-byte characters (11110www 10zzzzzz 10yyyyyy 10xxxxxx)
		$ord = ord($str[$i]);
		if ($ord >= 240 && $ord <= 244)
		{
			$result .= '?';
			$i += 3;
		}
		else
		{
			$result .= $str[$i];
		}
	}

	return $result;
}

//
// Check whether a file/folder is writable.
//
// This function also works on Windows Server where ACLs seem to be ignored.
//
function forum_is_writable($path)
{
	if (is_dir($path))
	{
		$path = rtrim($path, '/').'/';
		return forum_is_writable($path.uniqid(mt_rand()).'.tmp');
	}

	// Check temporary file for read/write capabilities
	$rm = file_exists($path);
	$f = @fopen($path, 'a');

	if ($f === false)
		return false;

	fclose($f);

	if (!$rm)
		@unlink($path);

	return true;
}


// DEBUG FUNCTIONS BELOW

//
// Display executed queries (if enabled)
//
function display_saved_queries()
{
	global $db, $lang;

	// Get the queries so that we can print them out
	$saved_queries = $db->get_saved_queries();

?>
<div class="debug panel panel-warning">
	<div class="panel-heading">
		<h3 class="panel-title"><?php echo $lang['Debug table'] ?></h3>
	</div>
	<table class="table table-bordered table-striped table-hover">
		<thead>
			<tr>
				<th class="col-xs-1"><?php echo $lang['Query times'] ?></th>
				<th class="col-xs-11"><?php echo $lang['Query'] ?></th>
			</tr>
		</thead>
		<tbody>
<?php

	$query_time_total = 0.0;
	foreach ($saved_queries as $cur_query)
	{
		$query_time_total += $cur_query[1];

?>
			<tr>
				<td><?php echo ($cur_query[1] != 0) ? $cur_query[1] : '&#160;' ?></td>
				<td><?php echo luna_htmlspecialchars($cur_query[0]) ?></td>
			</tr>
<?php

	}

?>
			<tr>
				<td colspan="2"><?php printf($lang['Total query time'], $query_time_total.' s') ?></td>
			</tr>
		</tbody>
	</table>
</div>
<?php

}


//
// Dump contents of variable(s)
//
function dump()
{
	echo '<pre>';

	$num_args = func_num_args();

	for ($i = 0; $i < $num_args; ++$i)
	{
		print_r(func_get_arg($i));
		echo "\n\n";
	}

	echo '</pre>';
	exit;
}

//
// Get the template that is required
//
function get_template_path($tpl_file)
{
	global $luna_user;

	if (file_exists(FORUM_ROOT.'style/'.$luna_user['style'].'/templates/'.$tpl_file))
	{
		return FORUM_ROOT.'style/'.$luna_user['style'].'/templates/'.$tpl_file;
	}
	else
	{
		return FORUM_ROOT.'style/Core/templates/'.$tpl_file;
	}
}

//
// Get the view that is required
//
function get_view_path($tpl_file)
{
	global $luna_user;

	if (file_exists(FORUM_ROOT.'style/'.$luna_user['style'].'/templates/views/'.$tpl_file))
	{
		return FORUM_ROOT.'style/'.$luna_user['style'].'/templates/views/'.$tpl_file;
	}
	else
	{
		return FORUM_ROOT.'style/Core/templates/views/'.$tpl_file;
	}
}

//
// Delete all content in a folder
//
function delete_all($path)
{
    $dir = dir($path);

    while ($file = $dir->read())
    {
        if ($file == '.' || $file == '..') continue;

        $file = $path.'/'.$file;

		if ($file != $path.'/.htaccess') { // Never remove a .htaccess
			if (is_dir($file))
			{
				delete_all($file);
				rmdir($file);
			}
			else
			{
				unlink($file);
			}
		}
    }
}


//
// Validate the given redirect URL, use the fallback otherwise
//
function validate_redirect($redirect_url, $fallback_url)
{
	$referrer = parse_url(strtolower($redirect_url));
	
	// Make sure the host component exists 
	if (!isset($referrer['host'])) 
		$referrer['host'] = ''; 

	// Remove www subdomain if it exists
	if (strpos($referrer['host'], 'www.') === 0)
		$referrer['host'] = substr($referrer['host'], 4);

	// Make sure the path component exists
	if (!isset($referrer['path']))
		$referrer['path'] = '';

	$valid = parse_url(strtolower(get_base_url()));

	// Remove www subdomain if it exists
	if (strpos($valid['host'], 'www.') === 0)
		$valid['host'] = substr($valid['host'], 4);

	// Make sure the path component exists
	if (!isset($valid['path']))
		$valid['path'] = '';

	if ($referrer['host'] == $valid['host'] && preg_match('%^'.preg_quote($valid['path'], '%').'/(.*?)\.php%i', $referrer['path']))
		return $redirect_url;
	else
		return $fallback_url;
}

//
// Fetch online users
//

//  Number of users online
function num_users_online()
{
	global $db;

    $result_num_users = $db->query('SELECT user_id FROM '.$db->prefix.'online WHERE idle=0 AND user_id>1', false) or error('Unable to fetch online users list', __FILE__, __LINE__, $db->error());

    return $db->num_rows($result_num_users);
}

// Number of guests online
function num_guests_online()
{
	global $db;

    $result_num_guests = $db->query('SELECT user_id FROM '.$db->prefix.'online WHERE idle=0 AND user_id=1', true) or error('Unable to fetch online guests list', __FILE__, __LINE__, $db->error());

    return $db->num_rows($result_num_guests);
}

// Get forum_id by post_id
function get_forum_id($post_id)
{
	global $db;

    $result_fid = $db->query('SELECT t.forum_id FROM '.$db->prefix.'posts as p INNER JOIN '.$db->prefix.'topics as t ON p.topic_id = t.id WHERE p.id='.intval($post_id), true) or error('Unable to fetch forum id', __FILE__, __LINE__, $db->error());

    $row = $db->fetch_row($result_fid);

    if($row)
        return $row[0];
    else
        return false;
}

// Update forum's last_topic
function set_forum_topic($fid, $fmessage)
{
	global $db;

    $result = $db->query('UPDATE '.$db->prefix.'forums SET last_topic=\''.$db->escape($fmessage).'\' WHERE id='.intval($fid), true) or error('Unable to set forum last topic', __FILE__, __LINE__, $db->error());

    if($db->affected_rows($result))
        return true;
    else
        return false;
}