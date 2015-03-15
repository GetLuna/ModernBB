<?php

/**
 * Copyright (C) 2013-2014 ModernBB Group
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * Licensed under GPLv3 (http://modernbb.be/license.php)
 */

define('FORUM_ROOT', dirname(__FILE__).'/');
require FORUM_ROOT.'include/common.php';


if ($luna_user['g_read_board'] == '0')
	message($lang['No view'], false, '403 Forbidden');
else if ($luna_user['g_view_users'] == '0')
	message($lang['No permission'], false, '403 Forbidden');

// Determine if we are allowed to view post counts
$show_post_count = ($luna_config['o_show_post_count'] == '1' || $luna_user['is_admmod']) ? true : false;

$username = isset($_GET['username']) && $luna_user['g_search_users'] == '1' ? luna_trim($_GET['username']) : '';
$show_group = isset($_GET['show_group']) ? intval($_GET['show_group']) : -1;
$sort_by = isset($_GET['sort_by']) && (in_array($_GET['sort_by'], array('username', 'registered')) || ($_GET['sort_by'] == 'num_posts' && $show_post_count)) ? $_GET['sort_by'] : 'username';
$sort_dir = isset($_GET['sort_dir']) && $_GET['sort_dir'] == 'DESC' ? 'DESC' : 'ASC';

// Create any SQL for the WHERE clause
$where_sql = array();
$like_command = ($db_type == 'pgsql') ? 'ILIKE' : 'LIKE';

if ($username != '')
	$where_sql[] = 'u.username '.$like_command.' \''.$db->escape(str_replace('*', '%', $username)).'\'';
if ($show_group > -1)
	$where_sql[] = 'u.group_id='.$show_group;

// Fetch user count
$result = $db->query('SELECT COUNT(id) FROM '.$db->prefix.'users AS u WHERE u.id>1 AND u.group_id!='.FORUM_UNVERIFIED.(!empty($where_sql) ? ' AND '.implode(' AND ', $where_sql) : '')) or error('Unable to fetch user list count', __FILE__, __LINE__, $db->error());
$num_users = $db->result($result);

// Determine the user offset (based on $_GET['p'])
$num_pages = ceil($num_users / 50);

$p = (!isset($_GET['p']) || $_GET['p'] <= 1 || $_GET['p'] > $num_pages) ? 1 : intval($_GET['p']);
$start_from = 50 * ($p - 1);

$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['User list']);
if ($luna_user['g_search_users'] == '1')
	$focus_element = array('userlist', 'username');

// Generate paging links
$paging_links = paginate($num_pages, $p, 'userlist.php?username='.urlencode($username).'&amp;show_group='.$show_group.'&amp;sort_by='.$sort_by.'&amp;sort_dir='.$sort_dir);


define('FORUM_ALLOW_INDEX', 1);
define('FORUM_ACTIVE_PAGE', 'userlist');
require FORUM_ROOT.'header.php';

require get_view_path('userlist-form.tpl.php');

require get_view_path('userlist-users.tpl.php');