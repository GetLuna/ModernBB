<?php

/**
 * Copyright (C) 2013-2014 ModernBB Group
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * Licensed under GPLv3 (http://modernbb.be/license.php)
 */

// The contents of this file are very much inspired by the file search.php
// from the phpBB Group forum software phpBB2 (http://www.phpbb.com)

define('FORUM_ROOT', dirname(__FILE__).'/');
require FORUM_ROOT.'include/common.php';

$section = isset($_GET['section']) ? $_GET['section'] : null;

if ($luna_user['g_read_board'] == '0')
	message($lang['No view'], false, '403 Forbidden');
else if ($luna_user['g_search'] == '0')
	message($lang['No search permission'], false, '403 Forbidden');

require FORUM_ROOT.'include/search_idx.php';

// Figure out what to do :-)
if (isset($_GET['action']) || isset($_GET['search_id']))
{
	$action = (isset($_GET['action'])) ? $_GET['action'] : null;
	$forums = isset($_GET['forums']) ? (is_array($_GET['forums']) ? $_GET['forums'] : array_filter(explode(',', $_GET['forums']))) : (isset($_GET['forum']) ? array($_GET['forum']) : array());
	$sort_dir = (isset($_GET['sort_dir']) && $_GET['sort_dir'] == 'DESC') ? 'DESC' : 'ASC';

	$forums = array_map('intval', $forums);

	// Allow the old action names for backwards compatibility reasons
	if ($action == 'show_user')
		$action = 'show_user_posts';
	else if ($action == 'show_24h')
		$action = 'show_recent';

	// If a search_id was supplied
	if (isset($_GET['search_id']))
	{
		$search_id = intval($_GET['search_id']);
		if ($search_id < 1)
			message($lang['Bad request'], false, '404 Not Found');
	}
	// If it's a regular search (keywords and/or author)
	else if ($action == 'search')
	{
		$keywords = (isset($_GET['keywords'])) ? utf8_strtolower(luna_trim($_GET['keywords'])) : null;
		$author = (isset($_GET['author'])) ? utf8_strtolower(luna_trim($_GET['author'])) : null;

		if (preg_match('%^[\*\%]+$%', $keywords) || (luna_strlen(str_replace(array('*', '%'), '', $keywords)) < FORUM_SEARCH_MIN_WORD && !is_cjk($keywords)))
			$keywords = '';

		if (preg_match('%^[\*\%]+$%', $author) || luna_strlen(str_replace(array('*', '%'), '', $author)) < 2)
			$author = '';

		if (!$keywords && !$author)
			message($lang['No terms']);

		if ($author)
			$author = str_replace('*', '%', $author);

		$show_as = (isset($_GET['show_as']) && $_GET['show_as'] == 'topics') ? 'topics' : 'posts';
		$sort_by = (isset($_GET['sort_by'])) ? intval($_GET['sort_by']) : 0;
		$search_in = (!isset($_GET['search_in']) || $_GET['search_in'] == '0') ? 0 : (($_GET['search_in'] == '1') ? 1 : -1);
	}
	// If it's a user search (by ID)
	else if ($action == 'show_user_posts' || $action == 'show_user_topics' || $action == 'show_subscriptions')
	{
		$user_id = (isset($_GET['user_id'])) ? intval($_GET['user_id']) : $luna_user['id'];
		if ($user_id < 2)
			message($lang['Bad request'], false, '404 Not Found');

		// Subscribed topics can only be viewed by admins, moderators and the users themselves
		if ($action == 'show_subscriptions' && !$luna_user['is_admmod'] && $user_id != $luna_user['id'])
			message($lang['No permission'], false, '403 Forbidden');
	}
	else if ($action == 'show_recent')
		$interval = isset($_GET['value']) ? intval($_GET['value']) : 86400;
	else if ($action != 'show_new' && $action != 'show_unanswered')
		message($lang['Bad request'], false, '404 Not Found');


	// If a valid search_id was supplied we attempt to fetch the search results from the db
	if (isset($search_id))
	{
		$ident = ($luna_user['is_guest']) ? get_remote_address() : $luna_user['username'];

		$result = $db->query('SELECT search_data FROM '.$db->prefix.'search_cache WHERE id='.$search_id.' AND ident=\''.$db->escape($ident).'\'') or error('Unable to fetch search results', __FILE__, __LINE__, $db->error());
		if ($row = $db->fetch_assoc($result))
		{
			$temp = unserialize($row['search_data']);

			$search_ids = unserialize($temp['search_ids']);
			$num_hits = $temp['num_hits'];
			$sort_by = $temp['sort_by'];
			$sort_dir = $temp['sort_dir'];
			$show_as = $temp['show_as'];
			$search_type = $temp['search_type'];

			unset($temp);
		}
		else
			message($lang['No hits']);
	}
	else
	{
		$keyword_results = $author_results = array();

		// Search a specific forum?
		$forum_sql = (!empty($forums) || (empty($forums) && $luna_config['o_search_all_forums'] == '0' && !$luna_user['is_admmod'])) ? ' AND t.forum_id IN ('.implode(',', $forums).')' : '';

		if (!empty($author) || !empty($keywords))
		{
			// Flood protection
			if ($luna_user['last_search'] && (time() - $luna_user['last_search']) < $luna_user['g_search_flood'] && (time() - $luna_user['last_search']) >= 0)
				message(sprintf($lang['Search flood'], $luna_user['g_search_flood'], $luna_user['g_search_flood'] - (time() - $luna_user['last_search'])));

			if (!$luna_user['is_guest'])
				$db->query('UPDATE '.$db->prefix.'users SET last_search='.time().' WHERE id='.$luna_user['id']) or error('Unable to update user', __FILE__, __LINE__, $db->error());
			else
				$db->query('UPDATE '.$db->prefix.'online SET last_search='.time().' WHERE ident=\''.$db->escape(get_remote_address()).'\'' ) or error('Unable to update user', __FILE__, __LINE__, $db->error());

			switch ($sort_by)
			{
				case 1:
					$sort_by_sql = ($show_as == 'topics') ? 't.poster' : 'p.poster';
					$sort_type = SORT_STRING;
					break;

				case 2:
					$sort_by_sql = 't.subject';
					$sort_type = SORT_STRING;
					break;

				case 3:
					$sort_by_sql = 't.forum_id';
					$sort_type = SORT_NUMERIC;
					break;

				case 4:
					$sort_by_sql = 't.last_post';
					$sort_type = SORT_NUMERIC;
					break;

				default:
					$sort_by_sql = ($show_as == 'topics') ? 't.last_post' : 'p.posted';
					$sort_type = SORT_NUMERIC;
					break;
			}

			// If it's a search for keywords
			if ($keywords)
			{
				// split the keywords into words
				$keywords_array = split_words($keywords, false);

				if (empty($keywords_array))
					message($lang['No hits']);

				// Should we search in message body or topic subject specifically?
				$search_in_cond = ($search_in) ? (($search_in > 0) ? ' AND m.subject_match = 0' : ' AND m.subject_match = 1') : '';

				$word_count = 0;
				$match_type = 'and';

				$sort_data = array();
				foreach ($keywords_array as $cur_word)
				{
					switch ($cur_word)
					{
						case 'and':
						case 'or':
						case 'not':
							$match_type = $cur_word;
							break;

						default:
						{
							if (is_cjk($cur_word))
							{
								$where_cond = str_replace('*', '%', $cur_word);
								$where_cond = ($search_in ? (($search_in > 0) ? 'p.message LIKE \'%'.$db->escape($where_cond).'%\'' : 't.subject LIKE \'%'.$db->escape($where_cond).'%\'') : 'p.message LIKE \'%'.$db->escape($where_cond).'%\' OR t.subject LIKE \'%'.$db->escape($where_cond).'%\'');

								$result = $db->query('SELECT p.id AS post_id, p.topic_id, '.$sort_by_sql.' AS sort_by FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE ('.$where_cond.') AND (fp.read_forum IS NULL OR fp.read_forum=1)'.$forum_sql, true) or error('Unable to search for posts', __FILE__, __LINE__, $db->error());
							}
							else
								$result = $db->query('SELECT m.post_id, p.topic_id, '.$sort_by_sql.' AS sort_by FROM '.$db->prefix.'search_words AS w INNER JOIN '.$db->prefix.'search_matches AS m ON m.word_id = w.id INNER JOIN '.$db->prefix.'posts AS p ON p.id=m.post_id INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE w.word LIKE \''.$db->escape(str_replace('*', '%', $cur_word)).'\''.$search_in_cond.' AND (fp.read_forum IS NULL OR fp.read_forum=1)'.$forum_sql, true) or error('Unable to search for posts', __FILE__, __LINE__, $db->error());

							$row = array();
							while ($temp = $db->fetch_assoc($result))
							{
								$row[$temp['post_id']] = $temp['topic_id'];

								if (!$word_count)
								{
									$keyword_results[$temp['post_id']] = $temp['topic_id'];
									$sort_data[$temp['post_id']] = $temp['sort_by'];
								}
								else if ($match_type == 'or')
								{
									$keyword_results[$temp['post_id']] = $temp['topic_id'];
									$sort_data[$temp['post_id']] = $temp['sort_by'];
								}
								else if ($match_type == 'not')
								{
									unset($keyword_results[$temp['post_id']]);
									unset($sort_data[$temp['post_id']]);
								}
							}

							if ($match_type == 'and' && $word_count)
							{
								foreach ($keyword_results as $post_id => $topic_id)
								{
									if (!isset($row[$post_id]))
									{
										unset($keyword_results[$post_id]);
										unset($sort_data[$post_id]);
									}
								}
							}

							++$word_count;
							$db->free_result($result);

							break;
						}
					}
				}

				// Sort the results - annoyingly array_multisort re-indexes arrays with numeric keys, so we need to split the keys out into a separate array then combine them again after
				$post_ids = array_keys($keyword_results);
				$topic_ids = array_values($keyword_results);

				array_multisort(array_values($sort_data), $sort_dir == 'DESC' ? SORT_DESC : SORT_ASC, $sort_type, $post_ids, $topic_ids);

				// combine the arrays back into a key=>value array (array_combine is PHP5 only unfortunately)
				$num_results = count($keyword_results);
				$keyword_results = array();
				for ($i = 0;$i < $num_results;$i++)
					$keyword_results[$post_ids[$i]] = $topic_ids[$i];

				unset($sort_data, $post_ids, $topic_ids);
			}

			// If it's a search for author name (and that author name isn't Guest)
			if ($author && $author != 'guest' && $author != utf8_strtolower($lang['Guest']))
			{
				switch ($db_type)
				{
					case 'pgsql':
						$result = $db->query('SELECT id FROM '.$db->prefix.'users WHERE username ILIKE \''.$db->escape($author).'\'') or error('Unable to fetch users', __FILE__, __LINE__, $db->error());
						break;

					default:
						$result = $db->query('SELECT id FROM '.$db->prefix.'users WHERE username LIKE \''.$db->escape($author).'\'') or error('Unable to fetch users', __FILE__, __LINE__, $db->error());
						break;
				}

				if ($db->num_rows($result))
				{
					$user_ids = array();
					while ($row = $db->fetch_row($result))
						$user_ids[] = $row[0];

					$result = $db->query('SELECT p.id AS post_id, p.topic_id FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.poster_id IN('.implode(',', $user_ids).')'.$forum_sql.' ORDER BY '.$sort_by_sql.' '.$sort_dir) or error('Unable to fetch matched posts list', __FILE__, __LINE__, $db->error());
					while ($temp = $db->fetch_assoc($result))
						$author_results[$temp['post_id']] = $temp['topic_id'];

					$db->free_result($result);
				}
			}

			// If we searched for both keywords and author name we want the intersection between the results
			if ($author && $keywords)
			{
				$search_ids = array_intersect_assoc($keyword_results, $author_results);
				$search_type = array('both', array($keywords, luna_trim($_GET['author'])), implode(',', $forums), $search_in);
			}
			else if ($keywords)
			{
				$search_ids = $keyword_results;
				$search_type = array('keywords', $keywords, implode(',', $forums), $search_in);
			}
			else
			{
				$search_ids = $author_results;
				$search_type = array('author', luna_trim($_GET['author']), implode(',', $forums), $search_in);
			}

			unset($keyword_results, $author_results);

			if ($show_as == 'topics')
				$search_ids = array_values($search_ids);
			else
				$search_ids = array_keys($search_ids);

			$search_ids = array_unique($search_ids);

			$num_hits = count($search_ids);
			if (!$num_hits)
				message($lang['No hits']);
		}
		else if ($action == 'show_new' || $action == 'show_recent' || $action == 'show_user_posts' || $action == 'show_user_topics' || $action == 'show_subscriptions' || $action == 'show_unanswered')
		{
			$search_type = array('action', $action);
			$show_as = 'topics';
			// We want to sort things after last post
			$sort_by = 0;
			$sort_dir = 'DESC';

			// If it's a search for new posts since last visit
			if ($action == 'show_new')
			{
				if ($luna_user['is_guest'])
					message($lang['No permission'], false, '403 Forbidden');

				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND t.last_post>'.$luna_user['last_visit'].' AND t.moved_to IS NULL'.(isset($_GET['fid']) ? ' AND t.forum_id='.intval($_GET['fid']) : '').' ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No new posts']);
			}
			// If it's a search for recent posts (in a certain time interval)
			else if ($action == 'show_recent')
			{
				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND t.last_post>'.(time() - $interval).' AND t.moved_to IS NULL'.(isset($_GET['fid']) ? ' AND t.forum_id='.intval($_GET['fid']) : '').' ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No recent posts']);
			}
			// If it's a search for posts by a specific user ID
			else if ($action == 'show_user_posts')
			{
				$show_as = 'posts';

				$result = $db->query('SELECT p.id FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON p.topic_id=t.id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.poster_id='.$user_id.' ORDER BY p.posted DESC') or error('Unable to fetch user posts', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No user posts']);

				// Pass on the user ID so that we can later know whose posts we're searching for
				$search_type[2] = $user_id;
			}
			// If it's a search for topics by a specific user ID
			else if ($action == 'show_user_topics')
			{
				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'posts AS p ON t.first_post_id=p.id LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND p.poster_id='.$user_id.' ORDER BY t.last_post DESC') or error('Unable to fetch user topics', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No user topics']);

				// Pass on the user ID so that we can later know whose topics we're searching for
				$search_type[2] = $user_id;
			}
			// If it's a search for subscribed topics
			else if ($action == 'show_subscriptions')
			{
				if ($luna_user['is_guest'])
					message($lang['Bad request'], false, '404 Not Found');

				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'topic_subscriptions AS s ON (t.id=s.topic_id AND s.user_id='.$user_id.') LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No subscriptions']);

				// Pass on user ID so that we can later know whose subscriptions we're searching for
				$search_type[2] = $user_id;
			}
			// If it's a search for unanswered posts
			else
			{
				$result = $db->query('SELECT t.id FROM '.$db->prefix.'topics AS t LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (fp.forum_id=t.forum_id AND fp.group_id='.$luna_user['g_id'].') WHERE (fp.read_forum IS NULL OR fp.read_forum=1) AND t.num_replies=0 AND t.moved_to IS NULL ORDER BY t.last_post DESC') or error('Unable to fetch topic list', __FILE__, __LINE__, $db->error());
				$num_hits = $db->num_rows($result);

				if (!$num_hits)
					message($lang['No unanswered']);
			}

			$search_ids = array();
			while ($row = $db->fetch_row($result))
				$search_ids[] = $row[0];

			$db->free_result($result);
		}
		else
			message($lang['Bad request'], false, '404 Not Found');


		// Prune "old" search results
		$old_searches = array();
		$result = $db->query('SELECT ident FROM '.$db->prefix.'online') or error('Unable to fetch online list', __FILE__, __LINE__, $db->error());

		if ($db->num_rows($result))
		{
			while ($row = $db->fetch_row($result))
				$old_searches[] = '\''.$db->escape($row[0]).'\'';

			$db->query('DELETE FROM '.$db->prefix.'search_cache WHERE ident NOT IN('.implode(',', $old_searches).')') or error('Unable to delete search results', __FILE__, __LINE__, $db->error());
		}

		// Fill an array with our results and search properties
		$temp = serialize(array(
			'search_ids'		=> serialize($search_ids),
			'num_hits'			=> $num_hits,
			'sort_by'			=> $sort_by,
			'sort_dir'			=> $sort_dir,
			'show_as'			=> $show_as,
			'search_type'		=> $search_type
		));
		$search_id = mt_rand(1, 2147483647);

		$ident = ($luna_user['is_guest']) ? get_remote_address() : $luna_user['username'];

		$db->query('INSERT INTO '.$db->prefix.'search_cache (id, ident, search_data) VALUES('.$search_id.', \''.$db->escape($ident).'\', \''.$db->escape($temp).'\')') or error('Unable to insert search results', __FILE__, __LINE__, $db->error());

		if ($search_type[0] != 'action')
		{
			$db->end_transaction();
			$db->close();

			// Redirect the user to the cached result page
			header('Location: search.php?search_id='.$search_id);
			exit;
		}
	}

	$forum_actions = array();

	// If we're on the new posts search, display a "mark all as read" link
	if (!$luna_user['is_guest'] && $search_type[0] == 'action' && $search_type[1] == 'show_new')
		$forum_actions[] = '<a href="misc.php?action=markread">'.$lang['Mark as read'].'</a>';

	// Fetch results to display
	if (!empty($search_ids))
	{
		switch ($sort_by)
		{
			case 1:
				$sort_by_sql = ($show_as == 'topics') ? 't.poster' : 'p.poster';
				break;

			case 2:
				$sort_by_sql = 't.subject';
				break;

			case 3:
				$sort_by_sql = 't.forum_id';
				break;

			default:
				$sort_by_sql = ($show_as == 'topics') ? 't.last_post' : 'p.posted';
				break;
		}

		// Determine the topic or post offset (based on $_GET['p'])
		$per_page = ($show_as == 'posts') ? $luna_user['disp_posts'] : $luna_user['disp_topics'];
		$num_pages = ceil($num_hits / $per_page);

		$p = (!isset($_GET['p']) || $_GET['p'] <= 1 || $_GET['p'] > $num_pages) ? 1 : intval($_GET['p']);
		$start_from = $per_page * ($p - 1);

		// Generate paging links
		$paging_links = paginate($num_pages, $p, 'search.php?search_id='.$search_id);

		// throw away the first $start_from of $search_ids, only keep the top $per_page of $search_ids
		$search_ids = array_slice($search_ids, $start_from, $per_page);

		// Run the query and fetch the results
		if ($show_as == 'posts')
			$result = $db->query('SELECT p.id AS pid, p.poster AS pposter, p.posted AS pposted, p.poster_id, p.message, p.hide_smilies, t.id AS tid, t.poster, t.subject, t.first_post_id, t.last_post, t.last_post_id, t.last_poster, t.last_poster_id, t.num_replies, t.forum_id, f.forum_name FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id WHERE p.id IN('.implode(',', $search_ids).') ORDER BY '.$sort_by_sql.' '.$sort_dir) or error('Unable to fetch search results', __FILE__, __LINE__, $db->error());
		else
			$result = $db->query('SELECT t.id AS tid, t.poster, t.subject, t.last_post, t.last_post_id, t.last_poster, t.last_poster_id, t.num_replies, t.closed, t.sticky, t.forum_id, f.forum_name FROM '.$db->prefix.'topics AS t INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id WHERE t.id IN('.implode(',', $search_ids).') ORDER BY '.$sort_by_sql.' '.$sort_dir) or error('Unable to fetch search results', __FILE__, __LINE__, $db->error());

		$search_set = array();
		while ($row = $db->fetch_assoc($result))
			$search_set[] = $row;

		$crumbs_text = array();
		$crumbs_text['show_as'] = $lang['Search'];

		if ($search_type[0] == 'action')
		{
			if ($search_type[1] == 'show_user_topics')
				$crumbs_text['search_type'] = '<a class="btn btn-primary" href="search.php?action=show_user_topics&amp;user_id='.$search_type[2].'">'.sprintf($lang['Quick search show_user_topics'], luna_htmlspecialchars($search_set[0]['poster'])).'</a>';
			else if ($search_type[1] == 'show_user_posts')
				$crumbs_text['search_type'] = '<a class="btn btn-primary" href="search.php?action=show_user_posts&amp;user_id='.$search_type[2].'">'.sprintf($lang['Quick search show_user_posts'], luna_htmlspecialchars($search_set[0]['pposter'])).'</a>';
			else if ($search_type[1] == 'show_subscriptions')
			{
				// Fetch username of subscriber
				$subscriber_id = $search_type[2];
				$result = $db->query('SELECT username FROM '.$db->prefix.'users WHERE id='.$subscriber_id) or error('Unable to fetch username of subscriber', __FILE__, __LINE__, $db->error());

				if ($db->num_rows($result))
					$subscriber_name = $db->result($result);
				else
					message($lang['Bad request'], false, '404 Not Found');

				$crumbs_text['search_type'] = '<a class="btn btn-primary" href="search.php?action=show_subscriptions&amp;user_id='.$subscriber_id.'">'.sprintf($lang['Quick search show_subscriptions'], luna_htmlspecialchars($subscriber_name)).'</a>';
			}
			else
				$crumbs_text['search_type'] = '<a class="btn btn-primary" href="search.php?action='.$search_type[1].'">'.$lang['Quick search '.$search_type[1]].'</a>';
		}
		else
		{
			$keywords = $author = '';

			if ($search_type[0] == 'both')
			{
				list ($keywords, $author) = $search_type[1];
				$crumbs_text['search_type'] = sprintf($lang['By both show as '.$show_as], luna_htmlspecialchars($keywords), luna_htmlspecialchars($author));
			}
			else if ($search_type[0] == 'keywords')
			{
				$keywords = $search_type[1];
				$crumbs_text['search_type'] = sprintf($lang['By keywords show as '.$show_as], luna_htmlspecialchars($keywords));
			}
			else if ($search_type[0] == 'author')
			{
				$author = $search_type[1];
				$crumbs_text['search_type'] = sprintf($lang['By user show as '.$show_as], luna_htmlspecialchars($author));
			}

			$crumbs_text['search_type'] = '<a class="btn btn-primary" href="search.php?action=search&amp;keywords='.urlencode($keywords).'&amp;author='.urlencode($author).'&amp;forums='.$search_type[2].'&amp;search_in='.$search_type[3].'&amp;sort_by='.$sort_by.'&amp;sort_dir='.$sort_dir.'&amp;show_as='.$show_as.'">'.$crumbs_text['search_type'].'</a>';
		}

		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Search results']);
		define('FORUM_ACTIVE_PAGE', 'search');
		require FORUM_ROOT.'header.php';

		require get_view_path('search-breadcrumbs.tpl.php');

		if ($show_as == 'topics') {
			$topic_count = 0;
			require get_view_path('search-topics_header.tpl.php');
		} else if ($show_as == 'posts') {
			require FORUM_ROOT.'include/parser.php';

			$post_count = 0;
		}

		// Get topic/forum tracking data
		if (!$luna_user['is_guest'])
			$tracked_topics = get_tracked_topics();

		foreach ($search_set as $cur_search)
		{
			$forum = '<a href="viewforum.php?id='.$cur_search['forum_id'].'">'.luna_htmlspecialchars($cur_search['forum_name']).'</a>';

			if ($luna_config['o_censoring'] == '1')
				$cur_search['subject'] = censor_words($cur_search['subject']);

			if ($show_as == 'posts')
			{
				require get_view_path('search-show_as_posts.tpl.php');
			}
			else
			{
				require get_view_path('search-show_as_topics.tpl.php');
			}
		}

		if ($show_as == 'topics')
			echo "\t\t\t".'</div>'."\n\n";

		require get_view_path('search-breadcrumbs.tpl.php');

		require FORUM_ROOT.'footer.php';
	}
	else
		message($lang['No hits']);
}


if (!$section || $section == 'simple') {
	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Search']);
	$focus_element = array('search', 'keywords');
	define('FORUM_ACTIVE_PAGE', 'search');
	require FORUM_ROOT.'header.php';

	require get_view_path('search-form.tpl.php');
} else {
	if ($luna_config['o_enable_advanced_search'] == 0) {
		message($lang['No permission'], false, '403 Forbidden');
	} else {
		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Search']);
		$focus_element = array('search', 'keywords');
		define('FORUM_ACTIVE_PAGE', 'search');
		require FORUM_ROOT.'header.php';

		require get_view_path('search-form_advanced.tpl.php');
	}
}
